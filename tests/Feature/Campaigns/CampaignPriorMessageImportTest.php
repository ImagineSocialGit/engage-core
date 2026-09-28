<?php

namespace Tests\Feature\Campaigns;

use App\Models\User;
use App\Modules\Campaigns\Actions\RecordPriorCampaignMessageReceiptAction;
use App\Modules\Campaigns\Import\CampaignPriorMessageReceiptContactImportPostProcessor;
use App\Modules\Campaigns\Models\Campaign;
use App\Modules\Campaigns\Models\CampaignPriorMessageReceipt;
use App\Modules\Core\Data\Contacts\ContactImportContext;
use App\Modules\Core\Models\Contact;
use App\Modules\Core\Models\ContactImportBatch;
use App\Modules\Core\Models\ContactImportOccurrence;
use App\Modules\Messaging\Models\MessageChain;
use App\Modules\Messaging\Models\MessageChainStep;
use App\Modules\Messaging\Models\MessageChainVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class CampaignPriorMessageImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_import_preview_exposes_campaign_steps_and_selected_history_has_import_provenance(): void
    {
        config()->set('modules.enabled', ['campaigns', 'messaging']);
        Storage::fake('local');
        $campaign = $this->campaign('realtor_outreach');

        $preview = $this->actingAs(User::factory()->create())->post(
            route('crm.contacts.import.preview'),
            ['csv' => UploadedFile::fake()->createWithContent(
                'realtors.csv',
                "Email\nprior@example.test\n",
            )],
        );

        $preview->assertOk()->assertViewHas('postImportInputs', function (array $groups): bool {
            $group = collect($groups)->firstWhere('key', 'campaign_prior_message_receipts');

            return is_array($group)
                && collect($group['inputs'] ?? [])->contains(
                    fn (array $field): bool => ($field['key'] ?? null) === 'campaign_key',
                )
                && collect($group['inputs'] ?? [])->where('type', 'select')->count() === 3;
        });

        $processor = app(CampaignPriorMessageReceiptContactImportPostProcessor::class);
        $config = $processor->operatorConfig(null);
        $fields = $processor->inputDefinitions($config);
        $priorB = collect($fields)->filter(
            fn (array $field): bool => ($field['show_when']['equals'] ?? null) === $campaign->key,
        )->last();
        $this->assertIsArray($priorB);

        $resolved = $processor->withSubmittedInputs($config, [
            'campaign_key' => $campaign->key,
            $priorB['key'] => '1',
        ]);
        $this->assertSame(['step_2'], $resolved['step_keys']);

        $contact = Contact::factory()->create();
        $batch = ContactImportBatch::query()->create(['name' => 'Realtors']);
        $occurrence = ContactImportOccurrence::query()->create([
            'contact_import_batch_id' => $batch->getKey(),
            'contact_id' => $contact->getKey(),
            'row_number' => 1,
            'outcome' => ContactImportOccurrence::OUTCOME_CREATED,
            'identity_type' => 'email',
            'identity_value' => $contact->email,
            'row_fingerprint' => hash('sha256', $contact->email),
        ]);
        $result = $processor->handle(new ContactImportContext(
            contact: $contact,
            batch: $batch,
            occurrence: $occurrence,
            row: [],
            mapping: [],
        ), $resolved);

        $this->assertSame(1, $result->meta['recorded_count']);
        $this->assertTrue(app(RecordPriorCampaignMessageReceiptAction::class)
            ->recorded($contact, $campaign, 'step_2'));
        $this->assertSame($occurrence->getKey(), CampaignPriorMessageReceipt::query()->firstOrFail()->source_id);
    }

    public function test_operator_cannot_submit_steps_outside_the_selected_campaign(): void
    {
        $first = $this->campaign('first_campaign');
        $second = $this->campaign('second_campaign');
        $processor = app(CampaignPriorMessageReceiptContactImportPostProcessor::class);
        $config = $processor->operatorConfig(null);
        $foreignStep = collect($processor->inputDefinitions($config))->first(
            fn (array $field): bool => ($field['show_when']['equals'] ?? null) === $second->key,
        );

        $this->assertIsArray($foreignStep);
        $this->expectException(ValidationException::class);
        $processor->withSubmittedInputs($config, [
            'campaign_key' => $first->key,
            $foreignStep['key'] => '1',
        ]);
    }

    private function campaign(string $key): Campaign
    {
        $chain = MessageChain::query()->create([
            'key' => 'campaign.'.$key,
            'name' => $key,
            'status' => MessageChain::STATUS_ACTIVE,
        ]);
        $version = MessageChainVersion::query()->create([
            'message_chain_id' => $chain->getKey(),
            'version' => 1,
            'content_hash' => hash('sha256', $key),
        ]);

        foreach (['Message A', 'Message B'] as $index => $name) {
            MessageChainStep::query()->create([
                'message_chain_version_id' => $version->getKey(),
                'key' => 'step_'.($index + 1),
                'name' => $name,
                'sort_order' => ($index + 1) * 10,
            ]);
        }

        $version->forceFill(['published_at' => now()])->save();
        $chain->forceFill(['current_version_id' => $version->getKey()])->save();

        return Campaign::factory()->create([
            'key' => $key,
            'message_chain_id' => $chain->getKey(),
            'status' => Campaign::STATUS_ACTIVE,
        ]);
    }
}