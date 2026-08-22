<?php

namespace Tests\Feature\Campaigns;

use App\Modules\Campaigns\Actions\PauseCampaignEnrollmentAction;
use App\Modules\Campaigns\Actions\ResumeCampaignEnrollmentAction;
use App\Modules\Campaigns\Automation\CampaignsAutomationPointAuthoringContributor;
use App\Modules\Campaigns\Automation\PauseCampaignAutomationActionHandler;
use App\Modules\Campaigns\Automation\ResumeCampaignAutomationActionHandler;
use App\Modules\Campaigns\Capabilities\CampaignsAutomationCapabilityContributor;
use App\Modules\Campaigns\Models\Campaign;
use App\Modules\Campaigns\Models\CampaignEnrollment;
use App\Modules\Core\Models\Contact;
use App\Modules\Messaging\Models\MessageChainEnrollment;
use App\Support\AutomationCapabilities\Data\AutomationActionContext;
use App\Support\AutomationCapabilities\Data\AutomationActionResult;
use App\Support\AutomationCapabilities\Data\AutomationPointAuthoringContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class CampaignLifecycleAutomationCapabilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_campaigns_contributes_pause_and_resume_capabilities_and_authoring(): void
    {
        $capabilities = collect(iterator_to_array(
            app(CampaignsAutomationCapabilityContributor::class)->definitions(),
        ));

        $this->assertSame(
            'pause_campaign',
            $capabilities->firstWhere('key', 'campaigns.pause_enrollment')?->pointType,
        );
        $this->assertSame(
            'resume_campaign',
            $capabilities->firstWhere('key', 'campaigns.resume_enrollment')?->pointType,
        );

        $campaign = Campaign::factory()->create([
            'key' => 'reply_nurture',
            'name' => 'Reply Nurture',
        ]);
        $authoring = app(CampaignsAutomationPointAuthoringContributor::class);
        $context = new AutomationPointAuthoringContext();

        $this->assertTrue($authoring->available('pause_campaign', $context));
        $this->assertTrue($authoring->available('resume_campaign', $context));
        $this->assertFalse($authoring->available('cancel_campaign', $context));

        $this->assertEquals([
            'campaign_key' => $campaign->key,
            'reason' => 'flow_route_paused_campaign',
            'on_not_enrolled' => 'skipped',
            'skip_pending_messages' => true,
        ], $authoring->buildDefinition(
            'pause_campaign',
            [
                'campaign_key' => $campaign->key,
                'skip_pending_messages' => true,
            ],
            $context,
        ));

        $this->assertEquals([
            'campaign_key' => $campaign->key,
            'reason' => 'flow_route_resumed_campaign',
            'on_not_enrolled' => 'skipped',
        ], $authoring->buildDefinition(
            'resume_campaign',
            ['campaign_key' => $campaign->key],
            $context,
        ));
    }

    public function test_pause_handler_delegates_to_campaign_lifecycle_action(): void
    {
        $contact = Contact::factory()->create();
        $enrollment = $this->enrollment(
            contact: $contact,
            campaignKey: 'reply_nurture',
            status: MessageChainEnrollment::STATUS_PAUSED,
        );

        $action = Mockery::mock(PauseCampaignEnrollmentAction::class);
        $action->shouldReceive('handle')
            ->once()
            ->withArgs(function (
                Contact $actualContact,
                string $campaignKey,
                mixed $source,
                string $reason,
                bool $skipPendingMessages,
                array $meta,
            ) use ($contact): bool {
                $this->assertTrue($actualContact->is($contact));
                $this->assertSame('reply_nurture', $campaignKey);
                $this->assertTrue($source->is($contact));
                $this->assertSame('human_reply', $reason);
                $this->assertTrue($skipPendingMessages);
                $this->assertSame('automation', $meta['source']);

                return true;
            })
            ->andReturn($enrollment);

        $result = (new PauseCampaignAutomationActionHandler($action))->handle(
            new AutomationActionContext(
                input: [
                    'campaign_key' => 'reply_nurture',
                    'reason' => 'human_reply',
                    'skip_pending_messages' => true,
                ],
                models: ['current_contact' => $contact],
            ),
        );

        $this->assertSame(AutomationActionResult::STATUS_COMPLETED, $result->status);
        $this->assertSame('campaign_paused', $result->reason);
        $this->assertSame($enrollment->getKey(), $result->primaryArtifact()?->getKey());
    }

    public function test_resume_handler_skips_when_contact_has_no_open_enrollment(): void
    {
        $contact = Contact::factory()->create();

        $action = Mockery::mock(ResumeCampaignEnrollmentAction::class);
        $action->shouldReceive('handle')
            ->once()
            ->andReturn(null);

        $result = (new ResumeCampaignAutomationActionHandler($action))->handle(
            new AutomationActionContext(
                input: ['campaign_key' => 'reply_nurture'],
                models: ['current_contact' => $contact],
            ),
        );

        $this->assertSame(AutomationActionResult::STATUS_SKIPPED, $result->status);
        $this->assertSame('campaign_not_enrolled', $result->reason);
    }

    private function enrollment(
        Contact $contact,
        string $campaignKey,
        string $status,
    ): CampaignEnrollment {
        $chainEnrollment = new MessageChainEnrollment();
        $chainEnrollment->setAttribute('id', 71);
        $chainEnrollment->setAttribute('status', $status);

        $enrollment = new CampaignEnrollment([
            'contact_id' => $contact->getKey(),
            'campaign_id' => 61,
            'message_chain_enrollment_id' => 71,
            'campaign_key' => $campaignKey,
        ]);
        $enrollment->setAttribute('id', 81);
        $enrollment->setRelation('messageChainEnrollment', $chainEnrollment);

        return $enrollment;
    }
}