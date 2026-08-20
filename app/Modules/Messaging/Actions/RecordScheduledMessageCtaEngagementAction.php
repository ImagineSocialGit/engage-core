<?php

namespace App\Modules\Messaging\Actions;

use App\Modules\Messaging\Models\ScheduledMessage;
use App\Modules\Messaging\Models\ScheduledMessageCtaEngagement;
use App\Modules\Messaging\Support\CtaTrackingLinkGenerator;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

class RecordScheduledMessageCtaEngagementAction
{
    public function handle(
        ScheduledMessage $scheduledMessage,
        string $ctaKey,
        string $classification,
        ?CarbonInterface $occurredAt = null,
    ): void {
        if (! (bool) config('messaging.cta_tracking.enabled', true)
            || ! CtaTrackingLinkGenerator::isValidTrackingKey($ctaKey)
            || ! in_array($classification, ScheduledMessageCtaEngagement::classifications(), true)
            || $scheduledMessage->isTestingRuntime()
        ) {
            return;
        }

        $retentionDays = max(
            1,
            (int) config('messaging.cta_tracking.retention_days', 180),
        );

        if ($scheduledMessage->send_at === null
            || $scheduledMessage->send_at->lt(now()->subDays($retentionDays))
        ) {
            return;
        }

        $occurredAt ??= now();
        $identity = [
            'scheduled_message_id' => (int) $scheduledMessage->getKey(),
            'cta_key' => trim($ctaKey),
            'classification' => $classification,
        ];

        DB::table('scheduled_message_cta_engagements')->insertOrIgnore([
            ...$identity,
            'occurrence_count' => 0,
            'first_occurred_at' => $occurredAt,
            'last_occurred_at' => $occurredAt,
        ]);

        DB::table('scheduled_message_cta_engagements')
            ->where($identity)
            ->increment(
                'occurrence_count',
                1,
                ['last_occurred_at' => $occurredAt],
            );
    }
}