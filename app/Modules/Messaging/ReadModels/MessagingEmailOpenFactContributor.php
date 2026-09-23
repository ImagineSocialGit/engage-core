<?php

namespace App\Modules\Messaging\ReadModels;

use App\Modules\Messaging\Models\ScheduledMessageEmailOpenSignal;
use App\Support\Reporting\Contracts\ReportingProjectionFactContributor;
use App\Support\Reporting\Data\ReportingProjectionFact;
use App\Support\Reporting\Data\ReportingProjectionWindow;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Schema;

final class MessagingEmailOpenFactContributor implements ReportingProjectionFactContributor
{
    public const CONTRIBUTOR_KEY = 'messaging_email_open_evidence';
    public const FACT_KEY = 'messaging.email_open_evidence';
    public const FACT_VERSION = 1;

    public function key(): string
    {
        return self::CONTRIBUTOR_KEY;
    }

    /** @return iterable<int, ReportingProjectionFact> */
    public function facts(ReportingProjectionWindow $window): iterable
    {
        if (! Schema::hasTable('scheduled_message_email_open_signals')) {
            return;
        }

        $signals = ScheduledMessageEmailOpenSignal::query()
            ->with('scheduledMessage')
            ->whereBetween('first_occurred_at', [
                $window->startsAt,
                $window->endsAt,
            ])
            ->orderBy('first_occurred_at')
            ->orderBy('id')
            ->get();

        foreach ($signals as $signal) {
            $message = $signal->scheduledMessage;

            if ($message === null) {
                continue;
            }

            yield new ReportingProjectionFact(
                key: self::FACT_KEY,
                version: self::FACT_VERSION,
                occurredAt: CarbonImmutable::instance(
                    $signal->first_occurred_at,
                )->utc(),
                subjectType: $message->getMorphClass(),
                subjectId: (string) $message->getKey(),
                correlationId: (string) $signal->delivery_attempt_id,
                dimensions: array_filter([
                    'provider' => $this->boundedString($signal->provider, 64),
                    'purpose' => $this->boundedString($message->purpose, 64),
                    'scope' => $this->boundedString($message->scope, 96),
                    'message_type' => $this->boundedString($message->message_type, 96),
                ], static fn (mixed $value): bool => $value !== null),
                values: [
                    'occurrence_count' => max(1, (int) $signal->occurrence_count),
                    'evidence_kind' => 'provider_tracking_pixel_load',
                    'evidence_strength' => 'weak',
                    'human_read_confirmed' => false,
                    'last_occurred_at' => CarbonImmutable::instance(
                        $signal->last_occurred_at,
                    )->utc()->toISOString(),
                ],
            );
        }
    }

    private function boundedString(mixed $value, int $maxLength): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value !== '' ? mb_substr($value, 0, $maxLength) : null;
    }
}