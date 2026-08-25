<?php

namespace Tests\Feature\InboundMessaging;

use App\Modules\FlowRoutes\Services\ReplyProfiles\FlowRouteReplyProfileDependencyContributor;
use App\Modules\Messaging\Models\MessageTemplatePresetAssignment;
use App\Modules\Messaging\Services\ReplyProfiles\MessagingReplyProfileDependencyContributor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ReplyProfileDependencyContributorTest extends TestCase
{
    use RefreshDatabase;

    public function test_messaging_exposes_reply_profile_assignments_as_dependencies(): void
    {
        $assignment = MessageTemplatePresetAssignment::factory()->create([
            'reply_profile_key' => 'cold_lead_nurture',
            'campaign_key' => 'cold_lead_nurture',
            'campaign_step' => 4,
            'is_active' => true,
        ]);

        $dependency = collect(
            app(MessagingReplyProfileDependencyContributor::class)->dependencies(),
        )->firstWhere('key', 'messaging:assignment:'.$assignment->getKey());

        $this->assertNotNull($dependency);
        $this->assertSame('cold_lead_nurture', $dependency->profileKey);
        $this->assertNull($dependency->intentKey);
        $this->assertTrue($dependency->active);
    }

    public function test_flow_route_exposes_profile_and_intent_references_as_one_dependency(): void
    {
        $routeId = DB::table('flow_routes')->insertGetId([
            'key' => 'cold_lead_reply_follow_up',
            'name' => 'Cold lead reply follow-up',
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
                        'value' => 'cold_lead_nurture',
                    ],
                    [
                        'path' => 'automation_event.payload.inbound_message.reply_intent_key',
                        'operator' => 'equals',
                        'value' => 'high_intent',
                    ],
                ],
            ]),
            'settings' => json_encode([]),
            'cancel_conditions' => json_encode([]),
            'is_customized' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $dependencies = collect(
            app(FlowRouteReplyProfileDependencyContributor::class)->dependencies(),
        );
        $dependency = $dependencies->sole();

        $this->assertSame('cold_lead_nurture', $dependency->profileKey);
        $this->assertSame('high_intent', $dependency->intentKey);
        $this->assertSame('flow_routes', $dependency->moduleKey);
        $this->assertSame(route('crm.flow-routes.show', $routeId), $dependency->url);
    }
}