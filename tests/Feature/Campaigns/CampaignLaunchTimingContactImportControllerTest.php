<?php

namespace Tests\Feature\Campaigns;

use App\Models\User;
use App\Modules\Campaigns\Models\Campaign;
use App\Modules\Core\Models\ContactImportBatch;
use App\Modules\Messaging\Models\MessageChain;
use App\Modules\Messaging\Models\MessageChainVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CampaignLaunchTimingContactImportControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
    }

    public function test_configured_campaign_launch_exposes_batch_input_and_requires_time_only_when_scheduled(): void
    {
        Config::set('contact_imports.profiles', [
            'timed_launch' => [
                'label' => 'Timed launch',
                'filename_contains' => ['timed launch'],
                'defaults' => [],
                'aliases' => [
                    'email' => ['Email'],
                ],
                'post_import' => [
                    'campaign_launch_timing' => [
                        'campaign_key' => 'candidate_campaign',
                    ],
                ],
            ],
        ]);

        $user = User::factory()->create();
        $csv = UploadedFile::fake()->createWithContent(
            'timed-launch.csv',
            "Email\nperson@example.test\n",
        );

        $preview = $this->actingAs($user)->post(
            route('crm.contacts.import.preview'),
            ['csv' => $csv],
        );

        $preview->assertOk();
        $preview->assertViewHas('postImportInputs', function (array $inputs): bool {
            $launchTiming = collect($inputs)->firstWhere('key', 'campaign_launch_timing');
            $fields = collect($launchTiming['inputs'] ?? [])->keyBy('key');
            $firstMessageAt = $fields->get('first_message_at');

            return is_array($launchTiming)
                && $fields->has('launch_mode')
                && is_array($firstMessageAt)
                && ($firstMessageAt['required'] ?? null) === false
                && ($firstMessageAt['show_when'] ?? null) === [
                    'field' => 'launch_mode',
                    'equals' => 'scheduled',
                ];
        });
        $preview->assertSee(
            'post_import_inputs[campaign_launch_timing][first_message_at]',
            escape: false,
        );

        $csvPath = $preview->viewData('csvPath');

        $this->actingAs($user)->post(
            route('crm.contacts.import.process'),
            [
                'csv_path' => $csvPath,
                'mapping' => [
                    'email' => 'Email',
                ],
                'treatments' => [],
                'post_import_inputs' => [
                    'campaign_launch_timing' => [
                        'launch_mode' => 'scheduled',
                    ],
                ],
            ],
        )->assertSessionHasErrors([
            'post_import_inputs.campaign_launch_timing.first_message_at',
        ]);
    }

    public function test_add_import_without_a_profile_exposes_ready_automatic_campaigns_and_import_only_is_safe_by_default(): void
    {
        $campaign = $this->readyAutomaticCampaign(
            key: 'cold_lead_nurture',
            name: 'Cold Lead Nurture',
            eligibility: [
                'status' => ['prospect_nurture'],
                'tag' => ['old_lead'],
            ],
        );
        $user = User::factory()->create();
        $preview = $this->actingAs($user)->post(
            route('crm.contacts.import.preview'),
            [
                'csv' => UploadedFile::fake()->createWithContent(
                    'one-contact.csv',
                    "Email\nperson@example.test\n",
                ),
            ],
        );

        $preview->assertOk();
        $preview->assertViewHas('postImportInputs', function (array $inputs) use ($campaign): bool {
            $launchTiming = collect($inputs)->firstWhere('key', 'campaign_launch_timing');
            $fields = collect($launchTiming['inputs'] ?? [])->keyBy('key');
            $campaignField = $fields->get('campaign_key');

            return is_array($launchTiming)
                && $fields->has('launch_mode')
                && $fields->has('first_message_at')
                && is_array($campaignField)
                && collect($campaignField['options'] ?? [])->contains(
                    fn (array $option): bool => ($option['value'] ?? null) === $campaign->key
                        && str_contains((string) ($option['label'] ?? ''), 'Status: Prospect Nurture')
                        && str_contains((string) ($option['label'] ?? ''), 'Tag: Old Lead'),
                );
        });

        Queue::fake();

        $response = $this->actingAs($user)->post(
            route('crm.contacts.import.process'),
            [
                'csv_path' => $preview->viewData('csvPath'),
                'mapping' => ['email' => 'Email'],
                'treatments' => [],
                'post_import_inputs' => [
                    'campaign_launch_timing' => [
                        'launch_mode' => 'none',
                        'campaign_key' => $campaign->key,
                    ],
                ],
            ],
        );

        $batch = ContactImportBatch::query()->latest('id')->firstOrFail();

        $response->assertRedirect(route('crm.contacts.import-batches.show', $batch));
        $this->assertArrayNotHasKey(
            'campaign_launch_timing',
            data_get($batch->meta, 'post_import_config', []),
        );
    }

    public function test_operator_can_start_a_discovered_campaign_when_the_import_completes(): void
    {
        $campaign = $this->readyAutomaticCampaign(
            key: 'cold_lead_nurture',
            name: 'Cold Lead Nurture',
            eligibility: ['status' => ['prospect_nurture']],
        );
        $user = User::factory()->create();
        $preview = $this->actingAs($user)->post(
            route('crm.contacts.import.preview'),
            [
                'csv' => UploadedFile::fake()->createWithContent(
                    'one-contact.csv',
                    "Email\nperson@example.test\n",
                ),
            ],
        );

        Queue::fake();

        $response = $this->actingAs($user)->post(
            route('crm.contacts.import.process'),
            [
                'csv_path' => $preview->viewData('csvPath'),
                'mapping' => ['email' => 'Email'],
                'treatments' => [],
                'post_import_inputs' => [
                    'campaign_launch_timing' => [
                        'launch_mode' => 'now',
                        'campaign_key' => $campaign->key,
                    ],
                ],
            ],
        );

        $batch = ContactImportBatch::query()->latest('id')->firstOrFail();
        $config = data_get($batch->meta, 'post_import_config.campaign_launch_timing');

        $response->assertRedirect(route('crm.contacts.import-batches.show', $batch));
        $this->assertSame('cold_lead_nurture', $config['campaign_key'] ?? null);
        $this->assertSame('now', $config['launch_mode'] ?? null);
        $this->assertIsString($config['first_message_at'] ?? null);
    }

    /** @param array<string, array<int, string>> $eligibility */
    private function readyAutomaticCampaign(
        string $key,
        string $name,
        array $eligibility,
    ): Campaign {
        $chain = MessageChain::query()->create([
            'key' => $key,
            'name' => $name,
            'status' => MessageChain::STATUS_ACTIVE,
            'source' => 'test',
            'is_customized' => false,
        ]);
        $version = MessageChainVersion::query()->create([
            'message_chain_id' => $chain->getKey(),
            'version' => 1,
            'exit_conditions' => [],
            'content_hash' => hash('sha256', $key),
            'published_at' => now(),
        ]);
        $chain->forceFill([
            'current_version_id' => $version->getKey(),
        ])->save();

        return Campaign::factory()->create([
            'key' => $key,
            'name' => $name,
            'message_chain_id' => $chain->getKey(),
            'enrollment_mode' => Campaign::ENROLLMENT_MODE_AUTOMATIC,
            'eligibility_filter' => $eligibility,
            'status' => Campaign::STATUS_ACTIVE,
        ]);
    }
}