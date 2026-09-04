<?php

namespace App\Modules\Webinars\Actions;

use App\Modules\Messaging\Actions\CancelMessageChainEnrollmentAction;
use App\Modules\Messaging\Models\MessageChainEnrollment;
use App\Modules\Webinars\Models\Webinar;
use App\Modules\Webinars\Models\WebinarOccurrenceSuppression;
use App\Modules\Webinars\Models\WebinarWaitlistSignup;
use Illuminate\Support\Facades\DB;

class RemoveWebinarOccurrenceAction
{
    public function __construct(
        private readonly CancelMessageChainEnrollmentAction $cancelMessageChainEnrollment,
    ) {}

    /**
     * @return array{
     *     outcome: 'deleted'|'hidden',
     *     webinar_id: int,
     *     suppression_id: int|null,
     * }
     */
    public function handle(Webinar $webinar): array
    {
        return DB::transaction(function () use ($webinar): array {
            $locked = Webinar::query()
                ->with('webinarSeries')
                ->lockForUpdate()
                ->findOrFail($webinar->getKey());

            $this->cancelPendingWaitlistNotifications($locked);

            if ($this->canDeletePermanently($locked)) {
                $suppression = $this->suppressProviderIdentity($locked);
                $webinarId = (int) $locked->getKey();

                $locked->delete();

                return [
                    'outcome' => 'deleted',
                    'webinar_id' => $webinarId,
                    'suppression_id' => $suppression?->getKey(),
                ];
            }

            $locked->forceFill([
                'hidden_at' => now(),
                'hidden_reason' => Webinar::HIDDEN_REASON_OPERATOR_REMOVED,
            ])->save();

            return [
                'outcome' => 'hidden',
                'webinar_id' => (int) $locked->getKey(),
                'suppression_id' => null,
            ];
        });
    }

    private function cancelPendingWaitlistNotifications(Webinar $webinar): void
    {
        $waitlistMorph = (new WebinarWaitlistSignup())->getMorphClass();

        MessageChainEnrollment::query()
            ->where('origin_type', $webinar->getMorphClass())
            ->where('origin_id', $webinar->getKey())
            ->where('context_type', $waitlistMorph)
            ->orderBy('id')
            ->get()
            ->each(function (MessageChainEnrollment $enrollment): void {
                $this->cancelMessageChainEnrollment->handle(
                    enrollment: $enrollment,
                    reason: 'webinar_occurrence_removed',
                );
            });
    }

    private function canDeletePermanently(Webinar $webinar): bool
    {
        $externalId = is_string($webinar->external_id)
            ? trim($webinar->external_id)
            : '';

        return $webinar->webinar_series_id !== null
            && $externalId !== ''
            && ! $webinar->registrations()->exists()
            && $webinar->replacement_of_webinar_id === null
            && ! $webinar->replacement()->exists()
            && ! MessageChainEnrollment::query()
                ->where('origin_type', $webinar->getMorphClass())
                ->where('origin_id', $webinar->getKey())
                ->exists();
    }

    private function suppressProviderIdentity(
        Webinar $webinar,
    ): ?WebinarOccurrenceSuppression {
        $series = $webinar->webinarSeries;
        $externalId = is_string($webinar->external_id)
            ? trim($webinar->external_id)
            : '';

        if (! $series || $externalId === '') {
            return null;
        }

        $externalUuid = data_get($webinar->meta, 'provider.data.zoom_uuid')
            ?? data_get($webinar->meta, 'zoom_uuid');

        return WebinarOccurrenceSuppression::query()->updateOrCreate(
            [
                'webinar_series_id' => $series->getKey(),
                'platform' => $webinar->providerKey(),
                'provider_event_type' => $webinar->providerEventTypeKey(),
                'external_id' => $externalId,
            ],
            [
                'external_uuid' => is_string($externalUuid) && trim($externalUuid) !== ''
                    ? trim($externalUuid)
                    : null,
                'reason' => WebinarOccurrenceSuppression::REASON_OPERATOR_REMOVED,
                'suppressed_at' => now(),
                'meta' => [
                    'source_webinar_id' => $webinar->getKey(),
                    'source_title' => $webinar->title,
                    'source_starts_at' => $webinar->starts_at?->toIso8601String(),
                    'source_timezone' => $webinar->timezone,
                ],
            ],
        );
    }
}