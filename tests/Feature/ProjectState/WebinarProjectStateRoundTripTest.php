<?php

namespace Tests\Feature\ProjectState;

use App\Modules\Webinars\Jobs\SyncWebinarRegistrationToProviderJob;
use App\Support\ProjectState\ProjectStateManager;
use App\Support\ProjectState\ProjectStateResumeManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class WebinarProjectStateRoundTripTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('client.key', 'test-client');
        config()->set('project_state.enforce_client_key', true);
    }

    public function test_webinar_state_round_trips_after_preset_sync_with_reference_remapping_and_paused_finalization(): void
    {
        Queue::fake();

        $this->seedSourceState();

        $projectState = app(ProjectStateManager::class);
        $document = $projectState->export();

        $this->assertSame(10, $document['version']);
        $this->assertCount(
            1,
            $document['sections']['webinars']['tables']['webinar_schedule_profiles'],
        );
        $this->assertCount(
            2,
            $document['sections']['webinars']['tables']['webinars'],
        );
        $this->assertCount(
            2,
            $document['sections']['webinars']['tables']['webinar_registrations'],
        );

        $this->prepareFreshPresetSyncedTarget();

        $report = $projectState->validate($document);
        $warnings = implode(' ', $report['warnings']);

        $this->assertTrue($report['valid']);
        $this->assertStringContainsString(
            '[webinar_registrations.meta.registration_finalization.status] [processing] → [paused]',
            $warnings,
        );
        $this->assertStringContainsString(
            '[message_chain_enrollments.status] [active] → [paused]',
            $warnings,
        );

        $applied = $projectState->import($document);

        $this->assertTrue($applied['applied']);

        $this->assertDatabaseHas('webinar_schedule_profiles', [
            'id' => 400,
            'key' => 'default',
            'name' => 'Production webinar schedule',
            'is_customized' => true,
        ]);
        $this->assertDatabaseHas('webinar_schedule_profile_items', [
            'id' => 401,
            'webinar_schedule_profile_id' => 400,
            'key' => 'confirmation_email',
            'label' => 'Production confirmation email',
            'is_customized' => true,
        ]);
        $this->assertDatabaseHas('webinar_schedule_profile_chain_bindings', [
            'id' => 402,
            'webinar_schedule_profile_id' => 400,
            'message_chain_id' => 220,
            'message_area_key' => 'confirmation',
            'dispatch_key' => 'webinar_registration_confirmation',
        ]);

        $this->assertDatabaseHas('webinar_series', [
            'id' => 310,
            'slug' => 'production-series',
            'webinar_schedule_profile_id' => 400,
        ]);
        $this->assertDatabaseHas('webinars', [
            'id' => 320,
            'webinar_series_id' => 310,
            'webinar_schedule_profile_id' => 400,
            'slug' => 'production-webinar-original',
        ]);
        $this->assertDatabaseHas('webinars', [
            'id' => 321,
            'replacement_of_webinar_id' => 320,
            'slug' => 'production-webinar-replacement',
        ]);
        $this->assertDatabaseHas('webinar_registrations', [
            'id' => 330,
            'contact_id' => 60,
            'webinar_id' => 320,
            'join_token' => 'production-original-token',
        ]);
        $this->assertDatabaseHas('webinar_registrations', [
            'id' => 331,
            'webinar_id' => 321,
            'replacement_of_registration_id' => 330,
            'join_token' => 'production-replacement-token',
        ]);
        $this->assertDatabaseHas('webinar_registration_responses', [
            'id' => 340,
            'webinar_registration_id' => 330,
            'question_key' => 'buying_timeline',
            'answer_key' => 'within_90_days',
        ]);
        $this->assertDatabaseHas('webinar_waitlist_signups', [
            'id' => 350,
            'contact_id' => 60,
            'webinar_series_id' => 310,
        ]);
        $this->assertDatabaseHas('webinar_series_message_chain_bindings', [
            'id' => 351,
            'webinar_series_id' => 310,
            'message_chain_id' => 220,
            'message_area_key' => 'confirmation',
        ]);

        $registrationMeta = json_decode(
            (string) DB::table('webinar_registrations')
                ->where('id', 330)
                ->value('meta'),
            true,
        );

        $this->assertSame(
            'paused',
            data_get($registrationMeta, 'registration_finalization.status'),
        );
        $this->assertSame(
            'submitting',
            data_get($registrationMeta, 'provider_sync.status'),
        );

        $this->assertDatabaseHas('message_chain_enrollments', [
            'id' => 140,
            'message_chain_version_id' => 221,
            'recipient_id' => 60,
            'context_type' => 'App\\Modules\\Webinars\\Models\\WebinarRegistration',
            'context_id' => 330,
            'origin_type' => 'App\\Modules\\Webinars\\Models\\Webinar',
            'origin_id' => 320,
            'current_message_chain_step_id' => 222,
            'status' => 'paused',
        ]);
        $this->assertDatabaseHas('scheduled_messages', [
            'id' => 150,
            'context_type' => 'App\\Modules\\Webinars\\Models\\WebinarRegistration',
            'context_id' => 330,
            'message_template_version_id' => 211,
            'message_chain_enrollment_id' => 140,
            'message_chain_step_variant_id' => 223,
            'status' => 'paused',
        ]);
        $this->assertDatabaseHas('contact_permission_invitations', [
            'id' => 155,
            'context_type' => 'App\\Modules\\Webinars\\Models\\WebinarRegistration',
            'context_id' => 330,
            'scheduled_message_id' => 150,
        ]);
        $this->assertDatabaseHas('project_state_resume_items', [
            'category' => 'webinar_finalizations',
            'source_table' => 'webinar_registrations',
            'source_record_id' => '330',
            'original_status' => 'processing',
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
        $resume->resume(ProjectStateResumeManager::CATEGORY_MESSAGE_CHAINS);
        $resume->resume(ProjectStateResumeManager::CATEGORY_WEBINAR_FINALIZATIONS);

        $resumedRegistrationMeta = json_decode(
            (string) DB::table('webinar_registrations')
                ->where('id', 330)
                ->value('meta'),
            true,
        );

        $this->assertSame(
            'queued',
            data_get($resumedRegistrationMeta, 'registration_finalization.status'),
        );
        $this->assertNotNull(
            data_get($resumedRegistrationMeta, 'registration_finalization.queue_token'),
        );
        $this->assertDatabaseMissing('project_state_resume_items', [
            'category' => 'webinar_finalizations',
            'state' => 'pending',
        ]);

        Queue::assertPushed(SyncWebinarRegistrationToProviderJob::class, 1);
    }

    public function test_historical_orphaned_webinar_polymorphic_reference_is_warned_and_preserved(): void
    {
        $this->seedSourceState();

        $projectState = app(ProjectStateManager::class);
        $document = $projectState->export();
        unset($document['checksum']);

        $document['sections']['messaging']['tables']['message_chain_enrollments'][0]['context_id'] = 999999;

        $this->prepareFreshPresetSyncedTarget();

        $report = $projectState->validate($document);

        $this->assertTrue($report['valid']);
        $this->assertStringContainsString(
            'Historical polymorphic reference [message_chain_enrollments.context_id]',
            implode(' ', $report['warnings']),
        );

        $projectState->import($document);

        $this->assertDatabaseHas('message_chain_enrollments', [
            'id' => 140,
            'context_type' => 'App\\Modules\\Webinars\\Models\\WebinarRegistration',
            'context_id' => 999999,
            'status' => 'paused',
        ]);
    }

    private function seedSourceState(): void
    {
        $now = now()->startOfSecond();

        $this->insertContact($now);
        $this->insertMessagingDefinitions($now, 110, 111, 120, 121, 122, 123);
        $this->insertWebinarScheduleDefinitions($now, 300, 301, 302, 120);

        DB::table('webinar_series')->insert([
            'id' => 310,
            'slug' => 'production-series',
            'title' => 'Production Webinar Series',
            'platform' => 'zoom',
            'provider_event_type' => 'webinar',
            'status' => 'active',
            'webinar_schedule_profile_id' => 300,
            'meta' => json_encode(['owner' => 'production']),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('webinars')->insert([
            $this->webinarRow(
                id: 320,
                slug: 'production-webinar-original',
                replacementOf: null,
                now: $now,
            ),
            $this->webinarRow(
                id: 321,
                slug: 'production-webinar-replacement',
                replacementOf: 320,
                now: $now,
            ),
        ]);

        DB::table('webinar_registrations')->insert([
            [
                'id' => 330,
                'contact_id' => 60,
                'webinar_id' => 320,
                'replacement_of_registration_id' => null,
                'join_token' => 'production-original-token',
                'webinar_slug' => 'production-webinar-original',
                'status' => 'registered',
                'source' => 'webinar_subdomain',
                'meta' => json_encode([
                    'registration_finalization' => [
                        'status' => 'processing',
                        'mode' => 'registration',
                        'last_attempted_at' => $now->toISOString(),
                    ],
                    'provider_sync' => [
                        'status' => 'submitting',
                        'provider' => 'zoom',
                        'submission_started_at' => $now->toISOString(),
                    ],
                ]),
                'registered_at' => $now,
                'attended_at' => null,
                'cancelled_at' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'id' => 331,
                'contact_id' => 60,
                'webinar_id' => 321,
                'replacement_of_registration_id' => 330,
                'join_token' => 'production-replacement-token',
                'webinar_slug' => 'production-webinar-replacement',
                'status' => 'registered',
                'source' => 'occurrence_replacement',
                'meta' => json_encode([
                    'registration_finalization' => [
                        'status' => 'completed',
                        'mode' => 'replacement_reprovisioning',
                    ],
                ]),
                'registered_at' => $now,
                'attended_at' => null,
                'cancelled_at' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);

        DB::table('webinar_registration_responses')->insert([
            'id' => 340,
            'webinar_registration_id' => 330,
            'question_key' => 'buying_timeline',
            'question_label' => 'When are you planning to buy?',
            'question_type' => 'single_select',
            'answer_key' => 'within_90_days',
            'answer_label' => 'Within 90 days',
            'answer_text' => null,
            'definition_version' => '2026_08',
            'sort_order' => 10,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('webinar_waitlist_signups')->insert([
            'id' => 350,
            'contact_id' => 60,
            'webinar_series_id' => 310,
            'notified_at' => null,
            'source_page' => 'notify_me',
            'meta' => json_encode(['owner' => 'production']),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('webinar_series_message_chain_bindings')->insert([
            'id' => 351,
            'webinar_series_id' => 310,
            'key' => 'registration',
            'message_area_key' => 'confirmation',
            'message_chain_id' => 120,
            'dispatch_key' => 'webinar_registration_confirmation',
            'surface' => 'registration',
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('message_chain_enrollments')->insert([
            'id' => 140,
            'message_chain_version_id' => 121,
            'recipient_type' => 'App\\Modules\\Core\\Models\\Contact',
            'recipient_id' => 60,
            'context_type' => 'App\\Modules\\Webinars\\Models\\WebinarRegistration',
            'context_id' => 330,
            'origin_type' => 'App\\Modules\\Webinars\\Models\\Webinar',
            'origin_id' => 320,
            'surface' => 'registration',
            'current_message_chain_step_id' => 122,
            'next_action_at' => $now->copy()->addHour(),
            'status' => 'active',
            'dedupe_key' => 'webinar-project-state-enrollment',
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
            'id' => 150,
            'recipient_type' => 'App\\Modules\\Core\\Models\\Contact',
            'recipient_id' => 60,
            'context_type' => 'App\\Modules\\Webinars\\Models\\WebinarRegistration',
            'context_id' => 330,
            'behavior_owner_type' => null,
            'behavior_owner_id' => null,
            'channel' => 'email',
            'message_type' => 'confirmation',
            'purpose' => 'transactional',
            'scope' => 'webinar',
            'payload_class' => 'App\\Modules\\Messaging\\Payloads\\EmailPayload',
            'queue' => 'confirmation_messages',
            'dispatch_keys' => json_encode(['webinar_registration_confirmation']),
            'definition_config_path' => 'messaging.email.definitions.transactional.webinar.confirmation',
            'payload' => json_encode([
                'to' => 'contact@example.com',
                'subject' => 'Production webinar confirmation',
            ]),
            'send_at' => $now->copy()->addHour(),
            'status' => 'pending',
            'provider_idempotency_key' => 'webinar-project-state-provider',
            'dedupe_key' => 'webinar-project-state-message',
            'meta' => json_encode(['owner' => 'production']),
            'created_at' => $now,
            'updated_at' => $now,
            'message_template_version_id' => 111,
            'message_chain_enrollment_id' => 140,
            'message_chain_step_variant_id' => 123,
        ]);

        DB::table('contact_permission_invitations')->insert([
            'id' => 155,
            'contact_id' => 60,
            'scheduled_message_id' => 150,
            'token' => 'webinar-project-state-permission-token',
            'context_type' => 'App\\Modules\\Webinars\\Models\\WebinarRegistration',
            'context_id' => 330,
            'channel' => 'email',
            'source' => 'webinar_registration',
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
    }

    private function prepareFreshPresetSyncedTarget(): void
    {
        DB::table('contact_permission_invitations')->delete();
        DB::table('scheduled_messages')->delete();
        DB::table('message_chain_enrollments')->delete();

        DB::table('webinar_series_message_chain_bindings')->delete();
        DB::table('webinar_schedule_profile_chain_bindings')->delete();
        DB::table('webinar_registration_responses')->delete();
        DB::table('webinar_waitlist_signups')->delete();
        DB::table('webinar_registrations')->delete();
        DB::table('webinars')->delete();
        DB::table('webinar_series')->delete();
        DB::table('webinar_schedule_profile_items')->delete();
        DB::table('webinar_schedule_profiles')->delete();

        DB::table('message_chain_step_variants')->delete();
        DB::table('message_chain_steps')->delete();
        DB::table('message_chains')->update(['current_version_id' => null]);
        DB::table('message_chain_versions')->delete();
        DB::table('message_chains')->delete();

        DB::table('message_templates')->update(['current_version_id' => null]);
        DB::table('message_template_versions')->delete();
        DB::table('message_templates')->delete();

        DB::table('contacts')->delete();

        $now = now()->startOfSecond();

        $this->insertMessagingDefinitions($now, 210, 211, 220, 221, 222, 223);
        $this->insertWebinarScheduleDefinitions($now, 400, 401, 402, 220);

        DB::table('webinar_schedule_profiles')
            ->where('id', 400)
            ->update([
                'name' => 'Fresh preset webinar schedule',
                'description' => null,
                'is_customized' => false,
                'customized_at' => null,
                'source_version' => 2,
                'updated_at' => $now,
            ]);

        DB::table('webinar_schedule_profile_items')
            ->where('id', 401)
            ->update([
                'label' => 'Fresh preset confirmation email',
                'is_customized' => false,
                'customized_at' => null,
                'updated_at' => $now,
            ]);
    }

    private function insertContact(mixed $now): void
    {
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
    }

    private function insertMessagingDefinitions(
        mixed $now,
        int $templateId,
        int $templateVersionId,
        int $chainId,
        int $chainVersionId,
        int $stepId,
        int $variantId,
    ): void {
        DB::table('message_templates')->insert([
            'id' => $templateId,
            'key' => 'email.transactional.webinar.confirmation',
            'name' => 'Production webinar confirmation',
            'description' => 'Production webinar confirmation template.',
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
            'id' => $templateVersionId,
            'message_template_id' => $templateId,
            'version' => 1,
            'subject' => 'Production webinar confirmation',
            'content' => json_encode(['body' => ['Production webinar body']]),
            'renderer_key' => 'email_blade',
            'renderer_version' => '1',
            'content_hash' => str_repeat('a', 64),
            'created_by' => null,
            'created_at' => $now,
        ]);

        DB::table('message_templates')
            ->where('id', $templateId)
            ->update(['current_version_id' => $templateVersionId]);

        DB::table('message_chains')->insert([
            'id' => $chainId,
            'key' => 'webinar.schedule_profile.default.registration',
            'name' => 'Production webinar registration chain',
            'description' => 'Production webinar registration chain.',
            'status' => 'active',
            'current_version_id' => null,
            'source' => 'webinar_schedule_profile',
            'source_version' => '1',
            'is_customized' => true,
            'customized_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('message_chain_versions')->insert([
            'id' => $chainVersionId,
            'message_chain_id' => $chainId,
            'version' => 1,
            'exit_conditions' => json_encode([]),
            'content_hash' => str_repeat('b', 64),
            'published_at' => $now,
            'created_by' => null,
            'created_at' => $now,
        ]);

        DB::table('message_chains')
            ->where('id', $chainId)
            ->update(['current_version_id' => $chainVersionId]);

        DB::table('message_chain_steps')->insert([
            'id' => $stepId,
            'message_chain_version_id' => $chainVersionId,
            'key' => 'confirmation_email',
            'name' => 'Confirmation email',
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
            'id' => $variantId,
            'message_chain_step_id' => $stepId,
            'key' => 'email',
            'sort_order' => 10,
            'message_template_version_id' => $templateVersionId,
            'channel' => 'email',
            'purpose' => 'transactional',
            'scope' => 'webinar',
            'message_type' => 'confirmation',
            'queue' => 'confirmation_messages',
            'dependency_policy' => json_encode([]),
            'conditions' => json_encode([]),
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function insertWebinarScheduleDefinitions(
        mixed $now,
        int $profileId,
        int $itemId,
        int $bindingId,
        int $chainId,
    ): void {
        DB::table('webinar_schedule_profiles')->insert([
            'id' => $profileId,
            'key' => 'default',
            'name' => 'Production webinar schedule',
            'description' => 'Customized production webinar schedule.',
            'message_template_set_key' => 'default',
            'status' => 'active',
            'is_default' => true,
            'is_active' => true,
            'is_customized' => true,
            'customized_at' => $now,
            'source' => 'config',
            'source_config_path' => 'webinars.schedule_profiles.default',
            'source_version' => 1,
            'last_synced_at' => $now,
            'meta' => json_encode(['owner' => 'production']),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('webinar_schedule_profile_items')->insert([
            'id' => $itemId,
            'webinar_schedule_profile_id' => $profileId,
            'key' => 'confirmation_email',
            'label' => 'Production confirmation email',
            'context_key' => 'confirmation',
            'channel' => 'email',
            'purpose' => 'transactional',
            'scope' => 'webinar',
            'surface' => 'registration',
            'message_type' => 'confirmation',
            'dispatch_key' => 'webinar_registration_confirmation',
            'message_template_key' => 'email.transactional.webinar.confirmation',
            'source_config_path' => 'webinars.schedule_profiles.default.items.0',
            'is_enabled' => true,
            'is_active' => true,
            'is_customized' => true,
            'customized_at' => $now,
            'sort_order' => 10,
            'timing' => 'immediate',
            'schedule' => json_encode([]),
            'conditions' => json_encode([]),
            'meta' => json_encode(['owner' => 'production']),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('webinar_schedule_profile_chain_bindings')->insert([
            'id' => $bindingId,
            'webinar_schedule_profile_id' => $profileId,
            'key' => 'registration',
            'message_area_key' => 'confirmation',
            'message_chain_id' => $chainId,
            'dispatch_key' => 'webinar_registration_confirmation',
            'surface' => 'registration',
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function webinarRow(
        int $id,
        string $slug,
        ?int $replacementOf,
        mixed $now,
    ): array {
        return [
            'id' => $id,
            'webinar_series_id' => 310,
            'replacement_of_webinar_id' => $replacementOf,
            'webinar_schedule_profile_id' => 300,
            'title' => $replacementOf === null
                ? 'Production Webinar'
                : 'Production Webinar Replacement',
            'slug' => $slug,
            'platform' => 'zoom',
            'provider_event_type' => 'webinar',
            'external_id' => 'zoom-'.$id,
            'host_account_key' => 'primary',
            'join_url' => 'https://example.test/join/'.$id,
            'registration_url' => 'https://example.test/register/'.$id,
            'playback_token' => 'playback-'.$id,
            'playback_url' => null,
            'playback_passcode' => null,
            'starts_at' => $now->copy()->addDays($id === 320 ? 3 : 10),
            'ends_at' => $now->copy()->addDays($id === 320 ? 3 : 10)->addHour(),
            'timezone' => 'America/Chicago',
            'description' => 'Production webinar occurrence.',
            'meta' => json_encode(['owner' => 'production']),
            'provider_settings' => json_encode(['registration_required' => true]),
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }
}