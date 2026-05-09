<?php

namespace App\Actions\Webinars;

use App\Data\WebinarMessageData;
use App\Jobs\Messaging\SendWebinarReminderEmailJob;
use App\Jobs\Messaging\SendWebinarReminderSmsJob;
use App\Models\WebinarRegistration;
use App\Models\WebinarScheduledMessage;
use Carbon\CarbonInterface;

class ScheduleWebinarRemindersAction
{
    public function execute(WebinarRegistration $registration): void
    {
        $registration->loadMissing(['lead', 'webinar']);

        $data = WebinarMessageData::fromRegistration($registration);
        $schedule = $this->scheduleFor($data);

        foreach ($schedule as $messageType => $runAt) {
            $channels = $this->channelsFor($messageType);

            if ($channels['email']) {
                $this->scheduleEmail($registration, $data, $messageType, $runAt);
            }

            if ($channels['sms']) {
                $this->scheduleSms($registration, $data, $messageType, $runAt);
            }
        }
    }

    protected function scheduleEmail(
        WebinarRegistration $registration,
        WebinarMessageData $data,
        string $messageType,
        CarbonInterface $runAt
    ): void {
        $scheduled = WebinarScheduledMessage::query()->firstOrNew([
            'webinar_registration_id' => $registration->id,
            'channel' => 'email',
            'message_type' => $messageType,
        ]);

        if ($scheduled->exists) {
            return;
        }

        if ($this->shouldSkip($runAt)) {
            $scheduled->fill([
                'status' => 'skipped',
                'send_at' => $runAt,
                'skipped_at' => now(),
                'meta' => [
                    'reason' => 'send_at_in_past',
                ],
            ])->save();

            return;
        }

        $scheduled->fill([
            'status' => 'pending',
            'send_at' => $runAt,
            'meta' => null,
        ])->save();

        $payload = [
            ...$data->toArray(),
            'message_type' => $messageType,
            'scheduled_message_id' => $scheduled->id,
        ];

        SendWebinarReminderEmailJob::dispatch($payload)
            ->delay($runAt)
            ->onQueue(config('webinars.queues.reminders'));
    }

    protected function scheduleSms(
        WebinarRegistration $registration,
        WebinarMessageData $data,
        string $messageType,
        CarbonInterface $runAt
    ): void {
        $scheduled = WebinarScheduledMessage::query()->firstOrNew([
            'webinar_registration_id' => $registration->id,
            'channel' => 'sms',
            'message_type' => $messageType,
        ]);

        if ($scheduled->exists) {
            return;
        }

        if ($this->shouldSkip($runAt)) {
            $scheduled->fill([
                'status' => 'skipped',
                'send_at' => $runAt,
                'skipped_at' => now(),
                'meta' => [
                    'reason' => 'send_at_in_past',
                ],
            ])->save();

            return;
        }

        $scheduled->fill([
            'status' => 'pending',
            'send_at' => $runAt,
            'meta' => null,
        ])->save();

        $payload = [
            ...$data->toArray(),
            'message_type' => $messageType,
            'scheduled_message_id' => $scheduled->id,
        ];

        SendWebinarReminderSmsJob::dispatch($payload)
            ->delay($runAt)
            ->onQueue(config('webinars.queues.reminders'));
    }

    protected function scheduleFor(WebinarMessageData $data): array
    {
        if (config('webinar_messaging.testing.enabled')) {
            return $this->testingSchedule();
        }

        $startsAt = $data->webinarStartsAt->copy();

        return [
            'reminder_10d' => $startsAt->copy()->subDays(10),
            'reminder_7d' => $startsAt->copy()->subDays(7),
            'reminder_24h' => $startsAt->copy()->subHours(24),
            'reminder_30m' => $startsAt->copy()->subMinutes(30),
            'reminder_10m' => $startsAt->copy()->subMinutes(10),
            'late_joiner_5m' => $startsAt->copy()->addMinutes(5),
        ];
    }

    protected function testingSchedule(): array
    {
        $delays = config('webinar_messaging.testing.delays', []);

        $schedule = [];

        foreach ($delays as $messageType => $seconds) {
            $schedule[$messageType] = now()->copy()->addSeconds((int) $seconds);
        }

        return $schedule;
    }

    protected function shouldSkip(CarbonInterface $runAt): bool
    {
        return $runAt->lessThanOrEqualTo(now());
    }

    protected function channelsFor(string $messageType): array
    {
        return config("webinar_messaging.message_types.{$messageType}", [
            'email' => false,
            'sms' => false,
        ]);
    }
}
