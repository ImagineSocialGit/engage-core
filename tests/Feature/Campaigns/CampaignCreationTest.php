<?php

namespace Tests\Feature\Campaigns;

use App\Http\Middleware\ForceStagingAccess;
use App\Models\User;
use App\Modules\Campaigns\Actions\CreateCampaignAction;
use App\Modules\Campaigns\Models\Campaign;
use App\Modules\Campaigns\Models\CampaignStep;
use App\Modules\Campaigns\Models\CampaignStepVariant;
use App\Modules\Messaging\Models\MessageChain;
use App\Modules\Messaging\Models\MessageChainStep;
use App\Modules\Messaging\Models\MessageTemplate;
use App\Modules\Messaging\Models\MessageTemplateCatalogEntry;
use App\Modules\Messaging\Models\MessageTemplatePreset;
use App\Modules\Messaging\Models\MessageTemplateVersion;
use App\Support\ModuleIntegrations\Messaging\Contracts\MessageMediaLibrary;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class CampaignCreationTest extends TestCase
{
    use RefreshDatabase;

    public function test_creation_surface_is_available_from_campaigns(): void
    {
        $this->enableCampaigns();
        $user = User::factory()->create();

        $this->withoutMiddleware(ForceStagingAccess::class);

        $this->actingAs($user)
            ->get(route('crm.campaigns.create'))
            ->assertOk()
            ->assertViewIs('crm.campaigns.create')
            ->assertViewHas('options')
            ->assertViewHas('selectedOption')
            ->assertViewHas('builderStages')
            ->assertViewHas('availableFields');
    }

    public function test_email_campaign_creation_builds_an_inactive_direct_message_chain(): void
    {
        $this->enableCampaigns();
        $user = User::factory()->create();

        $this->withoutMiddleware(ForceStagingAccess::class);

        $response = $this->actingAs($user)
            ->post(route('crm.campaigns.store'), [
                'creation_intent' => 'lead_nurture',
                'name' => 'New Lead Follow-up',
                'description' => 'Follow up with new leads.',
                'channel' => 'email',
                'subject' => 'Hi {contact.first_name}',
                'body' => 'Thanks for connecting. I wanted to follow up.',
            ]);

        $campaign = Campaign::query()
            ->where('name', 'New Lead Follow-up')
            ->firstOrFail();

        $response->assertRedirect(route('crm.campaigns.edit', [
            'campaign' => $campaign,
            'panel' => 'start',
        ]));

        $this->assertSame(Campaign::STATUS_INACTIVE, $campaign->status);
        $this->assertSame(Campaign::ENROLLMENT_MODE_MANUAL, $campaign->enrollment_mode);
        $this->assertSame(Campaign::REENTRY_NEVER, $campaign->reentry_policy);
        $this->assertSame(Campaign::INELIGIBLE_CONTINUE, $campaign->ineligible_behavior);
        $this->assertSame('email', $campaign->channel);
        $this->assertSame(CreateCampaignAction::PURPOSE, $campaign->purpose);
        $this->assertSame(CreateCampaignAction::SCOPE, $campaign->scope);
        $this->assertTrue($campaign->is_customized);
        $this->assertNotNull($campaign->customized_at);
        $this->assertSame(
            'lead_nurture',
            data_get($campaign->meta, 'authoring.creation_intent'),
        );

        $campaign->load(
            'messageChain.currentVersion.steps.variants.messageTemplateVersion.messageTemplate',
        );

        $chain = $campaign->messageChain;

        $this->assertInstanceOf(MessageChain::class, $chain);
        $this->assertSame(MessageChain::STATUS_INACTIVE, $chain->status);
        $this->assertSame(CreateCampaignAction::SOURCE, $chain->source);
        $this->assertTrue($chain->is_customized);

        $version = $chain->currentVersion;

        $this->assertNotNull($version);
        $this->assertTrue($version->isPublished());
        $this->assertCount(1, $version->steps);

        $step = $version->steps->first();

        $this->assertInstanceOf(MessageChainStep::class, $step);
        $this->assertSame(MessageChainStep::TIMING_IMMEDIATE, $step->timing_type);
        $this->assertCount(1, $step->variants);

        $variant = $step->variants->first();

        $this->assertSame('email', $variant->channel);
        $this->assertSame(CreateCampaignAction::PURPOSE, $variant->purpose);
        $this->assertSame(CreateCampaignAction::SCOPE, $variant->scope);
        $this->assertSame(CreateCampaignAction::QUEUE, $variant->queue);

        $templateVersion = $variant->messageTemplateVersion;
        $template = $templateVersion?->messageTemplate;

        $this->assertInstanceOf(MessageTemplateVersion::class, $templateVersion);
        $this->assertInstanceOf(MessageTemplate::class, $template);
        $this->assertSame('Hi {contact.first_name}', $templateVersion->payload()['subject']);
        $this->assertSame(
            'Thanks for connecting. I wanted to follow up.',
            $templateVersion->payload()['body'],
        );

        $preset = MessageTemplatePreset::query()
            ->where('key', $template->key)
            ->firstOrFail();

        $catalogEntry = MessageTemplateCatalogEntry::query()
            ->where('message_template_preset_id', $preset->getKey())
            ->firstOrFail();

        $this->assertSame('campaigns', $catalogEntry->module_key);
        $this->assertSame('campaign_step', $catalogEntry->usage_type);
        $this->assertSame(
            $campaign->key,
            data_get($catalogEntry->meta, 'campaign_key'),
        );
        $this->assertSame(1, data_get($catalogEntry->meta, 'campaign_step'));
        $this->assertSame(
            'email',
            data_get($catalogEntry->meta, 'campaign_step_variant_key'),
        );

        $this->assertSame(0, CampaignStep::query()->where('campaign_id', $campaign->getKey())->count());
        $this->assertSame(0, CampaignStepVariant::query()->count());
    }

    public function test_email_campaign_creation_can_snapshot_media_through_the_universal_authoring_contract(): void
    {
        $this->enableCampaigns();
        $user = User::factory()->create();
        $assetUuid = '44444444-4444-4444-8444-444444444444';

        app()->instance(MessageMediaLibrary::class, new class($assetUuid) implements MessageMediaLibrary
        {
            public function __construct(private readonly string $assetUuid) {}

            public function available(): bool
            {
                return true;
            }

            public function selectableAssets(): array
            {
                return [];
            }

            public function snapshot(string $assetUuid, ?string $posterAssetUuid = null): array
            {
                if ($assetUuid !== $this->assetUuid) {
                    throw new \RuntimeException('Unexpected Media asset UUID.');
                }

                return [
                    'asset_uuid' => $assetUuid,
                    'kind' => 'image',
                    'title' => 'Campaign image',
                    'url' => 'https://cdn.example.test/campaign.webp',
                    'mime_type' => 'image/webp',
                    'tracking_key' => 'media_primary',
                ];
            }

            public function store(
                UploadedFile $file,
                ?string $title = null,
                ?string $posterAssetUuid = null,
                ?Model $uploadedBy = null,
            ): array {
                throw new \RuntimeException('Upload should not be called in this test.');
            }
        });

        $this->withoutMiddleware(ForceStagingAccess::class);

        $this->actingAs($user)
            ->post(route('crm.campaigns.store'), [
                'creation_intent' => 'lead_nurture',
                'name' => 'Media Campaign',
                'channel' => 'email',
                'subject' => 'Campaign subject',
                'body' => 'Campaign body.',
                'media_present' => '1',
                'media_asset_uuid' => $assetUuid,
            ])
            ->assertSessionHasNoErrors();

        $campaign = Campaign::query()
            ->where('name', 'Media Campaign')
            ->firstOrFail()
            ->load('messageChain.currentVersion.steps.variants.messageTemplateVersion');

        $payload = $campaign->messageChain?->currentVersion?->steps
            ->first()?->variants
            ->first()?->messageTemplateVersion?->payload() ?? [];

        $this->assertSame($assetUuid, data_get($payload, 'media.asset_uuid'));
        $this->assertSame('https://cdn.example.test/campaign.webp', data_get($payload, 'media.url'));
    }

    public function test_sms_campaign_creation_uses_the_same_direct_chain_contract(): void
    {
        $this->enableCampaigns();
        $user = User::factory()->create();

        $this->withoutMiddleware(ForceStagingAccess::class);

        $this->actingAs($user)
            ->post(route('crm.campaigns.store'), [
                'creation_intent' => 'reengagement',
                'name' => 'Quiet Lead Re-engagement',
                'channel' => 'sms',
                'message' => 'Hi {contact.first_name}, checking in to see if I can help.',
            ])
            ->assertSessionHasNoErrors();

        $campaign = Campaign::query()
            ->where('name', 'Quiet Lead Re-engagement')
            ->firstOrFail()
            ->load('messageChain.currentVersion.steps.variants.messageTemplateVersion');

        $variant = $campaign->messageChain?->currentVersion?->steps
            ->first()?->variants
            ->first();

        $this->assertSame(Campaign::STATUS_INACTIVE, $campaign->status);
        $this->assertSame('sms', $campaign->channel);
        $this->assertSame('sms', $variant?->channel);
        $this->assertSame(
            'Hi {contact.first_name}, checking in to see if I can help.',
            $variant?->messageTemplateVersion?->payload()['message'] ?? null,
        );
    }

    public function test_campaign_creation_requires_real_first_message_copy(): void
    {
        $this->enableCampaigns();
        $user = User::factory()->create();

        $this->withoutMiddleware(ForceStagingAccess::class);

        $this->actingAs($user)
            ->from(route('crm.campaigns.create'))
            ->post(route('crm.campaigns.store'), [
                'creation_intent' => 'custom',
                'name' => 'Incomplete Campaign',
                'channel' => 'email',
                'subject' => '',
                'body' => '',
            ])
            ->assertRedirect(route('crm.campaigns.create'))
            ->assertSessionHasErrors([
                'subject',
                'body',
            ]);

        $this->assertDatabaseMissing('campaigns', [
            'name' => 'Incomplete Campaign',
        ]);
    }

    public function test_campaign_keys_remain_unique_when_names_repeat(): void
    {
        $this->enableCampaigns();
        $user = User::factory()->create();

        $this->withoutMiddleware(ForceStagingAccess::class);

        foreach (range(1, 2) as $attempt) {
            $this->actingAs($user)
                ->post(route('crm.campaigns.store'), [
                    'creation_intent' => 'client_follow_up',
                    'name' => 'Client Follow-up',
                    'channel' => 'sms',
                    'message' => 'Message '.$attempt,
                ])
                ->assertSessionHasNoErrors();
        }

        $campaigns = Campaign::query()
            ->where('name', 'Client Follow-up')
            ->orderBy('id')
            ->get();

        $this->assertSame(2, $campaigns->count());
        $this->assertNotSame($campaigns[0]->key, $campaigns[1]->key);
    }

    public function test_index_counts_direct_message_chain_steps_for_crm_created_campaigns(): void
    {
        $this->enableCampaigns();
        $user = User::factory()->create();

        $campaign = app(CreateCampaignAction::class)->handle(
            name: 'Index Count Campaign',
            description: null,
            channel: 'sms',
            firstMessagePayload: ['message' => 'First message'],
            creationOption: app(\App\Modules\Campaigns\Services\CampaignCreationGuide::class)
                ->find('custom') ?? throw new \RuntimeException('Missing custom Campaign creation option.'),
            createdBy: $user,
        );

        $this->withoutMiddleware(ForceStagingAccess::class);

        $response = $this->actingAs($user)
            ->get(route('crm.campaigns.index'))
            ->assertOk();

        $listed = $response->viewData('campaigns')
            ->firstWhere('id', $campaign->getKey());

        $this->assertInstanceOf(Campaign::class, $listed);
        $this->assertSame(1, (int) $listed->message_steps_count);
    }

    private function enableCampaigns(): void
    {
        config()->set('modules.enabled', [
            'campaigns',
            'messaging',
        ]);
    }
}