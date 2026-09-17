<?php

namespace App\Modules\Webinars\Actions;

use App\Modules\Core\Models\Contact;
use App\Modules\Messaging\Actions\ScheduleMessageAction;
use App\Modules\Messaging\Models\MessageTemplateVersion;
use App\Modules\Messaging\Models\ScheduledMessage;
use App\Modules\Messaging\Payloads\EmailPayload;
use App\Modules\Messaging\Payloads\SmsPayload;
use App\Modules\Messaging\Services\MessageChannelAvailability;
use App\Modules\Messaging\Services\MessageGate;
use App\Modules\Webinars\Jobs\ProcessWebinarScheduleChangeJob;
use App\Modules\Webinars\Models\Webinar;
use App\Modules\Webinars\Models\WebinarRegistration;
use App\Modules\Webinars\Models\WebinarScheduleChange;
use App\Modules\Webinars\Services\WebinarScheduleChangeCopy;
use Illuminate\Support\Facades\DB;

class ProcessWebinarScheduleChangeAction
{
    private const PAGE_SIZE = 100;

    public function __construct(
        private readonly ScheduleMessageAction $scheduleMessage,
        private readonly MessageChannelAvailability $availability,
        private readonly MessageGate $gate,
        private readonly WebinarScheduleChangeCopy $copy,
    ) {}

    public function handle(int $changeId): void
    {
        $webinarId = WebinarScheduleChange::query()->whereKey($changeId)->value('webinar_id');

        if ($webinarId === null) {
            return;
        }

        // Resync locks the occurrence first. Use the same lock order here.
        DB::transaction(function () use ($changeId, $webinarId): void {
            $webinar = Webinar::query()->lockForUpdate()->find($webinarId);
            $change = WebinarScheduleChange::query()->lockForUpdate()->find($changeId);

            if (! $webinar || ! $change
                || $change->status !== WebinarScheduleChange::STATUS_DISPATCHING) {
                return;
            }

            if (! $this->isCurrent($change, $webinar)) {
                $change->update(['status' => WebinarScheduleChange::STATUS_SUPERSEDED]);

                return;
            }

            $registrations = WebinarRegistration::query()
                ->with('contact')
                ->where('webinar_id', $webinarId)
                ->where('id', '>', $change->last_registration_id)
                ->where(function ($query) use ($change): void {
                    $query->where('registered_at', '<=', $change->created_at)
                        ->orWhere(function ($legacy) use ($change): void {
                            $legacy->whereNull('registered_at')
                                ->where('created_at', '<=', $change->created_at);
                        });
                })
                ->orderBy('id')
                ->limit(self::PAGE_SIZE)
                ->get();

            if ($registrations->isEmpty()) {
                $change->update([
                    'status' => WebinarScheduleChange::STATUS_COMPLETED,
                    'completed_at' => now(),
                ]);

                return;
            }

            $labels = $this->copy->labels($change);
            $queued = 0;
            $visible = $this->availability->visibleChannelsForSurface(
                surface: 'webinar_registrations', purpose: 'transactional',
                scope: 'webinar', requireProvider: true,
            );
            $versions = [];

            foreach (['email', 'sms'] as $channel) {
                $versionId = (int) data_get($change->template_version_ids, $channel, 0);
                $versions[$channel] = $versionId > 0
                    ? MessageTemplateVersion::query()->find($versionId)
                    : null;
            }

            foreach ($registrations as $registration) {
                if ($registration->cancelled_at !== null
                    || $registration->status === 'cancelled'
                    || ! $registration->contact) {
                    continue;
                }

                $accepted = data_get($registration->meta, 'accepted_channels.transactional');
                $channels = is_array($accepted)
                    ? array_intersect($change->channels ?? [], $visible, $accepted)
                    : array_intersect($change->channels ?? [], $visible);

                foreach ($channels as $channel) {
                    $contact = $registration->contact;

                    if (! $this->gate->allows(
                        $contact, $channel, 'transactional', 'webinar',
                        'webinar_schedule_change',
                    )) {
                        continue;
                    }

                    $version = $versions[$channel] ?? null;

                    if ($change->notification_mode === 'automatic' && ! $version) {
                        continue;
                    }

                    $payload = $version
                        ? array_replace($version->payload(), [
                            'to' => $channel === 'email' ? $contact->email : $contact->phone,
                            'tokens' => [
                                'first_name' => trim((string) $contact->first_name) ?: 'there',
                                'webinar_title' => $webinar->title,
                                'previous_webinar_time' => $labels['previous'],
                                'current_webinar_time' => $labels['current'],
                            ],
                        ])
                        : $this->defaultPayload($channel, $contact, $webinar, $labels);

                    if ($channel === 'email') {
                        $payload['contact_id'] = $contact->getKey();
                    }

                    $message = $this->scheduleMessage->handle(
                        recipient: $contact,
                        channel: $channel,
                        purpose: 'transactional',
                        scope: 'webinar',
                        messageType: 'webinar_schedule_change',
                        payloadClass: $channel === 'email' ? EmailPayload::class : SmsPayload::class,
                        payload: $payload,
                        sendAt: now(),
                        context: $registration,
                        behaviorOwner: $change,
                        dedupeKey: 'webinar_schedule_change:'.$change->getKey()
                            .':'.$registration->getKey().':'.$channel,
                        meta: ['webinar_id' => $webinar->getKey(), 'schedule_change_id' => $change->getKey()],
                        queue: (string) config('webinars.queues.notifications', 'notifications'),
                        messageTemplateVersionId: $version?->getKey(),
                    );

                    if ($message->wasRecentlyCreated) {
                        $queued++;
                    }
                }
            }

            $change->update([
                'last_registration_id' => $registrations->last()->getKey(),
                'messages_queued' => $change->messages_queued + $queued,
            ]);

            // The final, empty page marks the change complete. Each page is
            // atomic; a retried page reuses the same dedupe keys.
            ProcessWebinarScheduleChangeJob::dispatch($changeId)->afterCommit();
        }, 3);
    }

    private function defaultPayload(string $channel, Contact $contact, Webinar $webinar, array $labels): array
    {
        $name = trim((string) $contact->first_name);
        $greeting = $name !== '' ? 'Hi '.$name.'!' : 'Hello!';
        $body = $greeting.' The time for '.$webinar->title
            .' has changed from '.$labels['previous'].' to '.$labels['current'].'.';

        return $channel === 'email'
            ? ['to' => $contact->email, 'subject' => 'New time for '.$webinar->title, 'body' => $body]
            : ['to' => $contact->phone, 'message' => $body];
    }

    public function isCurrent(WebinarScheduleChange $change, Webinar $webinar): bool
    {
        return $webinar->isProviderActive()
            && ! $webinar->isHidden()
            && $webinar->starts_at?->isFuture()
            && $webinar->starts_at?->equalTo($change->current_starts_at)
            && $webinar->timezone === $change->current_timezone;
    }
}