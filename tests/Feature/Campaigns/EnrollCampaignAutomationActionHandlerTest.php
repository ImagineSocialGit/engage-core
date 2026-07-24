<?php

namespace Tests\Feature\Campaigns;

use App\Modules\Campaigns\Automation\EnrollCampaignAutomationActionHandler;
use App\Modules\Campaigns\Exceptions\CampaignUnavailableForEnrollmentException;
use App\Modules\Campaigns\Models\Campaign;
use App\Modules\Core\Models\Contact;
use App\Support\AutomationCapabilities\Data\AutomationActionContext;
use App\Support\AutomationCapabilities\Data\AutomationActionResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EnrollCampaignAutomationActionHandlerTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_skips_inactive_campaign_with_explicit_reason(): void
    {
        $campaign = Campaign::factory()->create([
            'key' => 'inactive_campaign',
            'status' => Campaign::STATUS_INACTIVE,
        ]);

        $result = app(EnrollCampaignAutomationActionHandler::class)->handle(
            $this->context($campaign->key),
        );

        $this->assertSame(AutomationActionResult::STATUS_SKIPPED, $result->status);
        $this->assertSame(
            CampaignUnavailableForEnrollmentException::REASON_INACTIVE,
            $result->reason,
        );
        $this->assertSame($campaign->key, $result->output['campaign_key']);
        $this->assertSame(Campaign::STATUS_INACTIVE, $result->output['campaign_status']);
        $this->assertDatabaseCount('campaign_enrollments', 0);
    }

    public function test_it_skips_missing_campaign_with_distinct_reason(): void
    {
        $result = app(EnrollCampaignAutomationActionHandler::class)->handle(
            $this->context('__missing_campaign__'),
        );

        $this->assertSame(AutomationActionResult::STATUS_SKIPPED, $result->status);
        $this->assertSame(
            CampaignUnavailableForEnrollmentException::REASON_MISSING,
            $result->reason,
        );
        $this->assertSame('__missing_campaign__', $result->output['campaign_key']);
        $this->assertArrayNotHasKey('campaign_status', $result->output);
        $this->assertDatabaseCount('campaign_enrollments', 0);
    }

    private function context(string $campaignKey): AutomationActionContext
    {
        $contact = Contact::factory()->create();

        return new AutomationActionContext(
            input: [
                'campaign_key' => $campaignKey,
            ],
            subject: $contact,
            models: [
                'current_contact' => $contact,
            ],
            source: $contact,
        );
    }
}