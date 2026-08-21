<?php

namespace Tests\Feature\Campaigns;

use App\Modules\Campaigns\Actions\EnrollContactInCampaignAction;
use App\Modules\Campaigns\Exceptions\CampaignUnavailableForEnrollmentException;
use App\Modules\Campaigns\Import\CampaignEnrollmentContactImportPostProcessor;
use App\Modules\Campaigns\Models\CampaignEnrollment;
use App\Modules\Core\Data\Contacts\ContactImportContext;
use App\Modules\Core\Data\Contacts\ContactImportPostProcessResult;
use App\Modules\Core\Models\Contact;
use App\Modules\Core\Models\ContactImportBatch;
use App\Modules\Core\Models\ContactImportOccurrence;
use Mockery;
use Tests\TestCase;

class CampaignEnrollmentContactImportPostProcessorTest extends TestCase
{
    public function test_it_reports_family_arbitration_block_without_throwing(): void
    {
        $action = Mockery::mock(EnrollContactInCampaignAction::class);
        $action->shouldReceive('handle')
            ->once()
            ->andThrow(CampaignUnavailableForEnrollmentException::familyBlocked(
                campaignKey: 'candidate_campaign',
                familyKey: 'consumer_nurture',
                campaignPriority: 10,
                blockingCampaignKey: 'hot_lead_campaign',
                blockingPriority: 20,
                blockingEnrollmentId: 44,
            ));

        $processor = new CampaignEnrollmentContactImportPostProcessor($action);
        $result = $processor->handle($this->context(), [
            'campaign_key' => 'candidate_campaign',
        ]);

        $this->assertSame(ContactImportPostProcessResult::STATE_BLOCKED, $result->state);
        $this->assertSame('campaign_family_blocked', $result->reasonCode);
        $this->assertSame('hot_lead_campaign', $result->meta['blocking_campaign_key']);
        $this->assertTrue($result->reviewRequired());
    }

    public function test_it_reports_successful_or_existing_enrollment_as_applied(): void
    {
        $enrollment = new CampaignEnrollment();
        $enrollment->setAttribute('id', 18);
        $enrollment->setAttribute('message_chain_enrollment_id', 27);

        $action = Mockery::mock(EnrollContactInCampaignAction::class);
        $action->shouldReceive('handle')
            ->once()
            ->andReturn($enrollment);

        $processor = new CampaignEnrollmentContactImportPostProcessor($action);
        $result = $processor->handle($this->context(), [
            'campaign_key' => 'candidate_campaign',
        ]);

        $this->assertSame(ContactImportPostProcessResult::STATE_APPLIED, $result->state);
        $this->assertSame(18, $result->meta['campaign_enrollment_id']);
        $this->assertSame(27, $result->meta['message_chain_enrollment_id']);
    }

    public function test_it_uses_stable_profile_entry_identity_and_defers_eager_progression(): void
    {
        $enrollment = new CampaignEnrollment();
        $enrollment->setAttribute('id', 31);
        $enrollment->setAttribute('message_chain_enrollment_id', 41);
        $action = Mockery::mock(EnrollContactInCampaignAction::class);
        $action->shouldReceive('handle')
            ->once()
            ->andReturnUsing(function (
                Contact $contact,
                string $campaignKey,
                mixed $source = null,
                array $payload = [],
                ?array $meta = null,
                ?array $startContext = null,
                ?array $exitConditions = null,
                ?string $channel = null,
                ?string $purpose = null,
                ?string $scope = null,
                ?string $dispatchKey = null,
                ?string $entryKey = null,
                bool $eagerProcess = true,
            ) use ($enrollment): CampaignEnrollment {
                $this->assertSame('candidate_campaign', $campaignKey);
                $this->assertSame(
                    'contact_import:test_profile:candidate_campaign',
                    $entryKey,
                );
                $this->assertFalse($eagerProcess);

                return $enrollment;
            });

        $processor = new CampaignEnrollmentContactImportPostProcessor($action);
        $processor->handle($this->context(), [
            'campaign_key' => 'candidate_campaign',
        ]);
    }

    private function context(): ContactImportContext
    {
        $contact = new Contact(['email' => 'person@example.test']);
        $contact->setAttribute('id', 10);

        $batch = new ContactImportBatch(['imported_at' => now()]);
        $batch->setAttribute('id', 11);

        $occurrence = new ContactImportOccurrence();
        $occurrence->setAttribute('id', 12);

        return new ContactImportContext(
            contact: $contact,
            batch: $batch,
            occurrence: $occurrence,
            row: ['Email' => 'person@example.test'],
            mapping: ['email' => 'Email'],
            profileKey: 'test_profile',
        );
    }
}