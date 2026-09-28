<?php

namespace Tests\Feature\Campaigns;

use App\Models\User;
use App\Modules\Campaigns\Actions\RecordPriorCampaignMessageReceiptAction;
use App\Modules\Campaigns\Models\Campaign;
use App\Modules\Campaigns\Models\CampaignPriorMessageReceipt;
use App\Modules\Core\Models\Contact;
use App\Modules\Core\Models\ContactImportBatch;
use App\Modules\Core\Models\ContactImportOccurrence;
use App\Modules\Messaging\Models\MessageChain;
use App\Modules\Messaging\Models\MessageChainStep;
use App\Modules\Messaging\Models\MessageChainVersion;
use App\Modules\Messaging\Models\ScheduledMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class CampaignPriorMessageReceiptTest extends TestCase
{
    use RefreshDatabase;

    public function test_prior_receipt_is_scoped_to_contact_campaign_and_stable_business_step(): void
    {
        $campaign = $this->campaign('realtor_outreach');
        $otherCampaign = $this->campaign('other_outreach');
        $contact = Contact::factory()->create();
        $otherContact = Contact::factory()->create();
        $operator = User::factory()->create();
        $action = app(RecordPriorCampaignMessageReceiptAction::class);

        $receipt = $action->handle(
            $contact,
            $campaign,
            'step_2',
            CampaignPriorMessageReceipt::SOURCE_OPERATOR_ATTESTATION,
            attestedBy: $operator,
        );

        $this->assertTrue($action->recorded($contact, $campaign, 'step_2'));
        $this->assertFalse($action->recorded($contact, $campaign, 'step_1'));
        $this->assertFalse($action->recorded($otherContact, $campaign, 'step_2'));
        $this->assertFalse($action->recorded($contact, $otherCampaign, 'step_2'));

        $sameReceipt = $action->handle(
            $contact,
            $campaign,
            'step_2',
            CampaignPriorMessageReceipt::SOURCE_OPERATOR_ATTESTATION,
            attestedBy: $operator,
        );

        $this->assertSame($receipt->getKey(), $sameReceipt->getKey());
        $this->assertSame(1, CampaignPriorMessageReceipt::query()->count());
        $this->assertSame(0, ScheduledMessage::query()->count());

        $this->publishNextVersion($campaign);
        $this->assertTrue($action->recorded($contact, $campaign, 'step_2'));
    }

    public function test_import_provenance_is_bound_to_the_same_contact_and_unknown_steps_are_rejected(): void
    {
        $campaign = $this->campaign('import_realtor_outreach');
        $contact = Contact::factory()->create();
        $otherContact = Contact::factory()->create();
        $batch = ContactImportBatch::query()->create(['name' => 'Realtor import']);
        $occurrence = ContactImportOccurrence::query()->create([
            'contact_import_batch_id' => $batch->getKey(),
            'contact_id' => $contact->getKey(),
            'row_number' => 1,
            'outcome' => ContactImportOccurrence::OUTCOME_CREATED,
            'identity_type' => 'email',
            'identity_value' => $contact->email,
            'row_fingerprint' => hash('sha256', $contact->email),
        ]);
        $action = app(RecordPriorCampaignMessageReceiptAction::class);

        $receipt = $action->handle(
            $contact,
            $campaign,
            'step_1',
            CampaignPriorMessageReceipt::SOURCE_CONTACT_IMPORT,
            source: $occurrence,
        );

        $this->assertSame($occurrence->getMorphClass(), $receipt->source_type);
        $this->assertSame($occurrence->getKey(), $receipt->source_id);

        try {
            $action->handle(
                $otherContact,
                $campaign,
                'step_1',
                CampaignPriorMessageReceipt::SOURCE_CONTACT_IMPORT,
                source: $occurrence,
            );
            $this->fail('An import occurrence cannot attest a different Contact.');
        } catch (InvalidArgumentException) {
            $this->assertFalse($action->recorded($otherContact, $campaign, 'step_1'));
        }

        $this->expectException(InvalidArgumentException::class);
        $action->handle(
            $contact,
            $campaign,
            'unknown_step',
            CampaignPriorMessageReceipt::SOURCE_CONTACT_IMPORT,
            source: $occurrence,
        );
    }

    private function campaign(string $key): Campaign
    {
        $chain = MessageChain::query()->create([
            'key' => 'campaign.'.$key,
            'name' => $key,
            'status' => MessageChain::STATUS_ACTIVE,
        ]);
        $version = $this->version($chain, 1);
        $chain->forceFill(['current_version_id' => $version->getKey()])->save();

        return Campaign::factory()->create([
            'key' => $key,
            'message_chain_id' => $chain->getKey(),
            'status' => Campaign::STATUS_ACTIVE,
        ]);
    }

    private function publishNextVersion(Campaign $campaign): void
    {
        $chain = $campaign->messageChain()->firstOrFail();
        $version = $this->version($chain, 2);
        $chain->forceFill(['current_version_id' => $version->getKey()])->save();
    }

    private function version(MessageChain $chain, int $number): MessageChainVersion
    {
        $version = MessageChainVersion::query()->create([
            'message_chain_id' => $chain->getKey(),
            'version' => $number,
            'content_hash' => hash('sha256', $chain->key.':'.$number),
        ]);

        foreach (['step_1', 'step_2'] as $index => $key) {
            MessageChainStep::query()->create([
                'message_chain_version_id' => $version->getKey(),
                'key' => $key,
                'sort_order' => ($index + 1) * 10,
            ]);
        }

        $version->forceFill(['published_at' => now()])->save();

        return $version;
    }
}