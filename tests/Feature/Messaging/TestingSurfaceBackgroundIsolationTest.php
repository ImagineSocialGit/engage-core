<?php

namespace Tests\Feature\Messaging;

use App\Modules\Core\Models\Contact;
use App\Modules\Messaging\Actions\RecoverStaleScheduledMessageClaimsAction;
use App\Modules\Messaging\Models\MessageChain;
use App\Modules\Messaging\Models\MessageChainEnrollment;
use App\Modules\Messaging\Models\MessageChainVersion;
use App\Modules\Messaging\Models\ScheduledMessage;
use App\Modules\Messaging\Models\ScheduledMessageDeliveryAttempt;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class TestingSurfaceBackgroundIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_stale_delivery_recovery_ignores_testing_surface_messages(): void
    {
        $contact = Contact::factory()->create();
        $chain = MessageChain::query()->create([
            'key' => 'testing.surface.background.isolation',
            'name' => 'Testing Surface Background Isolation',
            'status' => MessageChain::STATUS_ACTIVE,
            'source' => 'test',
        ]);
        $version = MessageChainVersion::query()->create([
            'message_chain_id' => $chain->getKey(),
            'version' => 1,
            'exit_conditions' => null,
            'content_hash' => hash('sha256', 'testing-surface-background-isolation'),
            'published_at' => now()->subHour(),
        ]);
        $chain->forceFill(['current_version_id' => $version->getKey()])->save();

        $enrollment = MessageChainEnrollment::query()->create([
            'message_chain_version_id' => $version->getKey(),
            'recipient_type' => $contact->getMorphClass(),
            'recipient_id' => $contact->getKey(),
            'context_type' => $contact->getMorphClass(),
            'context_id' => $contact->getKey(),
            'origin_type' => $contact->getMorphClass(),
            'origin_id' => $contact->getKey(),
            'surface' => 'testing:fixture',
            'status' => MessageChainEnrollment::STATUS_ACTIVE,
            'dedupe_key' => 'testing:fixture:'.$contact->getKey(),
            'started_at' => now()->subHour(),
            'next_action_at' => now()->subMinutes(30),
        ]);

        $message = ScheduledMessage::factory()->create([
            'message_chain_enrollment_id' => $enrollment->getKey(),
            'status' => ScheduledMessage::STATUS_SENDING,
        ]);

        $attempt = ScheduledMessageDeliveryAttempt::query()->create([
            'scheduled_message_id' => $message->getKey(),
            'attempt_number' => 1,
            'claim_token' => (string) Str::uuid(),
            'status' => ScheduledMessageDeliveryAttempt::STATUS_CLAIMED,
            'claimed_at' => now()->subMinutes(10),
            'lease_expires_at' => now()->subMinutes(5),
        ]);

        $result = app(RecoverStaleScheduledMessageClaimsAction::class)->handle();

        $this->assertEquals([], $result['requeued']);
        $this->assertEquals([], $result['failed']);
        $this->assertSame(ScheduledMessage::STATUS_SENDING, $message->fresh()->status);
        $this->assertSame(ScheduledMessageDeliveryAttempt::STATUS_CLAIMED, $attempt->fresh()->status);
        $this->assertNull($attempt->fresh()->completed_at);
    }
}