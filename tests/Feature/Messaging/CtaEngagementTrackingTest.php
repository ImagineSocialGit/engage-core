<?php

namespace Tests\Feature\Messaging;

use App\Modules\Messaging\Jobs\PruneScheduledMessageCtaEngagementsJob;
use App\Modules\Messaging\Models\ScheduledMessage;
use App\Modules\Messaging\Models\ScheduledMessageCtaEngagement;
use App\Modules\Messaging\Payloads\EmailPayload;
use App\Modules\Messaging\Support\CtaTrackingLinkGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CtaEngagementTrackingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('modules.enabled', ['core', 'messaging']);
        config()->set('messaging.cta_tracking.enabled', true);
        config()->set('messaging.cta_tracking.retention_days', 180);
    }

    public function test_tracked_link_redirects_and_aggregates_likely_human_occurrences(): void
    {
        $message = ScheduledMessage::factory()->forContact()->create([
            'send_at' => now(),
        ]);
        $destination = 'https://example.test/replay?registration=123';
        $url = app(CtaTrackingLinkGenerator::class)->forScheduledMessage(
            scheduledMessageId: (int) $message->getKey(),
            ctaKey: 'replay',
            destination: $destination,
        );

        $headers = [
            'User-Agent' => 'Mozilla/5.0',
            'Sec-Fetch-Mode' => 'navigate',
            'Sec-Fetch-Dest' => 'document',
            'Sec-Fetch-User' => '?1',
        ];

        $this->withHeaders($headers)
            ->get($url)
            ->assertRedirect($destination);

        $this->withHeaders($headers)
            ->get($url)
            ->assertRedirect($destination);

        $this->assertDatabaseHas('scheduled_message_cta_engagements', [
            'scheduled_message_id' => $message->getKey(),
            'cta_key' => 'replay',
            'classification' => ScheduledMessageCtaEngagement::CLASSIFICATION_LIKELY_HUMAN,
            'occurrence_count' => 2,
        ]);

        $this->assertSame(
            1,
            ScheduledMessageCtaEngagement::query()->count(),
        );
    }

    public function test_scanner_and_prefetch_evidence_remain_separate_from_likely_human(): void
    {
        $message = ScheduledMessage::factory()->forContact()->create([
            'send_at' => now(),
        ]);
        $destination = 'https://example.test/apply';
        $url = app(CtaTrackingLinkGenerator::class)->forScheduledMessage(
            scheduledMessageId: (int) $message->getKey(),
            ctaKey: 'pre_approval',
            destination: $destination,
        );

        $this->withHeaders([
            'User-Agent' => 'Proofpoint URL Defense',
        ])->get($url)->assertRedirect($destination);

        $this->withHeaders([
            'Purpose' => 'prefetch',
            'User-Agent' => 'Mozilla/5.0',
        ])->get($url)->assertRedirect($destination);

        $this->assertDatabaseHas('scheduled_message_cta_engagements', [
            'scheduled_message_id' => $message->getKey(),
            'cta_key' => 'pre_approval',
            'classification' => ScheduledMessageCtaEngagement::CLASSIFICATION_SCANNER,
            'occurrence_count' => 1,
        ]);

        $this->assertDatabaseHas('scheduled_message_cta_engagements', [
            'scheduled_message_id' => $message->getKey(),
            'cta_key' => 'pre_approval',
            'classification' => ScheduledMessageCtaEngagement::CLASSIFICATION_PREFETCH,
            'occurrence_count' => 1,
        ]);
    }

    public function test_old_messages_still_redirect_without_extending_retained_evidence(): void
    {
        $message = ScheduledMessage::factory()->forContact()->create([
            'send_at' => now()->subDays(181),
        ]);
        $destination = 'https://example.test/replay';
        $url = app(CtaTrackingLinkGenerator::class)->forScheduledMessage(
            scheduledMessageId: (int) $message->getKey(),
            ctaKey: 'replay',
            destination: $destination,
        );

        $this->withHeaders([
            'Sec-Fetch-Mode' => 'navigate',
            'Sec-Fetch-Dest' => 'document',
            'Sec-Fetch-User' => '?1',
        ])->get($url)->assertRedirect($destination);

        $this->assertDatabaseCount('scheduled_message_cta_engagements', 0);
    }

    public function test_pruning_is_bounded_by_scheduled_message_age(): void
    {
        config()->set('messaging.cta_tracking.prune_batch_size', 100);
        config()->set('messaging.cta_tracking.prune_max_rows_per_run', 100);

        $oldMessage = ScheduledMessage::factory()->forContact()->create([
            'send_at' => now()->subDays(181),
        ]);
        $currentMessage = ScheduledMessage::factory()->forContact()->create([
            'send_at' => now(),
        ]);

        ScheduledMessageCtaEngagement::query()->create([
            'scheduled_message_id' => $oldMessage->getKey(),
            'cta_key' => 'replay',
            'classification' => ScheduledMessageCtaEngagement::CLASSIFICATION_UNKNOWN,
            'occurrence_count' => 1,
            'first_occurred_at' => now(),
            'last_occurred_at' => now(),
        ]);

        ScheduledMessageCtaEngagement::query()->create([
            'scheduled_message_id' => $currentMessage->getKey(),
            'cta_key' => 'replay',
            'classification' => ScheduledMessageCtaEngagement::CLASSIFICATION_UNKNOWN,
            'occurrence_count' => 1,
            'first_occurred_at' => now(),
            'last_occurred_at' => now(),
        ]);

        app(PruneScheduledMessageCtaEngagementsJob::class)->handle();

        $this->assertDatabaseMissing('scheduled_message_cta_engagements', [
            'scheduled_message_id' => $oldMessage->getKey(),
        ]);
        $this->assertDatabaseHas('scheduled_message_cta_engagements', [
            'scheduled_message_id' => $currentMessage->getKey(),
        ]);
    }

    public function test_email_payload_wraps_only_explicitly_tracked_links(): void
    {
        $message = ScheduledMessage::factory()->forContact()->create([
            'send_at' => now(),
        ]);

        $payload = EmailPayload::fromArray([
            'to' => 'contact@example.test',
            'channel' => 'email',
            'purpose' => 'transactional',
            'scope' => 'webinar',
            'message_type' => 'post_attended',
            'subject' => 'Follow up',
            'body' => 'Choose your next step: {cta}',
            'ctas' => [
                [
                    'tracking_key' => 'replay',
                    'label' => 'Watch the Recording',
                    'url' => 'https://example.test/replay',
                ],
                [
                    'label' => 'Direct Link',
                    'url' => 'https://example.test/direct',
                ],
            ],
            'meta' => [
                'delivery' => [
                    'scheduled_message_id' => $message->getKey(),
                ],
            ],
        ]);

        $ctas = $payload->devPayload()['ctas'];

        $this->assertNotSame('https://example.test/replay', $ctas[0]['url']);
        $this->assertStringContainsString('/messaging/click/', $ctas[0]['url']);
        $this->assertSame('https://example.test/direct', $ctas[1]['url']);
    }

    public function test_invalid_tracking_identity_or_non_http_destination_is_not_wrapped(): void
    {
        $generator = app(CtaTrackingLinkGenerator::class);

        $this->assertSame(
            'https://example.test/replay',
            $generator->forScheduledMessage(1, 'Bad Key', 'https://example.test/replay'),
        );

        $this->assertSame(
            'mailto:test@example.test',
            $generator->forScheduledMessage(1, 'email', 'mailto:test@example.test'),
        );
    }

    public function test_signed_redirect_rejects_destination_tampering(): void
    {
        $message = ScheduledMessage::factory()->forContact()->create([
            'send_at' => now(),
        ]);

        $url = app(CtaTrackingLinkGenerator::class)->forScheduledMessage(
            scheduledMessageId: (int) $message->getKey(),
            ctaKey: 'replay',
            destination: 'https://example.test/replay',
        );

        $tampered = str_replace(
            urlencode('https://example.test/replay'),
            urlencode('https://attacker.example.test/'),
            $url,
        );

        $this->get($tampered)->assertForbidden();
        $this->assertDatabaseCount('scheduled_message_cta_engagements', 0);
    }
}