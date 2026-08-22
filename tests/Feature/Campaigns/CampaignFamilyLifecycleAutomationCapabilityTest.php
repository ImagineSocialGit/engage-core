<?php

namespace Tests\Feature\Campaigns;

use App\Modules\Campaigns\Actions\CancelCampaignFamilyEnrollmentsAction;
use App\Modules\Campaigns\Actions\PauseCampaignFamilyEnrollmentsAction;
use App\Modules\Campaigns\Automation\CampaignsAutomationPointAuthoringContributor;
use App\Modules\Campaigns\Automation\CancelCampaignFamilyAutomationActionHandler;
use App\Modules\Campaigns\Automation\PauseCampaignFamilyAutomationActionHandler;
use App\Modules\Campaigns\Capabilities\CampaignsAutomationCapabilityContributor;
use App\Modules\Campaigns\Models\Campaign;
use App\Modules\Campaigns\Models\CampaignEnrollment;
use App\Modules\Core\Models\Contact;
use App\Modules\Messaging\Models\MessageChainEnrollment;
use App\Support\AutomationCapabilities\Data\AutomationActionContext;
use App\Support\AutomationCapabilities\Data\AutomationActionResult;
use App\Support\AutomationCapabilities\Data\AutomationPointAuthoringContext;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class CampaignFamilyLifecycleAutomationCapabilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_campaigns_contributes_current_family_pause_and_stop_capabilities(): void
    {
        Campaign::factory()->create([
            'key' => 'consumer_nurture_a',
            'name' => 'Consumer Nurture A',
            'family_key' => 'consumer_nurture',
            'status' => Campaign::STATUS_ACTIVE,
        ]);

        $capabilities = collect(iterator_to_array(
            app(CampaignsAutomationCapabilityContributor::class)->definitions(),
        ));

        $this->assertSame(
            'pause_campaign_family',
            $capabilities->firstWhere('key', 'campaigns.pause_family_enrollments')?->pointType,
        );
        $this->assertSame(
            'cancel_campaign_family',
            $capabilities->firstWhere('key', 'campaigns.cancel_family_enrollments')?->pointType,
        );

        $authoring = app(CampaignsAutomationPointAuthoringContributor::class);
        $context = new AutomationPointAuthoringContext();

        $this->assertTrue($authoring->available('pause_campaign_family', $context));
        $this->assertTrue($authoring->available('cancel_campaign_family', $context));
        $this->assertEquals([
            'family_key' => 'consumer_nurture',
            'reason' => 'flow_route_paused_campaign_family',
            'on_not_enrolled' => 'skipped',
            'skip_pending_messages' => true,
        ], $authoring->buildDefinition(
            'pause_campaign_family',
            [
                'family_key' => 'consumer_nurture',
                'skip_pending_messages' => true,
            ],
            $context,
        ));
        $this->assertSame(
            'Stop current nurture',
            $authoring->editorSummary(
                'cancel_campaign_family',
                ['family_key' => 'consumer_nurture'],
                $context,
            ),
        );
    }

    public function test_pause_family_handler_delegates_without_naming_a_specific_campaign(): void
    {
        $contact = Contact::factory()->create();
        $enrollment = $this->enrollment($contact, MessageChainEnrollment::STATUS_PAUSED);

        $action = Mockery::mock(PauseCampaignFamilyEnrollmentsAction::class);
        $action->shouldReceive('handle')
            ->once()
            ->withArgs(function (
                Contact $actualContact,
                string $familyKey,
                mixed $source,
                string $reason,
                bool $skipPendingMessages,
                array $meta,
            ) use ($contact): bool {
                $this->assertTrue($actualContact->is($contact));
                $this->assertSame('consumer_nurture', $familyKey);
                $this->assertTrue($source->is($contact));
                $this->assertSame('human_reply', $reason);
                $this->assertTrue($skipPendingMessages);
                $this->assertSame('automation', $meta['source']);

                return true;
            })
            ->andReturn(new Collection([$enrollment]));

        $result = (new PauseCampaignFamilyAutomationActionHandler($action))->handle(
            new AutomationActionContext(
                input: [
                    'family_key' => 'consumer_nurture',
                    'reason' => 'human_reply',
                    'skip_pending_messages' => true,
                ],
                models: ['current_contact' => $contact],
            ),
        );

        $this->assertSame(AutomationActionResult::STATUS_COMPLETED, $result->status);
        $this->assertSame('campaign_family_paused', $result->reason);
        $this->assertSame($enrollment->getKey(), $result->primaryArtifact()?->getKey());
    }

    public function test_stop_family_handler_skips_when_no_current_family_enrollment_exists(): void
    {
        $contact = Contact::factory()->create();

        $action = Mockery::mock(CancelCampaignFamilyEnrollmentsAction::class);
        $action->shouldReceive('handle')
            ->once()
            ->andReturn(new Collection());

        $result = (new CancelCampaignFamilyAutomationActionHandler($action))->handle(
            new AutomationActionContext(
                input: ['family_key' => 'consumer_nurture'],
                models: ['current_contact' => $contact],
            ),
        );

        $this->assertSame(AutomationActionResult::STATUS_SKIPPED, $result->status);
        $this->assertSame('campaign_family_not_enrolled', $result->reason);
    }

    private function enrollment(Contact $contact, string $status): CampaignEnrollment
    {
        $campaign = new Campaign([
            'key' => 'current_nurture',
            'name' => 'Current Nurture',
            'family_key' => 'consumer_nurture',
        ]);
        $campaign->setAttribute('id', 61);

        $chainEnrollment = new MessageChainEnrollment();
        $chainEnrollment->setAttribute('id', 71);
        $chainEnrollment->setAttribute('status', $status);

        $enrollment = new CampaignEnrollment([
            'contact_id' => $contact->getKey(),
            'campaign_id' => 61,
            'message_chain_enrollment_id' => 71,
            'campaign_key' => 'current_nurture',
        ]);
        $enrollment->setAttribute('id', 81);
        $enrollment->setRelation('campaign', $campaign);
        $enrollment->setRelation('messageChainEnrollment', $chainEnrollment);

        return $enrollment;
    }
}