<?php

namespace Tests\Feature\ProjectState;

use App\Models\User;
use App\Modules\Core\Models\Contact;
use App\Modules\InboundMessaging\Models\InboundMessage;
use App\Modules\InternalNotifications\Models\TeamMember;
use App\Modules\Messaging\Payloads\Internal\InternalEmailNotificationPayload;
use App\Support\ProjectState\ProjectStateManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class InboundMessagingProjectStateRoundTripTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('client.key', 'test-client');
        config()->set('project_state.enforce_client_key', true);
    }

    public function test_inbound_message_history_round_trips_without_operational_receipts(): void
    {
        $this->seedSourceState();

        $projectState = app(ProjectStateManager::class);
        $document = $projectState->export();

        $this->assertSame((int) config('project_state.version'), $document['version']);
        $this->assertCount(
            1,
            $document['sections']['inbound_messaging']['tables']['inbound_messages'],
        );
        $this->assertArrayNotHasKey(
            'inbound_message_receipts',
            $document['sections']['inbound_messaging']['tables'],
        );

        $this->prepareCleanTarget();

        $report = $projectState->validate($document);

        $this->assertTrue($report['valid']);
        $this->assertEquals([], $report['errors']);

        $applied = $projectState->import($document);

        $this->assertTrue($applied['applied']);

        $this->assertDatabaseHas('contacts', [
            'id' => 40,
            'email' => 'replying-contact@example.com',
        ]);
        $this->assertDatabaseHas('team_members', [
            'id' => 20,
            'email' => 'advisor@example.com',
        ]);
        $this->assertDatabaseHas('inbound_messages', [
            'id' => 50,
            'sender_type' => Contact::class,
            'sender_id' => 40,
            'client_key' => 'test-client',
            'channel' => 'email',
            'provider' => 'resend',
            'provider_event_id' => 'evt-project-state-inbound',
            'provider_message_id' => 'received-project-state-inbound',
            'provider_context_id' => null,
            'message_id' => '<project-state-inbound@example.test>',
            'from_type' => 'email',
            'from_value' => 'replying-contact@example.com',
            'to_type' => 'email',
            'to_value' => 'reply+project-state@replies.example.test',
            'subject' => 'Re: Project state thread',
            'body' => 'Please call me about the webinar.',
            'classification' => InboundMessage::CLASSIFICATION_NORMAL_REPLY,
            'purpose' => 'marketing',
            'scope' => 'inbound_messages',
        ]);

        $meta = $this->jsonColumn(
            table: 'inbound_messages',
            id: 50,
            column: 'meta',
        );

        $this->assertEquals([
            'source' => 'resend_received_email',
        ], $meta);

        $this->assertDatabaseHas('scheduled_messages', [
            'id' => 60,
            'recipient_type' => TeamMember::class,
            'recipient_id' => 20,
            'context_type' => InboundMessage::class,
            'context_id' => 50,
            'scope' => 'inbound_messages',
            'message_type' => 'inbound_reply',
            'status' => 'sent',
        ]);

        $this->assertDatabaseCount('inbound_message_receipts', 0);
        $this->assertDatabaseCount('project_state_resume_items', 0);
    }

    public function test_validation_rejects_an_unsupported_inbound_sender_type(): void
    {
        $this->seedSourceState();

        $projectState = app(ProjectStateManager::class);
        $document = $projectState->export();
        unset($document['checksum']);

        $document['sections']['inbound_messaging']['tables']['inbound_messages'][0]['sender_type'] = User::class;
        $document['sections']['inbound_messaging']['tables']['inbound_messages'][0]['sender_id'] = 999999;

        $this->prepareCleanTarget();

        $report = $projectState->validate($document);

        $this->assertFalse($report['valid']);
        $this->assertStringContainsString(
            'Project-state polymorphic reference [inbound_messages.0.sender_type] uses unsupported type [App\\Models\\User].',
            implode(' ', $report['errors']),
        );
        $this->assertDatabaseMissing('inbound_messages', [
            'id' => 50,
        ]);
        $this->assertDatabaseMissing('scheduled_messages', [
            'id' => 60,
        ]);
    }

    private function seedSourceState(): void
    {
        $now = now()->startOfSecond();

        DB::table('contacts')->insert([
            'id' => 40,
            'first_name' => 'Replying',
            'last_name' => 'Contact',
            'name' => 'Replying Contact',
            'email' => 'replying-contact@example.com',
            'phone' => '+15555550123',
            'source' => 'production',
            'subsource' => null,
            'contact_import_batch_id' => null,
            'last_contacted_at' => null,
            'last_activity_at' => $now,
            'meta' => json_encode(['owner' => 'production']),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('team_members')->insert([
            'id' => 20,
            'user_id' => null,
            'name' => 'Production Advisor',
            'email' => 'advisor@example.com',
            'phone' => '+15555550888',
            'role' => 'advisor',
            'is_active' => true,
            'meta' => json_encode(['owner' => 'production']),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('inbound_messages')->insert([
            'id' => 50,
            'sender_type' => Contact::class,
            'sender_id' => 40,
            'client_key' => 'test-client',
            'channel' => 'email',
            'provider' => 'resend',
            'provider_event_id' => 'evt-project-state-inbound',
            'provider_message_id' => 'received-project-state-inbound',
            'provider_context_id' => null,
            'message_id' => '<project-state-inbound@example.test>',
            'from_type' => 'email',
            'from_value' => 'replying-contact@example.com',
            'to_type' => 'email',
            'to_value' => 'reply+project-state@replies.example.test',
            'subject' => 'Re: Project state thread',
            'body' => 'Please call me about the webinar.',
            'classification' => InboundMessage::CLASSIFICATION_NORMAL_REPLY,
            'purpose' => 'marketing',
            'scope' => 'inbound_messages',
            'received_at' => $now->copy()->subMinutes(5),
            'processed_at' => $now->copy()->subMinutes(4),
            'meta' => json_encode([
                'source' => 'resend_received_email',
            ]),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('scheduled_messages')->insert([
            'id' => 60,
            'recipient_type' => TeamMember::class,
            'recipient_id' => 20,
            'context_type' => InboundMessage::class,
            'context_id' => 50,
            'behavior_owner_type' => null,
            'behavior_owner_id' => null,
            'channel' => 'email',
            'message_type' => 'inbound_reply',
            'purpose' => 'internal',
            'scope' => 'inbound_messages',
            'payload_class' => InternalEmailNotificationPayload::class,
            'queue' => 'notifications',
            'dispatch_keys' => json_encode(['inbound_reply']),
            'definition_config_path' => null,
            'payload' => json_encode([
                'to' => 'advisor@example.com',
                'subject' => 'New inbound reply',
                'body' => 'A contact replied.',
            ]),
            'send_at' => $now,
            'status' => 'sent',
            'provider_idempotency_key' => 'project-state-inbound-notification',
            'dedupe_key' => 'project-state-inbound-notification',
            'meta' => json_encode([
                'notification_type' => 'inbound_replies',
            ]),
            'created_at' => $now,
            'updated_at' => $now,
            'message_template_version_id' => null,
            'message_chain_enrollment_id' => null,
            'message_chain_step_variant_id' => null,
        ]);

        DB::table('inbound_message_receipts')->insert([
            'id' => 51,
            'inbound_message_id' => 50,
            'client_key' => 'test-client',
            'provider' => 'resend',
            'provider_event_id' => 'evt-project-state-inbound',
            'provider_message_id' => 'received-project-state-inbound',
            'provider_event_key' => hash('sha256', 'evt-project-state-inbound'),
            'provider_message_key' => hash('sha256', 'received-project-state-inbound'),
            'status' => 'completed',
            'attempts' => 1,
            'response_message' => null,
            'last_error' => null,
            'last_attempted_at' => $now->copy()->subMinutes(4),
            'completed_at' => $now->copy()->subMinutes(4),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function prepareCleanTarget(): void
    {
        DB::table('inbound_message_receipts')->delete();
        DB::table('scheduled_messages')->delete();
        DB::table('inbound_messages')->delete();
        DB::table('team_member_notification_preferences')->delete();
        DB::table('team_members')->delete();
        DB::table('contacts')->delete();
    }

    /**
     * @return array<int|string, mixed>
     */
    private function jsonColumn(string $table, int $id, string $column): array
    {
        $value = DB::table($table)
            ->where('id', $id)
            ->value($column);

        $decoded = json_decode((string) $value, true, flags: JSON_THROW_ON_ERROR);

        $this->assertIsArray($decoded);

        return $decoded;
    }
}