<?php

namespace Tests\Feature\Campaigns;

use App\Http\Middleware\ForceStagingAccess;
use App\Models\User;
use App\Modules\Campaigns\Actions\PublishCampaignMessageChainVersionAction;
use App\Modules\Campaigns\Actions\RecordPriorCampaignMessageReceiptAction;
use App\Modules\Campaigns\Actions\StartCompletedCampaignAppendAction;
use App\Modules\Campaigns\Jobs\ProcessCompletedCampaignAppendChunkJob;
use App\Modules\Campaigns\Models\Campaign;
use App\Modules\Campaigns\Models\CampaignEnrollment;
use App\Modules\Campaigns\Models\CampaignMessageChainAppend;
use App\Modules\Campaigns\Models\CampaignPriorMessageReceipt;
use App\Modules\Core\Models\Contact;
use App\Modules\Messaging\Actions\ProcessMessageChainEnrollmentAction;
use App\Modules\Messaging\Actions\PublishMessageChainVersionAction;
use App\Modules\Messaging\Actions\PublishMessageTemplateVersionAction;
use App\Modules\Messaging\Models\MessageChain;
use App\Modules\Messaging\Models\MessageChainEnrollment;
use App\Modules\Messaging\Models\MessageChainStep;
use App\Modules\Messaging\Models\MessageChainVersion;
use App\Modules\Messaging\Models\MessageConsent;
use App\Modules\Messaging\Models\MessageTemplate;
use App\Modules\Messaging\Models\MessageTemplateCatalogEntry;
use App\Modules\Messaging\Models\MessageTemplatePreset;
use App\Modules\Messaging\Models\MessageTemplateVersion;
use App\Modules\Messaging\Models\ScheduledMessage;
use App\Modules\Messaging\Payloads\EmailPayload;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class CampaignAppendContinuationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('modules.enabled', ['campaigns', 'messaging']);
        $this->withoutMiddleware(ForceStagingAccess::class);
        Carbon::setTestNow('2026-09-28 12:00:00 UTC');
        Queue::fake();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_active_enrollment_finishes_its_original_scheduled_wave_then_receives_the_appended_step(): void
    {
        [$campaign, $oldVersion, $preset] = $this->campaign();
        $enrollment = $this->enrollment($campaign, $oldVersion);
        $completed = $this->enrollment($campaign, $oldVersion);
        $completed->forceFill([
            'status' => MessageChainEnrollment::STATUS_COMPLETED,
            'current_message_chain_step_id' => null,
            'next_action_at' => null,
            'completed_at' => now(),
        ])->save();
        $processor = app(ProcessMessageChainEnrollmentAction::class);
        $processor->handle($enrollment);
        $originalMessage = $enrollment->scheduledMessages()->firstOrFail();

        $newVersion = $this->append($campaign, $oldVersion, $preset, true);

        $this->assertNotSame($oldVersion->getKey(), $newVersion->getKey());
        $this->assertSame($oldVersion->getKey(), $enrollment->fresh()->message_chain_version_id);
        $this->assertSame($oldVersion->steps()->firstOrFail()->getKey(),
            $originalMessage->messageChainStepVariant->message_chain_step_id);
        $this->assertSame(1, CampaignMessageChainAppend::query()->count());
        $this->assertSame(MessageChainEnrollment::STATUS_COMPLETED, $completed->fresh()->status);

        $originalMessage->forceFill(['status' => ScheduledMessage::STATUS_SENT])->save();
        $processor->handleTerminal($originalMessage);

        $advanced = $enrollment->fresh();
        $this->assertSame($newVersion->getKey(), $advanced->message_chain_version_id);
        $this->assertSame('step_2', $advanced->currentMessageChainStep?->key);
        $this->assertSame(MessageChainEnrollment::STATUS_ACTIVE, $advanced->status);
        $this->assertSame(1, $advanced->scheduledMessages()->count());
        $this->assertSame(now()->addDays(14)->timestamp, $advanced->next_action_at?->timestamp);

        $this->travelTo($advanced->next_action_at->copy()->addSecond());
        $processor->handle($advanced);
        $this->assertSame(2, $advanced->scheduledMessages()->count());
        $appendedMessage = $advanced->latestScheduledMessage()->firstOrFail();
        $this->assertSame('step_2', $appendedMessage->messageChainStepVariant->messageChainStep->key);
        $this->travelBack();
    }

    public function test_a_prior_receipt_still_skips_the_appended_message(): void
    {
        [$campaign, $oldVersion, $preset] = $this->campaign();
        $enrollment = $this->enrollment($campaign, $oldVersion);
        $processor = app(ProcessMessageChainEnrollmentAction::class);
        $processor->handle($enrollment);
        $message = $enrollment->scheduledMessages()->firstOrFail();
        $this->append($campaign, $oldVersion, $preset, true);

        app(RecordPriorCampaignMessageReceiptAction::class)->handle(
            $enrollment->recipient,
            $campaign,
            'step_2',
            CampaignPriorMessageReceipt::SOURCE_OPERATOR_ATTESTATION,
            attestedBy: User::factory()->create(),
        );

        $message->forceFill(['status' => ScheduledMessage::STATUS_SENT])->save();
        $processor->handleTerminal($message);
        $due = $enrollment->fresh()->next_action_at;
        $this->travelTo($due->copy()->addSecond());
        $processor->handle($enrollment);

        $this->assertSame(MessageChainEnrollment::STATUS_COMPLETED, $enrollment->fresh()->status);
        $this->assertSame(1, $enrollment->scheduledMessages()->count());
        $this->travelBack();
    }

    public function test_regular_publication_keeps_old_enrollments_pinned_and_completed_contacts_completed(): void
    {
        [$campaign, $oldVersion, $preset] = $this->campaign();
        $active = $this->enrollment($campaign, $oldVersion);
        $completed = $this->enrollment($campaign, $oldVersion);
        $completed->forceFill([
            'status' => MessageChainEnrollment::STATUS_COMPLETED,
            'current_message_chain_step_id' => null,
            'next_action_at' => null,
            'completed_at' => now(),
        ])->save();

        $this->append($campaign, $oldVersion, $preset, false);

        $this->assertSame(0, CampaignMessageChainAppend::query()->count());
        $processor = app(ProcessMessageChainEnrollmentAction::class);
        $processor->handle($active);
        $message = $active->scheduledMessages()->firstOrFail();
        $message->forceFill(['status' => ScheduledMessage::STATUS_SENT])->save();
        $processor->handleTerminal($message);

        $this->assertSame(MessageChainEnrollment::STATUS_COMPLETED, $active->fresh()->status);
        $this->assertSame($oldVersion->getKey(), $active->fresh()->message_chain_version_id);
        $this->assertSame(MessageChainEnrollment::STATUS_COMPLETED, $completed->fresh()->status);
    }

    public function test_operator_can_start_previously_completed_contacts_at_only_the_appended_step(): void
    {
        [$campaign, $oldVersion, $preset] = $this->campaign();
        $original = $this->enrollment($campaign, $oldVersion);
        $original->forceFill([
            'status' => MessageChainEnrollment::STATUS_COMPLETED,
            'current_message_chain_step_id' => null,
            'next_action_at' => null,
            'completed_at' => now()->subMinute(),
        ])->save();
        $newVersion = $this->append($campaign, $oldVersion, $preset, true);
        $append = CampaignMessageChainAppend::query()->firstOrFail();

        $this->assertSame(1, app(StartCompletedCampaignAppendAction::class)->prompt($campaign)['count']);
        $this->actingAs(User::factory()->create())
            ->post(route('crm.campaigns.completed-append.start', $campaign), [
                'append_id' => $append->getKey(),
            ])
            ->assertSessionHasNoErrors();
        Queue::assertPushed(ProcessCompletedCampaignAppendChunkJob::class);

        $action = app(StartCompletedCampaignAppendAction::class);
        $action->processChunk((int) $append->getKey(), 0);
        $action->processChunk((int) $append->getKey(), 0);

        $this->assertSame(2, CampaignEnrollment::query()
            ->where('contact_id', $original->recipient_id)->count());
        $this->assertSame(MessageChainEnrollment::STATUS_COMPLETED, $original->fresh()->status);
        $new = CampaignEnrollment::query()
            ->where('contact_id', $original->recipient_id)
            ->orderByDesc('id')
            ->firstOrFail()->messageChainEnrollment;
        $this->assertSame($newVersion->getKey(), $new->message_chain_version_id);
        $this->assertSame('step_2', $new->currentMessageChainStep->key);
        $this->assertSame(now()->addDays(14)->timestamp, $new->next_action_at?->timestamp);
        $this->assertSame(0, $new->scheduledMessages()->count());
        $this->assertNull($action->prompt($campaign));
    }

    public function test_completed_append_excludes_revoked_prior_receipts_and_noncompleted_enrollments(): void
    {
        [$campaign, $oldVersion, $preset] = $this->campaign();
        $revoked = $this->enrollment($campaign, $oldVersion);
        $prior = $this->enrollment($campaign, $oldVersion);
        $exited = $this->enrollment($campaign, $oldVersion);

        foreach ([$revoked, $prior, $exited] as $enrollment) {
            $enrollment->forceFill([
                'status' => MessageChainEnrollment::STATUS_COMPLETED,
                'current_message_chain_step_id' => null,
                'next_action_at' => null,
                'completed_at' => now()->subMinute(),
            ])->save();
        }

        $exited->forceFill(['status' => MessageChainEnrollment::STATUS_EXITED])->save();
        MessageConsent::query()->where('contact_id', $revoked->recipient_id)->delete();
        $this->append($campaign, $oldVersion, $preset, true);
        app(RecordPriorCampaignMessageReceiptAction::class)->handle(
            $prior->recipient,
            $campaign,
            'step_2',
            CampaignPriorMessageReceipt::SOURCE_OPERATOR_ATTESTATION,
            attestedBy: User::factory()->create(),
        );

        $append = CampaignMessageChainAppend::query()->firstOrFail();
        $action = app(StartCompletedCampaignAppendAction::class);
        $this->assertSame(2, $action->prompt($campaign)['count']);
        $action->processChunk((int) $append->getKey(), 0);

        $this->assertSame(3, CampaignEnrollment::query()->count());
        $this->assertSame(MessageChainEnrollment::STATUS_EXITED, $exited->fresh()->status);
    }

    public function test_completed_append_rejects_a_stale_schedule_confirmation(): void
    {
        [$campaign, $oldVersion, $preset] = $this->campaign();
        $this->append($campaign, $oldVersion, $preset, true);
        $append = CampaignMessageChainAppend::query()->firstOrFail();
        $campaign->messageChain()->firstOrFail()
            ->forceFill(['current_version_id' => $oldVersion->getKey()])->save();

        $this->actingAs(User::factory()->create())
            ->post(route('crm.campaigns.completed-append.start', $campaign), [
                'append_id' => $append->getKey(),
            ])
            ->assertSessionHasErrors(['append_id']);
        Queue::assertNotPushed(ProcessCompletedCampaignAppendChunkJob::class);
    }

    public function test_completed_append_respects_current_automatic_eligibility(): void
    {
        [$campaign, $oldVersion, $preset] = $this->campaign();
        $original = $this->enrollment($campaign, $oldVersion);
        $original->forceFill([
            'status' => MessageChainEnrollment::STATUS_COMPLETED,
            'completed_at' => now()->subMinute(),
        ])->save();
        $this->append($campaign, $oldVersion, $preset, true);
        $campaign->forceFill([
            'enrollment_mode' => Campaign::ENROLLMENT_MODE_AUTOMATIC,
            'eligibility_filter' => [],
        ])->save();

        app(StartCompletedCampaignAppendAction::class)->processChunk(
            (int) CampaignMessageChainAppend::query()->firstOrFail()->getKey(),
            0,
        );

        $this->assertSame(1, CampaignEnrollment::query()->count());
        $this->assertSame(MessageChainEnrollment::STATUS_COMPLETED, $original->fresh()->status);
    }

    public function test_opted_in_publication_rejects_changes_to_existing_steps_atomically(): void
    {
        [$campaign, $oldVersion, $preset] = $this->campaign();

        try {
            app(PublishCampaignMessageChainVersionAction::class)->replaceSchedule(
                campaign: $campaign,
                expectedVersionId: (int) $oldVersion->getKey(),
                submittedSteps: [[
                    'key' => 'step_1',
                    'name' => 'Changed old message',
                    'position' => 1,
                    'timing_type' => MessageChainStep::TIMING_IMMEDIATE,
                ]],
                newStep: $this->newStep($preset),
                extendInProgress: true,
            );
            $this->fail('The append must reject changes to the original step.');
        } catch (ValidationException) {
            $this->assertSame($oldVersion->getKey(),
                $campaign->messageChain()->firstOrFail()->current_version_id);
            $this->assertSame(1, $campaign->messageChain()->firstOrFail()->versions()->count());
            $this->assertSame(0, CampaignMessageChainAppend::query()->count());
        }
    }

    public function test_schedule_form_can_opt_current_participants_into_a_pure_append(): void
    {
        [$campaign, $oldVersion, $preset] = $this->campaign();

        $this->actingAs(User::factory()->create())
            ->patch(route('crm.campaigns.schedule.update', $campaign), [
                'campaign_editor' => 'schedule',
                'message_chain_version_id' => $oldVersion->getKey(),
                'steps' => [[
                    'key' => 'step_1',
                    'name' => 'First message',
                    'position' => 1,
                    'timing_type' => MessageChainStep::TIMING_IMMEDIATE,
                ]],
                'new_step' => ['add' => 1] + $this->newStep($preset),
                'extend_in_progress' => 1,
            ])
            ->assertRedirect(route('crm.campaigns.edit', [
                'campaign' => $campaign,
                'panel' => 'schedule',
            ]));

        $this->assertSame($oldVersion->getKey(),
            CampaignMessageChainAppend::query()->firstOrFail()->from_message_chain_version_id);
    }

    public function test_schedule_form_creates_a_reusable_email_message_and_appends_it_in_one_submission(): void
    {
        [$campaign, $oldVersion] = $this->campaign();
        $before = MessageTemplatePreset::query()->count();

        $this->actingAs(User::factory()->create())
            ->patch(route('crm.campaigns.schedule.update', $campaign),
                $this->newMessageSubmission($oldVersion, 'email', [
                    'subject' => 'Next follow-up',
                    'body' => 'Here is the next step.',
                ]))
            ->assertSessionHasNoErrors();

        $this->assertSame($before + 1, MessageTemplatePreset::query()->count());
        $version = $campaign->messageChain()->firstOrFail()->requireCurrentVersion();
        $step = $version->steps()->reorder()->orderByDesc('sort_order')->firstOrFail();
        $templateVersion = $step->variants()->firstOrFail()->messageTemplateVersion;
        $this->assertSame('step_2', $step->key);
        $this->assertSame('Next follow-up', $templateVersion->payload()['subject']);
        $this->assertSame('Here is the next step.', $templateVersion->payload()['body']);

        $preset = MessageTemplatePreset::query()->where(
            'key', $templateVersion->messageTemplate->key,
        )->firstOrFail();
        $this->assertTrue($preset->catalogEntries()->where('module_key', 'campaigns')
            ->where('context_id', $campaign->getKey())->exists());
        $this->assertSame(1, CampaignMessageChainAppend::query()->count());
    }

    public function test_schedule_form_can_create_an_sms_message(): void
    {
        [$campaign, $oldVersion] = $this->campaign();

        $this->actingAs(User::factory()->create())
            ->patch(route('crm.campaigns.schedule.update', $campaign),
                $this->newMessageSubmission($oldVersion, 'sms', [
                    'message' => 'Checking in about your next move.',
                ]))
            ->assertSessionHasNoErrors();

        $step = $campaign->messageChain()->firstOrFail()
            ->requireCurrentVersion()->steps()->reorder()->orderByDesc('sort_order')->firstOrFail();
        $variant = $step->variants()->firstOrFail();
        $this->assertSame('sms', $variant->channel);
        $this->assertSame('Checking in about your next move.',
            $variant->messageTemplateVersion->payload()['message']);
    }

    public function test_invalid_append_rolls_back_new_message_template_creation(): void
    {
        [$campaign, $oldVersion] = $this->campaign();
        $before = MessageTemplatePreset::query()->count();
        $submission = $this->newMessageSubmission($oldVersion, 'email', [
            'subject' => 'Should roll back',
            'body' => 'This message must not remain in the catalog.',
        ]);
        $submission['steps'][0]['name'] = 'Changed first message';

        $this->actingAs(User::factory()->create())
            ->patch(route('crm.campaigns.schedule.update', $campaign), $submission)
            ->assertSessionHasErrors(['extend_in_progress']);

        $this->assertSame($before, MessageTemplatePreset::query()->count());
        $this->assertSame($oldVersion->getKey(),
            $campaign->messageChain()->firstOrFail()->current_version_id);
        $this->assertSame(0, CampaignMessageChainAppend::query()->count());
    }

    /** @return array{Campaign, MessageChainVersion, MessageTemplatePreset} */
    private function campaign(): array
    {
        [$firstPreset, $firstVersion] = $this->message('fixture.append.first', 'First message');
        [$secondPreset] = $this->message('fixture.append.second', 'Appended message');
        $chain = MessageChain::query()->create([
            'key' => 'campaign.append_fixture',
            'name' => 'Append fixture',
            'status' => MessageChain::STATUS_ACTIVE,
        ]);
        $version = app(PublishMessageChainVersionAction::class)->handle(
            messageChain: $chain,
            steps: [[
                'key' => 'step_1',
                'name' => 'First message',
                'sort_order' => 10,
                'timing_type' => MessageChainStep::TIMING_IMMEDIATE,
                'variants' => [[
                    'key' => 'email',
                    'message_template_version_id' => $firstVersion->getKey(),
                    'channel' => 'email',
                    'purpose' => 'marketing',
                    'scope' => 'fixture',
                    'message_type' => $firstPreset->message_type,
                ]],
            ]],
        );
        $campaign = Campaign::factory()->create([
            'key' => 'append_fixture',
            'message_chain_id' => $chain->getKey(),
            'status' => Campaign::STATUS_ACTIVE,
        ]);

        return [$campaign, $version, $secondPreset];
    }

    /** @return array{MessageTemplatePreset, MessageTemplateVersion} */
    private function message(string $key, string $name): array
    {
        $preset = MessageTemplatePreset::factory()->create([
            'key' => $key,
            'name' => $name,
            'channel' => 'email',
            'purpose' => 'marketing',
            'scope' => 'fixture',
            'message_type' => str_replace('.', '_', $key),
            'payload_class' => EmailPayload::class,
            'queue' => 'marketing',
            'payload' => ['subject' => $name, 'body' => $name.' body.'],
        ]);
        MessageTemplateCatalogEntry::factory()->forPreset($preset)->create([
            'channel' => 'email',
            'purpose' => 'marketing',
            'scope' => 'fixture',
            'module_key' => 'campaigns',
            'module_label' => 'Campaigns',
            'surface' => 'campaigns',
            'group_key' => 'campaign:append_fixture',
            'group_label' => 'Append fixture',
            'item_key' => $key,
            'item_label' => $name,
            'usage_type' => 'campaign_step',
            'is_active' => true,
        ]);
        $template = MessageTemplate::query()->create([
            'key' => $key,
            'name' => $name,
            'channel' => 'email',
            'status' => MessageTemplate::STATUS_ACTIVE,
        ]);
        $version = app(PublishMessageTemplateVersionAction::class)->handle(
            messageTemplate: $template,
            payload: $preset->payload,
        );

        return [$preset, $version];
    }

    private function enrollment(Campaign $campaign, MessageChainVersion $version): MessageChainEnrollment
    {
        $contact = Contact::factory()->create();
        MessageConsent::query()->create([
            'contact_id' => $contact->getKey(),
            'channel' => 'email',
            'purpose' => 'marketing',
            'scope' => 'fixture',
            'consented_at' => now()->subMinute(),
            'source' => 'test',
        ]);
        $wrapper = CampaignEnrollment::query()->create([
            'contact_id' => $contact->getKey(),
            'campaign_id' => $campaign->getKey(),
            'campaign_key' => $campaign->key,
            'started_at' => now(),
        ]);
        $enrollment = MessageChainEnrollment::query()->create([
            'message_chain_version_id' => $version->getKey(),
            'recipient_type' => $contact->getMorphClass(),
            'recipient_id' => $contact->getKey(),
            'context_type' => $wrapper->getMorphClass(),
            'context_id' => $wrapper->getKey(),
            'origin_type' => $campaign->getMorphClass(),
            'origin_id' => $campaign->getKey(),
            'surface' => 'campaigns',
            'current_message_chain_step_id' => $version->steps()->firstOrFail()->getKey(),
            'next_action_at' => now(),
            'status' => MessageChainEnrollment::STATUS_ACTIVE,
            'dedupe_key' => 'campaign-append-'.uniqid(),
            'started_at' => now(),
        ]);
        $wrapper->forceFill(['message_chain_enrollment_id' => $enrollment->getKey()])->save();

        return $enrollment;
    }

    private function append(
        Campaign $campaign,
        MessageChainVersion $version,
        MessageTemplatePreset $preset,
        bool $extend,
    ): MessageChainVersion {
        return app(PublishCampaignMessageChainVersionAction::class)->replaceSchedule(
            campaign: $campaign,
            expectedVersionId: (int) $version->getKey(),
            submittedSteps: [[
                'key' => 'step_1',
                'name' => 'First message',
                'position' => 1,
                'timing_type' => MessageChainStep::TIMING_IMMEDIATE,
            ]],
            newStep: $this->newStep($preset),
            extendInProgress: $extend,
        );
    }

    /** @return array<string, mixed> */
    private function newStep(MessageTemplatePreset $preset): array
    {
        return [
            'message_template_preset_id' => $preset->getKey(),
            'name' => 'Appended message',
            'position' => 2,
            'timing_type' => MessageChainStep::TIMING_DELAY,
            'delay_value' => 14,
            'delay_unit' => 'days',
        ];
    }

    /**
     * @param array<string, string> $copy
     * @return array<string, mixed>
     */
    private function newMessageSubmission(
        MessageChainVersion $version,
        string $channel,
        array $copy,
    ): array {
        return [
            'campaign_editor' => 'schedule',
            'message_chain_version_id' => $version->getKey(),
            'steps' => [[
                'key' => 'step_1',
                'name' => 'First message',
                'position' => 1,
                'timing_type' => MessageChainStep::TIMING_IMMEDIATE,
            ]],
            'new_step' => [
                'add' => 1,
                'template_mode' => 'create',
                'template' => [
                    'name' => 'Campaign follow-up',
                    'channel' => $channel,
                    ...$copy,
                ],
                'position' => 2,
                'timing_type' => MessageChainStep::TIMING_DELAY,
                'delay_value' => 14,
                'delay_unit' => 'days',
            ],
            'extend_in_progress' => 1,
        ];
    }
}