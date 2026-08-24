<?php

namespace Tests\Feature\Campaigns;

use App\Modules\Campaigns\Actions\ApplyAutomaticCampaignEligibilityAction;
use App\Modules\Campaigns\Actions\ReconcileContactCampaignEligibilityAction;
use App\Modules\Campaigns\Data\CampaignEligibilityLifecycleResult;
use App\Modules\Campaigns\Models\Campaign;
use App\Modules\Core\Models\Contact;
use App\Modules\Core\Models\ContactTag;
use App\Modules\Messaging\Actions\PublishMessageChainVersionAction;
use App\Modules\Messaging\Models\MessageChain;
use App\Modules\Messaging\Models\MessageChainEnrollment;
use App\Modules\Messaging\Models\MessageChainStep;
use App\Modules\Messaging\Models\MessageTemplate;
use App\Modules\Messaging\Models\MessageTemplateVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class CampaignEligibilityFamilyReconciliationTest extends TestCase
{
    use RefreshDatabase;

    public function test_reconciliation_settles_open_family_incumbent_before_enrolling_newly_eligible_campaign(): void
    {
        Queue::fake();

        $newCandidate = $this->automaticCampaign(
            key: 'candidate_a',
            tag: 'A',
        );
        $incumbent = $this->automaticCampaign(
            key: 'candidate_b',
            tag: 'B',
        );

        $contact = Contact::factory()->create();
        $this->addTag($contact, 'B');

        $started = app(ApplyAutomaticCampaignEligibilityAction::class)->handle(
            campaign: $incumbent,
            contact: $contact,
        );

        $this->assertSame(
            CampaignEligibilityLifecycleResult::ENROLLED,
            $started->action,
        );

        $this->removeTag($contact, 'B');
        $this->addTag($contact, 'A');

        $results = app(ReconcileContactCampaignEligibilityAction::class)->handle(
            contact: $contact,
        );

        $this->assertEquals([
            CampaignEligibilityLifecycleResult::CANCELLED,
            CampaignEligibilityLifecycleResult::ENROLLED,
        ], array_map(
            static fn ($result): string => $result->action,
            $results,
        ));

        $this->assertSame(
            MessageChainEnrollment::STATUS_CANCELLED,
            $started->enrollment?->messageChainEnrollment?->refresh()->status,
        );

        $this->assertDatabaseHas('campaign_enrollments', [
            'campaign_id' => $newCandidate->getKey(),
            'contact_id' => $contact->getKey(),
        ]);

        $this->assertSame(
            1,
            \App\Modules\Campaigns\Models\CampaignEnrollment::query()
                ->where('contact_id', $contact->getKey())
                ->whereHas(
                    'messageChainEnrollment',
                    fn ($query) => $query->whereIn('status', [
                        MessageChainEnrollment::STATUS_ACTIVE,
                        MessageChainEnrollment::STATUS_PAUSED,
                    ]),
                )
                ->count(),
        );
    }

    private function automaticCampaign(
        string $key,
        string $tag,
    ): Campaign {
        $templateVersion = $this->templateVersion("fixture.{$key}.email");

        $chain = MessageChain::query()->create([
            'key' => "campaign.{$key}",
            'name' => "{$key} chain",
            'status' => MessageChain::STATUS_ACTIVE,
            'source' => 'test',
            'is_customized' => false,
        ]);

        app(PublishMessageChainVersionAction::class)->handle(
            messageChain: $chain,
            steps: [[
                'key' => 'step_1',
                'name' => 'Step 1',
                'sort_order' => 10,
                'timing_type' => MessageChainStep::TIMING_DELAY,
                'offset_seconds' => 7200,
                'variant_strategy' => MessageChainStep::VARIANT_STRATEGY_FIRST_AVAILABLE,
                'advance_policy' => MessageChainStep::ADVANCE_ALL_TERMINAL,
                'conditions' => [],
                'is_active' => true,
                'variants' => [[
                    'key' => 'email',
                    'sort_order' => 10,
                    'message_template_version_id' => $templateVersion->getKey(),
                    'channel' => 'email',
                    'purpose' => 'marketing',
                    'scope' => 'campaign_test',
                    'message_type' => "{$key}_step_1",
                    'queue' => 'marketing',
                    'dependency_policy' => [],
                    'conditions' => [],
                    'is_active' => true,
                ]],
            ]],
        );

        return Campaign::query()->create([
            'key' => $key,
            'name' => "{$key} campaign",
            'message_chain_id' => $chain->getKey(),
            'family_key' => 'consumer_nurture',
            'priority' => 10,
            'eligibility_filter' => ['tag' => [$tag]],
            'enrollment_mode' => Campaign::ENROLLMENT_MODE_AUTOMATIC,
            'reentry_policy' => Campaign::REENTRY_NEVER,
            'ineligible_behavior' => Campaign::INELIGIBLE_CANCEL,
            'channel' => 'email',
            'purpose' => 'marketing',
            'scope' => 'campaign_test',
            'status' => Campaign::STATUS_ACTIVE,
            'meta' => [],
        ]);
    }

    private function templateVersion(string $key): MessageTemplateVersion
    {
        $template = MessageTemplate::query()->create([
            'key' => $key,
            'name' => $key,
            'channel' => 'email',
            'status' => MessageTemplate::STATUS_ACTIVE,
            'source' => 'test',
            'is_customized' => false,
        ]);

        $version = MessageTemplateVersion::query()->create([
            'message_template_id' => $template->getKey(),
            'version' => 1,
            'subject' => 'Fixture subject',
            'content' => ['body' => 'Fixture body'],
            'renderer_key' => 'fixture',
            'renderer_version' => '1',
            'content_hash' => hash('sha256', $key),
        ]);

        $template->forceFill([
            'current_version_id' => $version->getKey(),
        ])->save();

        return $version;
    }

    private function addTag(Contact $contact, string $tag): void
    {
        ContactTag::withoutEvents(
            fn () => ContactTag::query()->firstOrCreate([
                'contact_id' => $contact->getKey(),
                'tag' => $tag,
            ]),
        );
    }

    private function removeTag(Contact $contact, string $tag): void
    {
        ContactTag::withoutEvents(
            fn () => ContactTag::query()
                ->where('contact_id', $contact->getKey())
                ->where('tag', $tag)
                ->delete(),
        );
    }
}