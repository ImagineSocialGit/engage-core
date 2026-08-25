<?php

namespace Tests\Feature\Campaigns;

use App\Http\Middleware\ForceStagingAccess;
use App\Models\User;
use App\Modules\Campaigns\Models\Campaign;
use App\Modules\InboundMessaging\Models\InboundReplyProfile;
use App\Modules\Messaging\Actions\PublishMessageChainVersionAction;
use App\Modules\Messaging\Actions\PublishMessageTemplateVersionAction;
use App\Modules\Messaging\Models\MessageChain;
use App\Modules\Messaging\Models\MessageChainStep;
use App\Modules\Messaging\Models\MessageChainVersion;
use App\Modules\Messaging\Models\MessageTemplate;
use App\Modules\Messaging\Models\MessageTemplateCatalogEntry;
use App\Modules\Messaging\Models\MessageTemplatePreset;
use App\Modules\Messaging\Models\MessageTemplateVersion;
use App\Modules\Messaging\Payloads\EmailPayload;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CampaignMessageReplyHandlingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('modules.enabled', [
            'campaigns',
            'messaging',
            'inbound_messaging',
        ]);
        $this->withoutMiddleware(ForceStagingAccess::class);
    }

    public function test_campaign_message_exposes_contextual_reply_rules_and_publishes_assignment_changes(): void
    {
        $user = User::factory()->create();
        $profile = $this->profile();
        [$campaign, $firstVersion] = $this->campaignWithPublishedMessage($profile->key);
        $firstVariant = $firstVersion->steps->firstOrFail()->variants->firstOrFail();

        $response = $this->actingAs($user)
            ->get(route('crm.campaigns.edit', [
                'campaign' => $campaign,
                'panel' => 'messages',
            ]));

        $response
            ->assertOk()
            ->assertSee('data-message-reply-handling', false)
            ->assertSee('data-message-reply-profile-form', false)
            ->assertSee('data-message-reply-editor-form', false)
            ->assertSee(route('crm.campaigns.messages.reply-handling.update', [
                'campaign' => $campaign,
                'messageChainStepVariant' => $firstVariant,
            ]), false)
            ->assertSee(route('crm.inbound-messaging.reply-profiles.update', $profile), false);

        $message = collect($response->viewData('messageReview')['presentation']['channels'])
            ->flatMap(fn (array $channel): array => $channel['messages'])
            ->first();

        $this->assertIsArray($message);
        $this->assertSame($profile->key, $message['reply_profile_key']);
        $this->assertSame($profile->key, $message['reply_handling']['key']);
        $this->assertEqualsCanonicalizing(
            ['CALL ME', 'ready to talk'],
            array_merge(
                $message['reply_handling']['intents'][0]['exact'],
                $message['reply_handling']['intents'][0]['keywords'],
            ),
        );

        $this->actingAs($user)
            ->patch(route('crm.campaigns.messages.reply-handling.update', [
                'campaign' => $campaign,
                'messageChainStepVariant' => $firstVariant,
            ]), [
                'message_chain_version_id' => $firstVersion->getKey(),
                'reply_profile_key' => '',
            ])
            ->assertRedirect(route('crm.campaigns.edit', [
                'campaign' => $campaign,
                'panel' => 'messages',
            ]));

        $currentVersion = $campaign->messageChain()->firstOrFail()->requireCurrentVersion();
        $currentVariant = $currentVersion->steps()->firstOrFail()->variants()->firstOrFail();

        $this->assertNotSame($firstVersion->getKey(), $currentVersion->getKey());
        $this->assertNull($currentVariant->reply_profile_key);
        $this->assertSame($profile->key, $firstVariant->fresh()->reply_profile_key);
    }

    private function profile(): InboundReplyProfile
    {
        $profile = InboundReplyProfile::query()->create([
            'key' => 'fixture_nurture',
            'label' => 'Fixture nurture replies',
            'description' => 'Fixture reply behavior.',
            'is_active' => true,
            'source' => 'database',
            'is_customized' => true,
        ]);
        $intent = $profile->intents()->create([
            'key' => 'high_intent',
            'label' => 'High intent',
            'is_active' => true,
            'sort_order' => 10,
        ]);
        $intent->rules()->createMany([
            [
                'match_type' => 'exact',
                'value' => 'CALL ME',
                'normalized_value' => 'call me',
                'is_active' => true,
                'sort_order' => 10,
            ],
            [
                'match_type' => 'keyword',
                'value' => 'ready to talk',
                'normalized_value' => 'ready to talk',
                'is_active' => true,
                'sort_order' => 20,
            ],
        ]);

        return $profile->refresh();
    }

    /** @return array{0: Campaign, 1: MessageChainVersion} */
    private function campaignWithPublishedMessage(string $replyProfileKey): array
    {
        $preset = MessageTemplatePreset::factory()->create([
            'key' => 'fixture.reply_handling.email',
            'name' => 'Fixture reply handling email',
            'channel' => 'email',
            'purpose' => 'marketing',
            'scope' => 'fixture_nurture',
            'message_type' => 'fixture_reply_handling_email',
            'payload_class' => EmailPayload::class,
            'queue' => 'marketing',
            'dispatch_keys' => ['campaign_step_due'],
            'payload' => [
                'subject' => 'Fixture subject',
                'body' => 'Fixture body.',
            ],
        ]);
        MessageTemplateCatalogEntry::factory()->forPreset($preset)->create([
            'channel' => 'email',
            'purpose' => 'marketing',
            'scope' => 'fixture_nurture',
            'module_key' => 'campaigns',
            'module_label' => 'Campaigns',
            'surface' => 'campaigns',
            'group_key' => 'campaign:reply_handling_fixture',
            'group_label' => 'Reply handling fixture',
            'item_key' => $preset->key,
            'item_label' => $preset->name,
            'usage_type' => 'campaign_step',
            'is_active' => true,
        ]);
        $template = MessageTemplate::query()->create([
            'key' => $preset->key,
            'name' => $preset->name,
            'channel' => 'email',
            'status' => MessageTemplate::STATUS_ACTIVE,
            'source' => 'test',
            'is_customized' => false,
        ]);
        $templateVersion = app(PublishMessageTemplateVersionAction::class)->handle(
            messageTemplate: $template,
            payload: $preset->payload,
        );
        $chain = MessageChain::query()->create([
            'key' => 'campaign.reply_handling_fixture',
            'name' => 'Reply handling fixture',
            'status' => MessageChain::STATUS_ACTIVE,
            'source' => 'test',
            'is_customized' => false,
        ]);
        $version = app(PublishMessageChainVersionAction::class)->handle(
            messageChain: $chain,
            steps: [$this->stepDefinition($templateVersion, $replyProfileKey)],
        );
        $campaign = Campaign::factory()->create([
            'key' => 'reply_handling_fixture',
            'name' => 'Reply handling fixture',
            'message_chain_id' => $chain->getKey(),
            'status' => Campaign::STATUS_ACTIVE,
        ]);

        return [$campaign->refresh(), $version->fresh('steps.variants')];
    }

    /** @return array<string, mixed> */
    private function stepDefinition(
        MessageTemplateVersion $templateVersion,
        string $replyProfileKey,
    ): array {
        return [
            'key' => 'step_1',
            'name' => 'First message',
            'sort_order' => 10,
            'timing_type' => MessageChainStep::TIMING_IMMEDIATE,
            'offset_seconds' => 0,
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
                'scope' => 'fixture_nurture',
                'message_type' => 'fixture_reply_handling_email',
                'queue' => 'marketing',
                'reply_profile_key' => $replyProfileKey,
                'dependency_policy' => [],
                'conditions' => [],
                'is_active' => true,
            ]],
        ];
    }
}