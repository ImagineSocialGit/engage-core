<?php

namespace Tests\Feature\Core;

use App\Models\User;
use App\Modules\Campaigns\Models\Campaign;
use App\Modules\Messaging\Actions\PublishMessageChainVersionAction;
use App\Modules\Messaging\Actions\PublishMessageTemplateVersionAction;
use App\Modules\Messaging\Models\MessageChain;
use App\Modules\Messaging\Models\MessageChainStep;
use App\Modules\Messaging\Models\MessageTemplate;
use App\Support\ProcessHighway\ProcessHighwayReadService;
use App\Support\ProcessHighway\ProcessHighwaySemanticKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ProcessHighwayBusinessProcessAcceptanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_slam_dunk_shaped_processes_compose_as_business_highways(): void
    {
        config()->set('modules.enabled', [
            'core',
            'campaigns',
            'messaging',
            'inbound_messaging',
            'flow_routes',
            'tasks',
            'relationships',
            'webinars',
            'workflow',
        ]);

        $this->contactStatus('prospect_nurture', 'Prospect – Nurture', 10);
        $this->contactStatus('past_contact', 'Past Client', 20);

        $coldCampaign = $this->campaign(
            key: 'cold_lead_nurture',
            name: 'Cold Lead Nurture',
            eligibility: [
                'status' => ['prospect_nurture'],
                'tag' => ['Old Lead'],
            ],
            replyProfileKey: 'cold_lead_nurture',
        );
        $pastCampaign = $this->campaign(
            key: 'past_client_nurture',
            name: 'Past Client Nurture',
            eligibility: [
                'status' => ['past_contact'],
            ],
            replyProfileKey: 'past_client_nurture',
        );
        $attendedCampaign = $this->campaign(
            key: 'va_webinar_attended_nurture',
            name: 'VA Webinar Attended Nurture',
            eligibility: [
                'webinar_outcome' => ['va-homebuyer-game-plan:attended'],
            ],
            replyProfileKey: 'webinar_homebuyer',
        );
        $missedCampaign = $this->campaign(
            key: 'va_webinar_missed_nurture',
            name: 'VA Webinar Missed Nurture',
            eligibility: [
                'webinar_outcome' => ['va-homebuyer-game-plan:missed'],
            ],
            replyProfileKey: 'webinar_homebuyer',
        );
        $campaignOnly = $this->campaign(
            key: 'campaign_only_fixture',
            name: 'Campaign Only Fixture',
            eligibility: [
                'tag' => ['Campaign Only'],
            ],
        );

        $coldRouteId = $this->replyRoute(
            key: 'cold_lead_high_intent_reply_routing',
            name: 'Cold Lead High Intent Reply',
            replyProfileKey: 'cold_lead_nurture',
        );
        $pastRouteId = $this->replyRoute(
            key: 'past_client_high_intent_reply_follow_up',
            name: 'Past Client High Intent Reply',
            replyProfileKey: 'past_client_nurture',
        );
        $webinarRouteId = $this->replyRoute(
            key: 'webinar_high_intent_reply_routing',
            name: 'Webinar High Intent Reply',
            replyProfileKey: 'webinar_homebuyer',
        );
        $realtorRouteId = $this->replyRoute(
            key: 'realtor_high_intent_reply_routing',
            name: 'Realtor Positive Reply',
            replyProfileKey: 'realtor_outreach',
            relationshipKey: 'realtor',
        );

        $graph = app(ProcessHighwayReadService::class)->read();
        $highways = collect($graph['highways']);
        $coldCampaignKey = ProcessHighwaySemanticKey::campaign((string) $coldCampaign->key);
        $coldRouteKey = ProcessHighwaySemanticKey::flowRoute('cold_lead_high_intent_reply_routing');
        $coldReplyKey = ProcessHighwaySemanticKey::replyProfile('cold_lead_nurture');
        $coldHighway = $highways->first(
            fn (array $highway): bool => in_array($coldCampaignKey, $highway['segment_keys'], true),
        );

        $this->assertNotNull($coldHighway);
        $this->assertSame([
            $coldCampaignKey,
            $coldRouteKey,
        ], $coldHighway['segment_keys']);
        $this->assertEqualsCanonicalizing([
            ProcessHighwaySemanticKey::status('prospect_nurture'),
            ProcessHighwaySemanticKey::tag('Old Lead'),
        ], $coldHighway['entry_node_keys']);
        $this->assertEqualsCanonicalizing([
            'status' => ['prospect_nurture'],
            'tag' => ['Old Lead'],
        ], $coldHighway['qualifiers']);
        $this->assertFalse(in_array($coldReplyKey, $coldHighway['entry_node_keys'], true));
        $this->assertSame(
            'eligibility_program',
            $coldHighway['segments'][0]['attributes']['mechanism_role'],
        );
        $this->assertSame(
            'procedural_orchestration',
            $coldHighway['segments'][1]['attributes']['mechanism_role'],
        );
        $this->assertSame(
            route('crm.campaigns.edit', $coldCampaign),
            $coldHighway['segments'][0]['navigation_target']['url'],
        );
        $this->assertSame(
            route('crm.flow-routes.show', $coldRouteId),
            $coldHighway['segments'][1]['navigation_target']['url'],
        );

        $coldReplyNode = collect($graph['nodes'])->firstWhere('key', $coldReplyKey);

        $this->assertNotNull($coldReplyNode);
        $this->assertSame('inbound_messaging', $coldReplyNode['authority']['owner_key']);
        $this->assertEqualsCanonicalizing([
            $coldCampaignKey,
            $coldRouteKey,
        ], $coldReplyNode['segment_keys']);
        $this->assertTrue(collect($coldReplyNode['authority']['edit_targets'])->contains(
            fn (array $target): bool => $target['owner_key'] === 'inbound_messaging'
                && $target['resource']['type'] === 'reply_profile'
                && $target['resource']['key'] === 'cold_lead_nurture'
                && $target['url'] === route(
                    'crm.inbound-messaging.reply-profiles.index',
                    ['profile' => 'cold_lead_nurture'],
                ),
        ));
        $this->assertTrue(collect($graph['edges'])->contains(
            fn (array $edge): bool => $edge['from_node_key'] === $coldCampaignKey.':journey'
                && $edge['to_node_key'] === $coldReplyKey,
        ));

        $pastHighway = $highways->first(
            fn (array $highway): bool => in_array(
                ProcessHighwaySemanticKey::campaign((string) $pastCampaign->key),
                $highway['segment_keys'],
                true,
            ),
        );

        $this->assertNotNull($pastHighway);
        $this->assertSame([
            ProcessHighwaySemanticKey::status('past_contact'),
        ], $pastHighway['entry_node_keys']);
        $this->assertTrue(in_array(
            ProcessHighwaySemanticKey::flowRoute('past_client_high_intent_reply_follow_up'),
            $pastHighway['segment_keys'],
            true,
        ));
        $this->assertSame(
            route('crm.flow-routes.show', $pastRouteId),
            collect($pastHighway['segments'])
                ->firstWhere('source_key', 'flow_routes')['navigation_target']['url'],
        );

        $webinarHighway = $highways->first(
            fn (array $highway): bool => in_array(
                ProcessHighwaySemanticKey::campaign((string) $attendedCampaign->key),
                $highway['segment_keys'],
                true,
            ),
        );

        $this->assertNotNull($webinarHighway);
        $this->assertTrue(in_array(
            ProcessHighwaySemanticKey::campaign((string) $missedCampaign->key),
            $webinarHighway['segment_keys'],
            true,
        ));
        $this->assertTrue(in_array(
            ProcessHighwaySemanticKey::flowRoute('webinar_high_intent_reply_routing'),
            $webinarHighway['segment_keys'],
            true,
        ));
        $this->assertEqualsCanonicalizing([
            ProcessHighwaySemanticKey::webinarOutcome('va-homebuyer-game-plan', 'attended'),
            ProcessHighwaySemanticKey::webinarOutcome('va-homebuyer-game-plan', 'missed'),
        ], $webinarHighway['entry_node_keys']);
        $this->assertSame(
            route('crm.flow-routes.show', $webinarRouteId),
            collect($webinarHighway['segments'])
                ->firstWhere('source_key', 'flow_routes')['navigation_target']['url'],
        );

        $campaignOnlyHighway = $highways->first(
            fn (array $highway): bool => in_array(
                ProcessHighwaySemanticKey::campaign((string) $campaignOnly->key),
                $highway['segment_keys'],
                true,
            ),
        );

        $this->assertNotNull($campaignOnlyHighway);
        $this->assertSame(1, $campaignOnlyHighway['segment_count']);
        $this->assertSame(['campaigns'], $campaignOnlyHighway['source_keys']);

        $realtorHighway = $highways->first(
            fn (array $highway): bool => in_array(
                ProcessHighwaySemanticKey::flowRoute('realtor_high_intent_reply_routing'),
                $highway['segment_keys'],
                true,
            ),
        );

        $this->assertNotNull($realtorHighway);
        $this->assertSame('contacts:relationship:realtor', $realtorHighway['lane_key']);
        $this->assertSame(
            route('crm.flow-routes.show', $realtorRouteId),
            $realtorHighway['segments'][0]['navigation_target']['url'],
        );
        $this->assertNull(collect($graph['nodes'])->firstWhere(
            'key',
            ProcessHighwaySemanticKey::automationEvent('inbound_message.normal_reply'),
        ));

        $this->actingAs(User::factory()->create())
            ->get(route('crm.process-highway.index'))
            ->assertOk()
            ->assertSee('data-process-highway', false)
            ->assertSee('data-process-highway-segment=', false)
            ->assertSee('data-process-highway-owner=', false);
    }

    private function contactStatus(string $key, string $name, int $sortOrder): void
    {
        DB::table('contact_statuses')->insert([
            'key' => $key,
            'name' => $name,
            'is_core' => false,
            'is_active' => true,
            'is_customized' => false,
            'sort_order' => $sortOrder,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** @param array<string, array<int, string>> $eligibility */
    private function campaign(
        string $key,
        string $name,
        array $eligibility,
        ?string $replyProfileKey = null,
    ): Campaign {
        $campaign = Campaign::factory()->create([
            'key' => $key,
            'name' => $name,
            'status' => Campaign::STATUS_ACTIVE,
            'eligibility_filter' => $eligibility,
            'enrollment_mode' => Campaign::ENROLLMENT_MODE_AUTOMATIC,
            'reentry_policy' => Campaign::REENTRY_NEVER,
            'ineligible_behavior' => Campaign::INELIGIBLE_CANCEL,
        ]);

        if ($replyProfileKey !== null) {
            $this->attachReplyProfile($campaign, $replyProfileKey);
        }

        return $campaign->refresh();
    }

    private function attachReplyProfile(Campaign $campaign, string $replyProfileKey): void
    {
        $template = MessageTemplate::query()->create([
            'key' => 'highway.'.$campaign->key.'.email',
            'name' => $campaign->name.' Email',
            'channel' => 'email',
            'status' => MessageTemplate::STATUS_ACTIVE,
            'source' => 'test',
            'is_customized' => false,
        ]);
        $templateVersion = app(PublishMessageTemplateVersionAction::class)->handle(
            messageTemplate: $template,
            payload: [
                'subject' => 'Fixture subject',
                'body' => 'Fixture body.',
            ],
        );
        $chain = MessageChain::query()->create([
            'key' => 'campaign.'.$campaign->key,
            'name' => $campaign->name,
            'status' => MessageChain::STATUS_ACTIVE,
            'source' => 'test',
            'is_customized' => false,
        ]);

        app(PublishMessageChainVersionAction::class)->handle(
            messageChain: $chain,
            steps: [[
                'key' => 'message_1',
                'name' => 'Message 1',
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
                    'scope' => 'fixture',
                    'message_type' => 'fixture_follow_up',
                    'reply_profile_key' => $replyProfileKey,
                    'queue' => 'marketing',
                    'dependency_policy' => [],
                    'conditions' => [],
                    'is_active' => true,
                ]],
            ]],
        );

        $campaign->forceFill([
            'message_chain_id' => $chain->getKey(),
        ])->save();
    }

    private function replyRoute(
        string $key,
        string $name,
        string $replyProfileKey,
        ?string $relationshipKey = null,
    ): int {
        $routeId = DB::table('flow_routes')->insertGetId([
            'key' => $key,
            'name' => $name,
            'description' => 'Fixture reply orchestration.',
            'version' => 1,
            'is_current_version' => true,
            'trigger_type' => 'automation_event',
            'trigger_key' => 'inbound_message.normal_reply',
            'is_active' => true,
            'is_customized' => false,
            'meta' => json_encode([
                'definition' => [
                    'category' => $relationshipKey === null ? 'consumer_reply' : 'realtor_reply',
                    'role' => 'reply_routing',
                ],
            ]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $actionKey = $relationshipKey === null
            ? 'create_follow_up_task'
            : 'advance_relationship';
        $actionDefinition = $relationshipKey === null
            ? ['task_template_key' => 'fixture.reply_follow_up']
            : [
                'relationship_key' => $relationshipKey,
                'from_stage_key' => 'target_agent',
                'stage_key' => 'engaged_agent',
                'on_missing_relationship' => 'skipped',
            ];
        $actionPointId = DB::table('flow_route_points')->insertGetId([
            'flow_route_id' => $routeId,
            'key' => $actionKey,
            'type' => $relationshipKey === null ? 'create_task' : 'change_relationship_stage',
            'name' => $relationshipKey === null ? 'Create follow-up task' : 'Advance relationship',
            'sort_order' => 20,
            'is_start' => false,
            'is_active' => true,
            'definition' => json_encode($actionDefinition),
            'settings' => json_encode([]),
            'cancel_conditions' => json_encode([]),
            'is_customized' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('flow_route_points')->insert([
            'flow_route_id' => $routeId,
            'key' => 'match_reply_profile',
            'type' => 'branch_evaluate',
            'name' => 'Match reply profile',
            'sort_order' => 10,
            'is_start' => true,
            'is_active' => true,
            'next_flow_route_point_id' => $actionPointId,
            'definition' => json_encode([
                'branches' => [[
                    'conditions' => [[
                        'source' => 'execution_meta',
                        'path' => 'automation_event.payload.inbound_message.reply_profile_key',
                        'operator' => 'equals',
                        'value' => $replyProfileKey,
                    ]],
                    'target_flow_route_point_key' => $actionKey,
                ]],
                'on_no_match' => 'completed',
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