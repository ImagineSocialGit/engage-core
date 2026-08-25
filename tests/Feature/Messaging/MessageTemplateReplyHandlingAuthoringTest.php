<?php

namespace Tests\Feature\Messaging;

use App\Http\Middleware\ForceStagingAccess;
use App\Models\User;
use App\Modules\InboundMessaging\Models\InboundReplyProfile;
use App\Modules\Messaging\Models\MessageTemplateCatalogEntry;
use App\Modules\Messaging\Models\MessageTemplatePreset;
use App\Modules\Messaging\Models\MessageTemplatePresetAssignment;
use App\Modules\Messaging\Payloads\EmailPayload;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class MessageTemplateReplyHandlingAuthoringTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('modules.enabled', [
            'workflow',
            'flow_routes',
            'messaging',
            'inbound_messaging',
            'campaigns',
        ]);

        $this->withoutMiddleware(ForceStagingAccess::class);
    }

    public function test_reusable_message_exposes_assignment_owned_reply_handling_and_owner_links(): void
    {
        $user = User::factory()->create();
        $profile = $this->replyProfile();
        $flowRouteId = $this->replyFlowRoute($profile->key, 'high_intent');

        $preset = MessageTemplatePreset::factory()->create([
            'key' => 'fixture.reply_aware.email',
            'name' => 'Fixture reply-aware email',
            'channel' => 'email',
            'purpose' => 'marketing',
            'scope' => 'fixture_nurture',
            'message_type' => 'fixture_reply_aware_email',
            'payload_class' => EmailPayload::class,
            'queue' => 'marketing',
            'dispatch_keys' => ['campaign_step_due'],
            'payload' => [
                'subject' => 'Fixture subject',
                'body' => 'Fixture body.',
            ],
        ]);

        MessageTemplateCatalogEntry::factory()
            ->forPreset($preset)
            ->create([
                'channel' => 'email',
                'purpose' => 'marketing',
                'scope' => 'fixture_nurture',
                'module_key' => 'campaigns',
                'module_label' => 'Campaigns',
                'surface' => 'campaigns',
                'group_key' => 'campaign:reply_aware_fixture',
                'group_label' => 'Reply-aware fixture',
                'item_key' => $preset->key,
                'item_label' => $preset->name,
                'usage_type' => 'campaign_step',
                'is_active' => true,
                'meta' => [
                    'campaign_key' => 'fixture_nurture',
                    'campaign_step' => 4,
                    'campaign_step_variant_key' => 'email_primary',
                ],
            ]);

        $assignment = MessageTemplatePresetAssignment::factory()
            ->forPreset($preset)
            ->forCampaignStepVariant(
                campaignKey: 'fixture_nurture',
                stepNumber: 4,
                variantKey: 'email_primary',
            )
            ->create([
                'reply_profile_key' => $profile->key,
            ]);

        $ownerUrl = route('crm.campaigns.message-templates.index', [
            'campaign' => 'fixture_nurture',
            'step' => 4,
        ]);
        $flowRouteUrl = route('crm.flow-routes.show', $flowRouteId);

        $response = $this->actingAs($user)
            ->get(route('crm.messaging.message-templates.index', [
                'channel' => 'email',
                'purpose' => 'marketing',
                'module' => 'campaigns',
                'group' => 'campaign:reply_aware_fixture',
                'preset' => $preset->getKey(),
            ]));

        $response
            ->assertOk()
            ->assertViewIs('crm.messaging.message-templates.index')
            ->assertSee('data-message-reply-handling', false)
            ->assertSee('data-message-reply-editor-form', false)
            ->assertSee('data-message-reply-handling-usages', false)
            ->assertSee('data-message-reply-usage-link', false)
            ->assertSee('data-message-reply-dependency-link', false)
            ->assertSee($ownerUrl)
            ->assertSee($flowRouteUrl, false)
            ->assertSee(route('crm.inbound-messaging.reply-profiles.update', $profile), false);

        $message = collect($response->viewData('messageLibrary')['channels'])
            ->flatMap(fn (array $channel): array => $channel['messages'])
            ->firstWhere('preset_id', $preset->getKey());

        $this->assertIsArray($message);
        $this->assertSame($profile->key, $message['reply_profile_key']);
        $this->assertSame($profile->key, $message['reply_handling']['key']);
        $this->assertSame(
            $assignment->getKey(),
            $message['reply_handling_usages'][0]['assignment_id'],
        );
        $this->assertSame(
            $profile->key,
            $message['reply_handling_usages'][0]['reply_profile_key'],
        );
        $this->assertSame(
            $ownerUrl,
            $message['reply_handling_usages'][0]['owner_url'],
        );
        $this->assertEqualsCanonicalizing(
            ['CALL ME', 'ready to talk'],
            array_merge(
                $message['reply_handling']['intents'][0]['exact'],
                $message['reply_handling']['intents'][0]['keywords'],
            ),
        );
        $this->assertTrue(collect($message['reply_handling']['dependencies'])->contains(
            fn (array $dependency): bool =>
                ($dependency['module_key'] ?? null) === 'flow_routes'
                && ($dependency['intent_key'] ?? null) === 'high_intent'
                && ($dependency['url'] ?? null) === $flowRouteUrl,
        ));

        $this->assertFalse(in_array(
            'reply_profile_key',
            $preset->getFillable(),
            true,
        ));
    }

    private function replyProfile(): InboundReplyProfile
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

    private function replyFlowRoute(string $profileKey, string $intentKey): int
    {
        $routeId = DB::table('flow_routes')->insertGetId([
            'key' => 'fixture_reply_follow_up',
            'name' => 'Fixture reply follow-up',
            'version' => 1,
            'is_current_version' => true,
            'trigger_type' => 'automation_event',
            'trigger_key' => 'inbound_message.normal_reply',
            'is_active' => true,
            'is_customized' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('flow_route_points')->insert([
            'flow_route_id' => $routeId,
            'key' => 'match_reply',
            'type' => 'branch_evaluate',
            'name' => 'Match reply',
            'sort_order' => 10,
            'is_start' => true,
            'is_active' => true,
            'definition' => json_encode([
                'conditions' => [
                    [
                        'path' => 'automation_event.payload.inbound_message.reply_profile_key',
                        'operator' => 'equals',
                        'value' => $profileKey,
                    ],
                    [
                        'path' => 'automation_event.payload.inbound_message.reply_intent_key',
                        'operator' => 'equals',
                        'value' => $intentKey,
                    ],
                ],
            ]),
            'settings' => json_encode([]),
            'cancel_conditions' => json_encode([]),
            'is_customized' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $routeId;
    }
}