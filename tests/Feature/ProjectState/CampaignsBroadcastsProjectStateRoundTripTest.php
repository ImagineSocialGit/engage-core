<?php

namespace Tests\Feature\ProjectState;

use App\Models\User;
use App\Modules\Broadcasts\Models\Broadcast;
use App\Modules\Core\Models\Contact;
use App\Modules\Messaging\Jobs\SendScheduledMessageJob;
use App\Modules\Messaging\Payloads\EmailPayload;
use App\Support\ProjectState\ProjectStateManager;
use App\Support\ProjectState\ProjectStateResumeManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class CampaignsBroadcastsProjectStateRoundTripTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('client.key', 'test-client');
        config()->set('project_state.enforce_client_key', true);
    }

    public function test_campaign_and_broadcast_state_round_trips_with_deferred_metadata_remapping(): void
    {
        Queue::fake();

        $this->seedSourceState();

        $projectState = app(ProjectStateManager::class);
        $document = $projectState->export();

        $this->assertSame((int) config('project_state.version'), $document['version']);
        $this->assertSame((int) config('project_state.sections.campaigns.version'), $document['sections']['campaigns']['version']);
        $this->assertCount(1, $document['sections']['campaigns']['tables']['campaigns']);
        $this->assertCount(1, $document['sections']['campaigns']['tables']['campaign_steps']);
        $this->assertCount(1, $document['sections']['campaigns']['tables']['campaign_step_variants']);
        $this->assertCount(1, $document['sections']['campaigns']['tables']['campaign_enrollments']);
        $this->assertCount(1, $document['sections']['broadcasts']['tables']['broadcasts']);
        $this->assertCount(1, $document['sections']['broadcasts']['tables']['broadcast_recipients']);

        $this->prepareFreshPresetSyncedTarget();

        $report = $projectState->validate($document);
        $warnings = implode(' ', $report['warnings']);

        $this->assertTrue($report['valid']);
        $this->assertEquals([], $report['errors']);
        $this->assertStringContainsString(
            '[campaign_enrollments.status] [active] → [paused]',
            $warnings,
        );
        $this->assertStringContainsString(
            '[broadcasts.status] [sending] → [paused]',
            $warnings,
        );
        $this->assertStringContainsString(
            '[scheduled_messages.status] [pending] → [paused]',
            $warnings,
        );

        $applied = $projectState->import($document);

        $this->assertTrue($applied['applied']);

        $this->assertDatabaseHas('campaigns', [
            'id' => 200,
            'key' => 'production_nurture',
            'name' => 'Production nurture',
            'status' => 'active',
            'message_chain_id' => 400,
            'is_customized' => true,
        ]);
        $this->assertDatabaseHas('campaign_steps', [
            'id' => 210,
            'campaign_id' => 200,
            'step_number' => 1,
            'name' => 'Production step',
            'is_customized' => true,
        ]);
        $this->assertDatabaseHas('campaign_step_variants', [
            'id' => 211,
            'campaign_step_id' => 210,
            'key' => 'email',
            'name' => 'Production email',
            'is_customized' => true,
        ]);
        $this->assertDatabaseHas('message_chain_enrollments', [
            'id' => 320,
            'message_chain_version_id' => 401,
            'status' => 'completed',
        ]);
        $this->assertDatabaseHas('campaign_enrollments', [
            'id' => 120,
            'contact_id' => 60,
            'campaign_id' => 200,
            'message_chain_enrollment_id' => 320,
            'current_campaign_step_id' => 210,
            'last_scheduled_message_id' => 130,
            'status' => 'paused',
        ]);

        $campaignMessageMeta = $this->jsonColumn(
            table: 'scheduled_messages',
            id: 130,
            column: 'meta',
        );

        $this->assertSame(120, $campaignMessageMeta['campaign_enrollment_id']);
        $this->assertSame(200, $campaignMessageMeta['campaign_id']);
        $this->assertSame(210, $campaignMessageMeta['campaign_step_id']);
        $this->assertSame(211, $campaignMessageMeta['campaign_step_variant_id']);

        $this->assertDatabaseHas('broadcasts', [
            'id' => 140,
            'user_id' => null,
            'name' => 'Production broadcast',
            'status' => 'paused',
        ]);
        $this->assertDatabaseHas('broadcast_recipients', [
            'id' => 141,
            'broadcast_id' => 140,
            'contact_id' => 60,
            'status' => 'scheduled',
        ]);
        $this->assertDatabaseHas('scheduled_messages', [
            'id' => 150,
            'context_type' => Broadcast::class,
            'context_id' => 140,
            'behavior_owner_type' => Broadcast::class,
            'behavior_owner_id' => 140,
            'status' => 'paused',
        ]);

        $broadcastMessageMeta = $this->jsonColumn(
            table: 'scheduled_messages',
            id: 150,
            column: 'meta',
        );

        $this->assertSame(140, $broadcastMessageMeta['broadcast_id']);
        $this->assertSame(141, $broadcastMessageMeta['broadcast_recipient_id']);
        $this->assertEquals(
            [150],
            $this->jsonColumn(
                table: 'broadcast_recipients',
                id: 141,
                column: 'scheduled_message_ids',
            ),
        );
        $this->assertDatabaseHas('project_state_resume_items', [
            'category' => 'campaign_enrollments',
            'source_table' => 'campaign_enrollments',
            'source_record_id' => '120',
            'original_status' => 'active',
            'state' => 'pending',
        ]);
        $this->assertDatabaseHas('project_state_resume_items', [
            'category' => 'broadcasts',
            'source_table' => 'broadcasts',
            'source_record_id' => '140',
            'original_status' => 'sending',
            'state' => 'pending',
        ]);
        $this->assertDatabaseHas('project_state_resume_items', [
            'category' => 'scheduled_messages',
            'source_table' => 'scheduled_messages',
            'source_record_id' => '150',
            'original_status' => 'pending',
            'state' => 'pending',
        ]);

        $resume = app(ProjectStateResumeManager::class);
        $resume->resume(ProjectStateResumeManager::CATEGORY_CAMPAIGNS);
        $resume->resume(ProjectStateResumeManager::CATEGORY_BROADCASTS);
        $resume->resume(ProjectStateResumeManager::CATEGORY_SCHEDULED_MESSAGES);

        $this->assertDatabaseHas('message_chain_enrollments', [
            'id' => 320,
            'message_chain_version_id' => 401,
            'status' => 'completed',
        ]);
        $this->assertDatabaseHas('campaign_enrollments', [
            'id' => 120,
            'status' => 'active',
        ]);
        $this->assertDatabaseHas('broadcasts', [
            'id' => 140,
            'status' => 'scheduled',
        ]);
        $this->assertDatabaseHas('scheduled_messages', [
            'id' => 130,
            'status' => 'pending',
        ]);
        $this->assertDatabaseHas('scheduled_messages', [
            'id' => 150,
            'status' => 'pending',
        ]);
        $this->assertDatabaseMissing('project_state_resume_items', [
            'category' => 'campaign_enrollments',
            'state' => 'pending',
        ]);
        $this->assertDatabaseMissing('project_state_resume_items', [
            'category' => 'broadcasts',
            'state' => 'pending',
        ]);
        $this->assertDatabaseMissing('project_state_resume_items', [
            'category' => 'scheduled_messages',
            'state' => 'pending',
        ]);

        Queue::assertPushed(SendScheduledMessageJob::class, 2);
    }

    public function test_validation_rejects_a_broken_campaign_id_inside_scheduled_message_metadata(): void
    {
        $this->seedSourceState();

        $projectState = app(ProjectStateManager::class);
        $document = $projectState->export();
        unset($document['checksum']);

        $document['sections']['messaging']['tables']['scheduled_messages'][0]['meta']['campaign_step_id'] = 999999;

        $this->prepareFreshPresetSyncedTarget();

        $report = $projectState->validate($document);

        $this->assertFalse($report['valid']);
        $this->assertStringContainsString(
            'scheduled_messages.0.meta.campaign_step_id',
            implode(' ', $report['errors']),
        );
        $this->assertDatabaseMissing('campaign_enrollments', [
            'id' => 120,
        ]);
    }

    private function seedSourceState(): void
    {
        $now = now()->startOfSecond();

        User::factory()->create([
            'id' => 7,
            'email' => 'broadcast-owner@example.com',
        ]);

        DB::table('contacts')->insert([
            'id' => 60,
            'first_name' => 'State',
            'last_name' => 'Recipient',
            'name' => 'State Recipient',
            'email' => 'state-recipient@example.com',
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

        DB::table('message_chains')->insert([
            'id' => 300,
            'key' => 'production_campaign_chain',
            'name' => 'Production campaign chain',
            'description' => 'Source chain selected by the production Campaign.',
            'status' => 'active',
            'current_version_id' => null,
            'source' => 'project_state_test',
            'source_version' => '1',
            'is_customized' => true,
            'customized_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('message_chain_versions')->insert([
            'id' => 301,
            'message_chain_id' => 300,
            'version' => 1,
            'exit_conditions' => json_encode([]),
            'content_hash' => hash('sha256', 'production-campaign-chain-v1'),
            'published_at' => $now,
            'created_by' => null,
            'created_at' => $now,
        ]);

        DB::table('message_chains')
            ->where('id', 300)
            ->update(['current_version_id' => 301]);

        DB::table('message_chain_enrollments')->insert([
            'id' => 320,
            'message_chain_version_id' => 301,
            'recipient_type' => Contact::class,
            'recipient_id' => 60,
            'context_type' => null,
            'context_id' => null,
            'origin_type' => null,
            'origin_id' => null,
            'surface' => 'campaigns',
            'current_message_chain_step_id' => null,
            'next_action_at' => null,
            'status' => 'completed',
            'dedupe_key' => 'project-state-campaign-chain-enrollment',
            'started_at' => $now,
            'paused_at' => null,
            'resumed_at' => null,
            'exited_at' => null,
            'exit_reason_code' => null,
            'completed_at' => $now,
            'cancelled_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('campaigns')->insert([
            'id' => 100,
            'key' => 'production_nurture',
            'name' => 'Production nurture',
            'description' => 'Customized production Campaign.',
            'message_chain_id' => 300,
            'channel' => 'email',
            'purpose' => 'marketing',
            'scope' => 'campaign',
            'status' => 'active',
            'source_version' => '1',
            'is_customized' => true,
            'customized_at' => $now,
            'meta' => json_encode(['owner' => 'production']),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('campaign_steps')->insert([
            'id' => 110,
            'campaign_id' => 100,
            'step_number' => 1,
            'name' => 'Production step',
            'dispatch_key' => 'campaign_step_due',
            'channel' => 'email',
            'purpose' => 'marketing',
            'scope' => 'campaign',
            'variant_strategy' => 'first_available',
            'is_active' => true,
            'criteria' => json_encode([]),
            'source_version' => '1',
            'is_customized' => true,
            'customized_at' => $now,
            'meta' => json_encode(['type' => 'message']),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('campaign_step_variants')->insert([
            'id' => 111,
            'campaign_step_id' => 110,
            'key' => 'email',
            'name' => 'Production email',
            'sort_order' => 10,
            'dispatch_key' => 'campaign_step_due',
            'channel' => 'email',
            'purpose' => 'marketing',
            'scope' => 'campaign',
            'is_active' => true,
            'criteria' => json_encode([]),
            'dependency_rules' => json_encode([]),
            'source_config_path' => 'messaging.email.definitions.marketing.campaign.production_nurture',
            'source_version' => '1',
            'is_customized' => true,
            'customized_at' => $now,
            'meta' => json_encode(['owner' => 'production']),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('scheduled_messages')->insert([
            $this->scheduledMessageRow(
                id: 130,
                scope: 'campaign',
                messageType: 'campaign_step',
                dedupeKey: 'project-state-campaign-message',
                providerKey: 'project-state-campaign-provider',
                meta: [
                    'campaign_enrollment_id' => 120,
                    'campaign_id' => 100,
                    'campaign_key' => 'production_nurture',
                    'campaign_step_id' => 110,
                    'campaign_step' => 1,
                    'campaign_step_variant_id' => 111,
                    'campaign_step_variant_key' => 'email',
                ],
                now: $now,
            ),
            $this->scheduledMessageRow(
                id: 150,
                scope: 'broadcasts',
                messageType: 'broadcast',
                dedupeKey: 'project-state-broadcast-message',
                providerKey: 'project-state-broadcast-provider',
                meta: [
                    'broadcast_id' => 140,
                    'broadcast_recipient_id' => 141,
                ],
                now: $now,
                contextType: Broadcast::class,
                contextId: 140,
                behaviorOwnerType: Broadcast::class,
                behaviorOwnerId: 140,
            ),
        ]);

        DB::table('campaign_enrollments')->insert([
            'id' => 120,
            'contact_id' => 60,
            'campaign_id' => 100,
            'message_chain_enrollment_id' => 320,
            'source_type' => null,
            'source_id' => null,
            'campaign_key' => 'production_nurture',
            'status' => 'active',
            'current_step' => 1,
            'current_campaign_step_id' => 110,
            'start_context' => json_encode(['source' => 'production']),
            'exit_conditions' => json_encode([]),
            'exited_at' => null,
            'exit_reason' => null,
            'last_scheduled_message_id' => 130,
            'dedupe_key' => 'project-state-campaign-enrollment',
            'started_at' => $now,
            'paused_at' => null,
            'resumed_at' => null,
            'cancelled_at' => null,
            'completed_at' => null,
            'meta' => json_encode(['owner' => 'production']),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('broadcasts')->insert([
            'id' => 140,
            'user_id' => 7,
            'name' => 'Production broadcast',
            'channel' => 'email',
            'purpose' => 'marketing',
            'scope' => 'broadcasts',
            'dispatch_key' => 'broadcast_send',
            'message_type' => 'broadcast',
            'payload_class' => EmailPayload::class,
            'queue' => 'marketing',
            'status' => 'sending',
            'send_at' => $now->copy()->addHour(),
            'payload' => json_encode(['subject' => 'Production broadcast']),
            'recipient_filter' => json_encode(['type' => 'contact_ids', 'contact_ids' => [60]]),
            'recipient_count' => 1,
            'scheduled_count' => 1,
            'cancelled_at' => null,
            'completed_at' => null,
            'meta' => json_encode(['owner' => 'production']),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('broadcast_recipients')->insert([
            'id' => 141,
            'broadcast_id' => 140,
            'contact_id' => 60,
            'status' => 'scheduled',
            'scheduled_message_ids' => json_encode([150]),
            'sent_at' => null,
            'terminal_reason' => null,
            'meta' => json_encode(['owner' => 'production']),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function prepareFreshPresetSyncedTarget(): void
    {
        DB::table('broadcast_recipients')->delete();
        DB::table('broadcasts')->delete();
        DB::table('campaign_enrollments')->delete();
        DB::table('scheduled_messages')->delete();
        DB::table('message_chain_enrollments')->delete();
        DB::table('campaign_step_variants')->delete();
        DB::table('campaign_steps')->delete();
        DB::table('campaigns')->delete();
        DB::table('message_chains')->update(['current_version_id' => null]);
        DB::table('message_chain_versions')->delete();
        DB::table('message_chains')->delete();
        DB::table('contacts')->delete();

        $now = now()->startOfSecond();

        DB::table('message_chains')->insert([
            'id' => 400,
            'key' => 'production_campaign_chain',
            'name' => 'Fresh preset campaign chain',
            'description' => null,
            'status' => 'active',
            'current_version_id' => null,
            'source' => 'project_state_test',
            'source_version' => '2',
            'is_customized' => false,
            'customized_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('message_chain_versions')->insert([
            'id' => 401,
            'message_chain_id' => 400,
            'version' => 1,
            'exit_conditions' => json_encode([]),
            'content_hash' => hash('sha256', 'fresh-preset-campaign-chain-v1'),
            'published_at' => $now,
            'created_by' => null,
            'created_at' => $now,
        ]);

        DB::table('message_chains')
            ->where('id', 400)
            ->update(['current_version_id' => 401]);

        DB::table('campaigns')->insert([
            'id' => 200,
            'key' => 'production_nurture',
            'name' => 'Fresh preset nurture',
            'description' => null,
            'message_chain_id' => 400,
            'channel' => 'email',
            'purpose' => 'marketing',
            'scope' => 'campaign',
            'status' => 'active',
            'source_version' => '2',
            'is_customized' => false,
            'customized_at' => null,
            'meta' => json_encode([]),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('campaign_steps')->insert([
            'id' => 210,
            'campaign_id' => 200,
            'step_number' => 1,
            'name' => 'Fresh preset step',
            'dispatch_key' => 'campaign_step_due',
            'channel' => 'email',
            'purpose' => 'marketing',
            'scope' => 'campaign',
            'variant_strategy' => 'first_available',
            'is_active' => true,
            'criteria' => json_encode([]),
            'source_version' => '2',
            'is_customized' => false,
            'customized_at' => null,
            'meta' => json_encode(['type' => 'message']),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('campaign_step_variants')->insert([
            'id' => 211,
            'campaign_step_id' => 210,
            'key' => 'email',
            'name' => 'Fresh preset email',
            'sort_order' => 10,
            'dispatch_key' => 'campaign_step_due',
            'channel' => 'email',
            'purpose' => 'marketing',
            'scope' => 'campaign',
            'is_active' => true,
            'criteria' => json_encode([]),
            'dependency_rules' => json_encode([]),
            'source_config_path' => 'fresh.preset.path',
            'source_version' => '2',
            'is_customized' => false,
            'customized_at' => null,
            'meta' => json_encode([]),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /**
     * @param array<string, mixed> $meta
     * @return array<string, mixed>
     */
    private function scheduledMessageRow(
        int $id,
        string $scope,
        string $messageType,
        string $dedupeKey,
        string $providerKey,
        array $meta,
        mixed $now,
        ?string $contextType = null,
        ?int $contextId = null,
        ?string $behaviorOwnerType = null,
        ?int $behaviorOwnerId = null,
    ): array {
        return [
            'id' => $id,
            'recipient_type' => Contact::class,
            'recipient_id' => 60,
            'context_type' => $contextType,
            'context_id' => $contextId,
            'behavior_owner_type' => $behaviorOwnerType,
            'behavior_owner_id' => $behaviorOwnerId,
            'channel' => 'email',
            'message_type' => $messageType,
            'purpose' => 'marketing',
            'scope' => $scope,
            'payload_class' => EmailPayload::class,
            'queue' => 'marketing',
            'dispatch_keys' => json_encode([$scope === 'campaign' ? 'campaign_step_due' : 'broadcast_send']),
            'definition_config_path' => null,
            'payload' => json_encode([
                'to' => 'state-recipient@example.com',
                'subject' => 'Project-state message',
            ]),
            'send_at' => $now->copy()->addHour(),
            'status' => 'pending',
            'provider_idempotency_key' => $providerKey,
            'dedupe_key' => $dedupeKey,
            'meta' => json_encode($meta),
            'created_at' => $now,
            'updated_at' => $now,
            'message_template_version_id' => null,
            'message_chain_enrollment_id' => null,
            'message_chain_step_variant_id' => null,
        ];
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