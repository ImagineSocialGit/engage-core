<?php

namespace Tests\Feature\ProjectState;

use App\Modules\Messaging\Jobs\PublishScheduledMessageOutboxEventsJob;
use App\Modules\Messaging\Jobs\SendScheduledMessageJob;
use App\Support\ProjectState\ProjectStateManager;
use App\Support\ProjectState\ProjectStateResumeManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class MessagingProjectStateRoundTripTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('client.key', 'test-client');
        config()->set('project_state.enforce_client_key', true);
    }

    public function test_messaging_state_round_trips_after_preset_sync_with_reference_remapping_and_paused_runtime(): void
    {
        Queue::fake();

        $this->seedSourceState();

        $projectState = app(ProjectStateManager::class);
        $document = $projectState->export();

        $this->assertSame(7, $document['version']);
        $this->assertCount(1, $document['sections']['messaging']['tables']['message_templates']);
        $this->assertCount(2, $document['sections']['messaging']['tables']['scheduled_messages']);
        $this->assertCount(2, $document['sections']['messaging']['tables']['scheduled_message_delivery_attempts']);

        $this->prepareFreshPresetSyncedTarget();

        $report = $projectState->validate($document);
        $warnings = implode(' ', $report['warnings']);

        $this->assertTrue($report['valid']);
        $this->assertStringContainsString(
            '[message_chain_enrollments.status] [active] → [paused]',
            $warnings,
        );
        $this->assertStringContainsString(
            '[scheduled_messages.status] [sending] → [paused]',
            $warnings,
        );
        $this->assertStringContainsString(
            '[scheduled_message_delivery_attempts.status] [claimed] → [paused]',
            $warnings,
        );
        $this->assertStringContainsString(
            '[scheduled_message_outbox_events.status] [pending] → [paused]',
            $warnings,
        );

        $applied = $projectState->import($document);

        $this->assertTrue($applied['applied']);

        $this->assertDatabaseHas('contacts', [
            'id' => 60,
            'email' => 'contact@example.com',
        ]);

        $this->assertDatabaseHas('message_template_presets', [
            'id' => 200,
            'key' => 'webinar.confirmation.email',
            'name' => 'Production confirmation email',
            'is_customized' => true,
        ]);
        $this->assertDatabaseHas('message_template_preset_assignments', [
            'id' => 201,
            'message_template_preset_id' => 200,
            'definition_key' => 'confirmation',
            'is_active' => true,
        ]);
        $this->assertDatabaseHas('message_template_catalog_entries', [
            'id' => 202,
            'message_template_preset_id' => 200,
            'item_key' => 'email.transactional.webinar.confirmation',
            'item_label' => 'Production confirmation',
        ]);

        $this->assertDatabaseHas('message_templates', [
            'id' => 210,
            'key' => 'email.transactional.webinar.confirmation',
            'current_version_id' => 211,
            'is_customized' => true,
        ]);
        $this->assertDatabaseHas('message_template_versions', [
            'id' => 211,
            'message_template_id' => 210,
            'version' => 1,
            'subject' => 'Production subject',
            'created_by' => null,
        ]);
        $this->assertDatabaseHas('message_chains', [
            'id' => 220,
            'key' => 'webinar.registration.default',
            'current_version_id' => 221,
            'is_customized' => true,
        ]);
        $this->assertDatabaseHas('message_chain_versions', [
            'id' => 221,
            'message_chain_id' => 220,
            'version' => 1,
            'created_by' => null,
        ]);
        $this->assertDatabaseHas('message_chain_steps', [
            'id' => 222,
            'message_chain_version_id' => 221,
            'key' => 'confirmation',
        ]);
        $this->assertDatabaseHas('message_chain_step_variants', [
            'id' => 223,
            'message_chain_step_id' => 222,
            'message_template_version_id' => 211,
            'key' => 'email',
        ]);

        $this->assertDatabaseHas('message_consents', [
            'id' => 130,
            'contact_id' => 60,
            'scope' => 'webinars',
        ]);
        $this->assertDatabaseHas('consent_revocations', [
            'id' => 131,
            'contact_id' => 60,
            'message_consent_id' => 130,
        ]);
        $this->assertDatabaseHas('message_suppressions', [
            'id' => 132,
            'destination' => 'blocked@example.com',
        ]);

        $this->assertDatabaseHas('message_chain_enrollments', [
            'id' => 140,
            'message_chain_version_id' => 221,
            'current_message_chain_step_id' => 222,
            'status' => 'paused',
        ]);
        $this->assertDatabaseHas('scheduled_messages', [
            'id' => 150,
            'message_template_version_id' => 211,
            'message_chain_enrollment_id' => 140,
            'message_chain_step_variant_id' => 223,
            'status' => 'paused',
        ]);
        $this->assertDatabaseHas('scheduled_messages', [
            'id' => 160,
            'message_template_version_id' => 211,
            'status' => 'sent',
        ]);
        $this->assertDatabaseHas('contact_permission_invitations', [
            'id' => 155,
            'contact_id' => 60,
            'scheduled_message_id' => 150,
        ]);
        $this->assertDatabaseHas('scheduled_message_render_contexts', [
            'id' => 151,
            'scheduled_message_id' => 150,
        ]);
        $this->assertDatabaseHas('scheduled_message_components', [
            'id' => 152,
            'scheduled_message_id' => 150,
            'message_template_version_id' => 211,
            'message_consent_id' => 130,
        ]);
        $this->assertDatabaseHas('scheduled_message_delivery_attempts', [
            'id' => 153,
            'scheduled_message_id' => 150,
            'status' => 'paused',
        ]);
        $this->assertDatabaseHas('scheduled_message_delivery_attempts', [
            'id' => 163,
            'scheduled_message_id' => 160,
            'status' => 'sent',
        ]);
        $this->assertDatabaseHas('scheduled_message_outbox_events', [
            'id' => 164,
            'scheduled_message_id' => 160,
            'delivery_attempt_id' => 163,
            'status' => 'paused',
        ]);
        $this->assertDatabaseHas('project_state_resume_items', [
            'category' => 'message_chain_enrollments',
            'source_table' => 'message_chain_enrollments',
            'source_record_id' => '140',
            'original_status' => 'active',
            'state' => 'pending',
        ]);
        $this->assertDatabaseHas('project_state_resume_items', [
            'category' => 'message_deliveries',
            'source_table' => 'scheduled_messages',
            'source_record_id' => '150',
            'original_status' => 'sending',
            'state' => 'pending',
        ]);
        $this->assertDatabaseHas('project_state_resume_items', [
            'category' => 'scheduled_message_outbox',
            'source_table' => 'scheduled_message_outbox_events',
            'source_record_id' => '164',
            'original_status' => 'pending',
            'state' => 'pending',
        ]);

        $resume = app(ProjectStateResumeManager::class);
        $resume->resume(ProjectStateResumeManager::CATEGORY_MESSAGE_CHAINS);
        $resume->resume(ProjectStateResumeManager::CATEGORY_MESSAGE_DELIVERIES);
        $resume->resume(ProjectStateResumeManager::CATEGORY_SCHEDULED_MESSAGE_OUTBOX);

        $this->assertDatabaseHas('message_chain_enrollments', [
            'id' => 140,
            'status' => 'active',
        ]);
        $this->assertDatabaseHas('scheduled_messages', [
            'id' => 150,
            'status' => 'pending',
        ]);
        $this->assertDatabaseHas('scheduled_message_delivery_attempts', [
            'id' => 153,
            'status' => 'recovered',
            'claim_token' => '11111111-1111-4111-8111-111111111111',
            'reason_code' => 'project_state_import_claim_recovered',
        ]);
        $this->assertDatabaseHas('scheduled_message_outbox_events', [
            'id' => 164,
            'status' => 'pending',
            'claim_token' => null,
            'claim_expires_at' => null,
        ]);
        $this->assertDatabaseMissing('project_state_resume_items', [
            'category' => 'message_chain_enrollments',
            'state' => 'pending',
        ]);
        $this->assertDatabaseMissing('project_state_resume_items', [
            'category' => 'message_deliveries',
            'state' => 'pending',
        ]);
        $this->assertDatabaseMissing('project_state_resume_items', [
            'category' => 'scheduled_message_outbox',
            'state' => 'pending',
        ]);

        Queue::assertPushed(SendScheduledMessageJob::class, 1);
        Queue::assertPushed(PublishScheduledMessageOutboxEventsJob::class, 1);
    }

    public function test_interrupted_provider_submission_is_failed_instead_of_blindly_resent(): void
    {
        Queue::fake();
        Event::fake();
        config()->set('messaging.email.provider', 'dev_sink');
        config()->set(
            'messaging.delivery.provider_idempotency.email.dev_sink.enabled',
            false,
        );

        $this->seedSourceState();

        DB::table('scheduled_message_delivery_attempts')
            ->where('id', 153)
            ->update([
                'status' => 'submitting',
                'provider_submission_started_at' => now(),
            ]);

        $projectState = app(ProjectStateManager::class);
        $document = $projectState->export();
        $this->prepareFreshPresetSyncedTarget();
        $projectState->import($document);

        $resume = app(ProjectStateResumeManager::class);
        $resume->resume(ProjectStateResumeManager::CATEGORY_MESSAGE_CHAINS);
        $resume->resume(ProjectStateResumeManager::CATEGORY_MESSAGE_DELIVERIES);

        $this->assertDatabaseHas('scheduled_messages', [
            'id' => 150,
            'status' => 'failed',
        ]);
        $this->assertDatabaseHas('scheduled_message_delivery_attempts', [
            'id' => 153,
            'status' => 'failed',
            'reason_code' => 'project_state_import_provider_outcome_unknown',
        ]);
        $this->assertDatabaseHas('scheduled_message_outbox_events', [
            'scheduled_message_id' => 150,
            'delivery_attempt_id' => 153,
            'event_type' => 'failed',
            'reason_code' => 'project_state_import_provider_outcome_unknown',
        ]);
        $this->assertDatabaseMissing('project_state_resume_items', [
            'category' => 'message_deliveries',
            'state' => 'pending',
        ]);

        Queue::assertNotPushed(SendScheduledMessageJob::class);
    }

    public function test_validation_rejects_a_broken_messaging_reference_before_import(): void
    {
        $this->seedSourceState();

        $projectState = app(ProjectStateManager::class);
        $document = $projectState->export();
        unset($document['checksum']);

        $document['sections']['messaging']['tables']['scheduled_messages'][0]['message_template_version_id'] = 999999;

        $this->prepareFreshPresetSyncedTarget();

        $report = $projectState->validate($document);

        $this->assertFalse($report['valid']);
        $this->assertStringContainsString(
            'scheduled_messages.0.message_template_version_id',
            implode(' ', $report['errors']),
        );
        $this->assertDatabaseMissing('scheduled_messages', [
            'id' => 150,
        ]);
    }

    private function seedSourceState(): void
    {
        $now = now()->startOfSecond();

        DB::table('contacts')->insert([
            'id' => 60,
            'first_name' => 'Project',
            'last_name' => 'State',
            'name' => 'Project State',
            'email' => 'contact@example.com',
            'phone' => '+15555550123',
            'source' => 'production',
            'subsource' => null,
            'contact_import_batch_id' => null,
            'last_contacted_at' => $now,
            'last_activity_at' => $now,
            'meta' => json_encode(['source' => 'project_state_test']),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('message_template_presets')->insert([
            'id' => 100,
            'key' => 'webinar.confirmation.email',
            'name' => 'Production confirmation email',
            'description' => 'Customized production confirmation.',
            'channel' => 'email',
            'purpose' => 'transactional',
            'scope' => 'webinars',
            'message_type' => 'confirmation',
            'payload_class' => 'App\\Modules\\Messaging\\Payloads\\EmailPayload',
            'queue' => 'confirmation_messages',
            'dispatch_keys' => json_encode(['webinar_registration_confirmation']),
            'payload' => json_encode(['subject' => 'Production subject']),
            'tokens' => json_encode(['contact.first_name']),
            'status' => 'active',
            'is_active' => true,
            'source' => 'preset',
            'source_config_path' => 'messaging.email.definitions.transactional.webinar.confirmation',
            'source_version' => 1,
            'is_customized' => true,
            'customized_at' => $now,
            'last_synced_at' => $now,
            'meta' => json_encode(['owner' => 'production']),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('message_template_preset_assignments')->insert([
            'id' => 101,
            'message_template_preset_id' => 100,
            'channel' => 'email',
            'purpose' => 'transactional',
            'scope' => 'webinars',
            'surface' => 'registration',
            'message_type' => 'confirmation',
            'definition_key' => 'confirmation',
            'campaign_key' => null,
            'campaign_step' => null,
            'campaign_step_variant_key' => null,
            'source_config_path' => 'messaging.email.definitions.transactional.webinar.confirmation',
            'context_type' => null,
            'context_id' => null,
            'is_active' => true,
            'starts_at' => null,
            'ends_at' => null,
            'meta' => json_encode(['owner' => 'production']),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('message_template_catalog_entries')->insert([
            'id' => 102,
            'message_template_preset_id' => 100,
            'channel' => 'email',
            'purpose' => 'transactional',
            'scope' => 'webinars',
            'module_key' => 'webinars',
            'module_label' => 'Webinars',
            'surface' => 'registration',
            'group_key' => 'webinars:registration',
            'group_label' => 'Registration',
            'item_key' => 'email.transactional.webinar.confirmation',
            'item_label' => 'Production confirmation',
            'item_order' => 10,
            'usage_type' => 'webinar_confirmation',
            'source' => 'preset',
            'source_config_path' => 'messaging.email.definitions.transactional.webinar.confirmation',
            'context_type' => null,
            'context_id' => null,
            'is_active' => true,
            'meta' => json_encode(['owner' => 'production']),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('message_templates')->insert([
            'id' => 110,
            'key' => 'email.transactional.webinar.confirmation',
            'name' => 'Production confirmation',
            'description' => 'Customized production template.',
            'channel' => 'email',
            'status' => 'active',
            'current_version_id' => null,
            'source' => 'preset',
            'source_version' => '1',
            'is_customized' => true,
            'customized_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('message_template_versions')->insert([
            'id' => 111,
            'message_template_id' => 110,
            'version' => 1,
            'subject' => 'Production subject',
            'content' => json_encode(['body' => ['Production body']]),
            'renderer_key' => 'email_blade',
            'renderer_version' => '1',
            'content_hash' => str_repeat('a', 64),
            'created_by' => null,
            'created_at' => $now,
        ]);

        DB::table('message_templates')->where('id', 110)->update([
            'current_version_id' => 111,
        ]);

        DB::table('message_chains')->insert([
            'id' => 120,
            'key' => 'webinar.registration.default',
            'name' => 'Production registration chain',
            'description' => 'Customized production chain.',
            'status' => 'active',
            'current_version_id' => null,
            'source' => 'preset',
            'source_version' => '1',
            'is_customized' => true,
            'customized_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('message_chain_versions')->insert([
            'id' => 121,
            'message_chain_id' => 120,
            'version' => 1,
            'exit_conditions' => json_encode([]),
            'content_hash' => str_repeat('b', 64),
            'published_at' => $now,
            'created_by' => null,
            'created_at' => $now,
        ]);

        DB::table('message_chains')->where('id', 120)->update([
            'current_version_id' => 121,
        ]);

        DB::table('message_chain_steps')->insert([
            'id' => 122,
            'message_chain_version_id' => 121,
            'key' => 'confirmation',
            'name' => 'Confirmation',
            'sort_order' => 10,
            'timing_type' => 'immediate',
            'anchor_key' => null,
            'offset_seconds' => 0,
            'day_offset' => 0,
            'local_time' => null,
            'variant_strategy' => 'first_available',
            'advance_policy' => 'all_terminal',
            'conditions' => json_encode([]),
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('message_chain_step_variants')->insert([
            'id' => 123,
            'message_chain_step_id' => 122,
            'key' => 'email',
            'sort_order' => 10,
            'message_template_version_id' => 111,
            'channel' => 'email',
            'purpose' => 'transactional',
            'scope' => 'webinars',
            'message_type' => 'confirmation',
            'queue' => 'confirmation_messages',
            'dependency_policy' => json_encode([]),
            'conditions' => json_encode([]),
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('message_consents')->insert([
            'id' => 130,
            'contact_id' => 60,
            'channel' => 'email',
            'purpose' => 'transactional',
            'scope' => 'webinars',
            'consented_at' => $now,
            'source' => 'registration',
            'ip_address' => '127.0.0.1',
            'user_agent' => 'ProjectStateTest',
            'meta' => json_encode(['owner' => 'production']),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('consent_revocations')->insert([
            'id' => 131,
            'contact_id' => 60,
            'message_consent_id' => 130,
            'channel' => 'email',
            'purpose' => 'transactional',
            'scope' => 'webinars',
            'reason' => 'test_history',
            'revoked_at' => $now,
            'source' => 'crm',
            'ip_address' => null,
            'user_agent' => null,
            'meta' => json_encode(['owner' => 'production']),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('message_suppressions')->insert([
            'id' => 132,
            'channel' => 'email',
            'destination' => 'blocked@example.com',
            'reason' => 'manual',
            'provider' => null,
            'source_event_id' => null,
            'suppressed_at' => $now,
            'released_at' => null,
            'meta' => json_encode(['owner' => 'production']),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('message_chain_enrollments')->insert([
            'id' => 140,
            'message_chain_version_id' => 121,
            'recipient_type' => 'App\\Modules\\Core\\Models\\Contact',
            'recipient_id' => 60,
            'context_type' => null,
            'context_id' => null,
            'origin_type' => null,
            'origin_id' => null,
            'surface' => 'registration',
            'current_message_chain_step_id' => 122,
            'next_action_at' => $now->copy()->addHour(),
            'status' => 'active',
            'dedupe_key' => 'project-state-enrollment',
            'started_at' => $now,
            'paused_at' => null,
            'resumed_at' => null,
            'exited_at' => null,
            'exit_reason_code' => null,
            'completed_at' => null,
            'cancelled_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('scheduled_messages')->insert([
            $this->scheduledMessageRow(
                id: 150,
                status: 'sending',
                dedupeKey: 'project-state-message-sending',
                providerIdempotencyKey: 'project-state-provider-sending',
                now: $now,
            ),
            $this->scheduledMessageRow(
                id: 160,
                status: 'sent',
                dedupeKey: 'project-state-message-sent',
                providerIdempotencyKey: 'project-state-provider-sent',
                now: $now,
            ),
        ]);

        DB::table('contact_permission_invitations')->insert([
            'id' => 155,
            'contact_id' => 60,
            'scheduled_message_id' => 150,
            'token' => 'project-state-permission-token',
            'context_type' => null,
            'context_id' => null,
            'channel' => 'email',
            'source' => 'contact_import',
            'status' => 'sent',
            'claimed_at' => $now,
            'sent_at' => $now,
            'failed_at' => null,
            'accepted_at' => null,
            'accepted_channels' => json_encode(['email']),
            'failure_reason' => null,
            'meta' => json_encode(['owner' => 'production']),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('scheduled_message_render_contexts')->insert([
            'id' => 151,
            'scheduled_message_id' => 150,
            'values' => json_encode(['contact' => ['first_name' => 'Project']]),
            'content_hash' => str_repeat('c', 64),
            'rendered_at' => $now,
            'expires_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('scheduled_message_components')->insert([
            'id' => 152,
            'scheduled_message_id' => 150,
            'message_template_version_id' => 111,
            'role' => 'primary',
            'intent_key' => 'confirmation',
            'message_consent_id' => 130,
            'sort_order' => 10,
            'placement_key' => 'body',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('scheduled_message_delivery_attempts')->insert([
            [
                'id' => 153,
                'scheduled_message_id' => 150,
                'attempt_number' => 1,
                'claim_token' => '11111111-1111-4111-8111-111111111111',
                'status' => 'claimed',
                'claimed_at' => $now,
                'lease_expires_at' => $now->copy()->addMinutes(5),
                'provider_submission_started_at' => null,
                'completed_at' => null,
                'destination' => 'contact@example.com',
                'provider' => null,
                'provider_message_id' => null,
                'reason_code' => null,
                'reason' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'id' => 163,
                'scheduled_message_id' => 160,
                'attempt_number' => 1,
                'claim_token' => '22222222-2222-4222-8222-222222222222',
                'status' => 'sent',
                'claimed_at' => $now,
                'lease_expires_at' => $now->copy()->addMinutes(5),
                'provider_submission_started_at' => $now,
                'completed_at' => $now,
                'destination' => 'contact@example.com',
                'provider' => 'dev_sink',
                'provider_message_id' => 'provider-message-1',
                'reason_code' => null,
                'reason' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);

        DB::table('scheduled_message_outbox_events')->insert([
            'id' => 164,
            'scheduled_message_id' => 160,
            'delivery_attempt_id' => 163,
            'event_type' => 'sent',
            'occurred_at' => $now,
            'reason_code' => null,
            'reason' => null,
            'status' => 'pending',
            'available_at' => $now,
            'claim_token' => null,
            'claim_expires_at' => null,
            'attempts' => 0,
            'last_attempted_at' => null,
            'published_at' => null,
            'last_error' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function prepareFreshPresetSyncedTarget(): void
    {
        DB::table('scheduled_message_outbox_events')->delete();
        DB::table('scheduled_message_delivery_attempts')->delete();
        DB::table('scheduled_message_components')->delete();
        DB::table('scheduled_message_render_contexts')->delete();
        DB::table('contact_permission_invitations')->delete();
        DB::table('scheduled_messages')->delete();
        DB::table('message_chain_enrollments')->delete();
        DB::table('consent_revocations')->delete();
        DB::table('message_consents')->delete();
        DB::table('message_suppressions')->delete();

        DB::table('message_chain_step_variants')->delete();
        DB::table('message_chain_steps')->delete();
        DB::table('message_chains')->update(['current_version_id' => null]);
        DB::table('message_chain_versions')->delete();
        DB::table('message_chains')->delete();

        DB::table('message_templates')->update(['current_version_id' => null]);
        DB::table('message_template_versions')->delete();
        DB::table('message_templates')->delete();

        DB::table('message_template_catalog_entries')->delete();
        DB::table('message_template_preset_assignments')->delete();
        DB::table('message_template_presets')->delete();

        DB::table('notes')->delete();
        DB::table('contact_tags')->delete();
        DB::table('contacts')->delete();
        DB::table('contact_import_batches')->delete();
        DB::table('site_settings')->delete();

        $now = now()->startOfSecond();

        DB::table('message_template_presets')->insert([
            'id' => 200,
            'key' => 'webinar.confirmation.email',
            'name' => 'Fresh preset confirmation',
            'description' => null,
            'channel' => 'email',
            'purpose' => 'transactional',
            'scope' => 'webinars',
            'message_type' => 'confirmation',
            'payload_class' => 'App\\Modules\\Messaging\\Payloads\\EmailPayload',
            'queue' => 'confirmation_messages',
            'dispatch_keys' => json_encode(['webinar_registration_confirmation']),
            'payload' => json_encode(['subject' => 'Fresh preset subject']),
            'tokens' => json_encode(['contact.first_name']),
            'status' => 'active',
            'is_active' => true,
            'source' => 'preset',
            'source_config_path' => 'messaging.email.definitions.transactional.webinar.confirmation',
            'source_version' => 2,
            'is_customized' => false,
            'customized_at' => null,
            'last_synced_at' => $now,
            'meta' => json_encode([]),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('message_template_preset_assignments')->insert([
            'id' => 201,
            'message_template_preset_id' => 200,
            'channel' => 'email',
            'purpose' => 'transactional',
            'scope' => 'webinars',
            'surface' => 'registration',
            'message_type' => 'confirmation',
            'definition_key' => 'confirmation',
            'campaign_key' => null,
            'campaign_step' => null,
            'campaign_step_variant_key' => null,
            'source_config_path' => 'fresh.preset.path',
            'context_type' => null,
            'context_id' => null,
            'is_active' => false,
            'starts_at' => null,
            'ends_at' => null,
            'meta' => json_encode([]),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('message_template_catalog_entries')->insert([
            'id' => 202,
            'message_template_preset_id' => 200,
            'channel' => 'email',
            'purpose' => 'transactional',
            'scope' => 'webinars',
            'module_key' => 'webinars',
            'module_label' => 'Webinars',
            'surface' => 'registration',
            'group_key' => 'webinars:registration',
            'group_label' => 'Registration',
            'item_key' => 'email.transactional.webinar.confirmation',
            'item_label' => 'Fresh preset confirmation',
            'item_order' => 10,
            'usage_type' => 'webinar_confirmation',
            'source' => 'preset',
            'source_config_path' => 'fresh.preset.path',
            'context_type' => null,
            'context_id' => null,
            'is_active' => true,
            'meta' => json_encode([]),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('message_templates')->insert([
            'id' => 210,
            'key' => 'email.transactional.webinar.confirmation',
            'name' => 'Fresh preset confirmation',
            'description' => null,
            'channel' => 'email',
            'status' => 'active',
            'current_version_id' => null,
            'source' => 'preset',
            'source_version' => '2',
            'is_customized' => false,
            'customized_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('message_template_versions')->insert([
            'id' => 211,
            'message_template_id' => 210,
            'version' => 1,
            'subject' => 'Fresh preset subject',
            'content' => json_encode(['body' => ['Fresh preset body']]),
            'renderer_key' => 'email_blade',
            'renderer_version' => '2',
            'content_hash' => str_repeat('d', 64),
            'created_by' => null,
            'created_at' => $now,
        ]);

        DB::table('message_templates')->where('id', 210)->update([
            'current_version_id' => 211,
        ]);

        DB::table('message_chains')->insert([
            'id' => 220,
            'key' => 'webinar.registration.default',
            'name' => 'Fresh preset chain',
            'description' => null,
            'status' => 'active',
            'current_version_id' => null,
            'source' => 'preset',
            'source_version' => '2',
            'is_customized' => false,
            'customized_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('message_chain_versions')->insert([
            'id' => 221,
            'message_chain_id' => 220,
            'version' => 1,
            'exit_conditions' => json_encode([]),
            'content_hash' => str_repeat('e', 64),
            'published_at' => $now,
            'created_by' => null,
            'created_at' => $now,
        ]);

        DB::table('message_chains')->where('id', 220)->update([
            'current_version_id' => 221,
        ]);

        DB::table('message_chain_steps')->insert([
            'id' => 222,
            'message_chain_version_id' => 221,
            'key' => 'confirmation',
            'name' => 'Fresh preset confirmation',
            'sort_order' => 10,
            'timing_type' => 'immediate',
            'anchor_key' => null,
            'offset_seconds' => 0,
            'day_offset' => 0,
            'local_time' => null,
            'variant_strategy' => 'first_available',
            'advance_policy' => 'all_terminal',
            'conditions' => json_encode([]),
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('message_chain_step_variants')->insert([
            'id' => 223,
            'message_chain_step_id' => 222,
            'key' => 'email',
            'sort_order' => 10,
            'message_template_version_id' => 211,
            'channel' => 'email',
            'purpose' => 'transactional',
            'scope' => 'webinars',
            'message_type' => 'confirmation',
            'queue' => 'confirmation_messages',
            'dependency_policy' => json_encode([]),
            'conditions' => json_encode([]),
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function scheduledMessageRow(
        int $id,
        string $status,
        string $dedupeKey,
        string $providerIdempotencyKey,
        mixed $now,
    ): array {
        return [
            'id' => $id,
            'recipient_type' => 'App\\Modules\\Core\\Models\\Contact',
            'recipient_id' => 60,
            'context_type' => null,
            'context_id' => null,
            'behavior_owner_type' => null,
            'behavior_owner_id' => null,
            'channel' => 'email',
            'message_type' => 'confirmation',
            'purpose' => 'transactional',
            'scope' => 'webinars',
            'payload_class' => 'App\\Modules\\Messaging\\Payloads\\EmailPayload',
            'queue' => 'confirmation_messages',
            'dispatch_keys' => json_encode(['webinar_registration_confirmation']),
            'definition_config_path' => 'messaging.email.definitions.transactional.webinar.confirmation',
            'payload' => json_encode([
                'to' => 'contact@example.com',
                'subject' => 'Production subject',
            ]),
            'send_at' => $now->copy()->addHour(),
            'status' => $status,
            'provider_idempotency_key' => $providerIdempotencyKey,
            'dedupe_key' => $dedupeKey,
            'meta' => json_encode(['owner' => 'production']),
            'created_at' => $now,
            'updated_at' => $now,
            'message_template_version_id' => 111,
            'message_chain_enrollment_id' => 140,
            'message_chain_step_variant_id' => 123,
        ];
    }
}