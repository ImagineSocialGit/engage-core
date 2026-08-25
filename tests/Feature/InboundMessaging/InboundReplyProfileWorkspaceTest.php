<?php

namespace Tests\Feature\InboundMessaging;

use App\Models\User;
use App\Modules\InboundMessaging\Models\InboundReplyProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InboundReplyProfileWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    public function test_workspace_exposes_authoritative_profile_rules_and_actions(): void
    {
        config()->set('modules.enabled', ['inbound_messaging']);
        $profile = $this->profile();

        $this->actingAs(User::factory()->create())
            ->get(route('crm.inbound-messaging.reply-profiles.index', [
                'profile' => $profile->key,
            ]))
            ->assertOk()
            ->assertSee('data-reply-handling-workspace', false)
            ->assertSee('data-reply-profile-editor', false)
            ->assertSee('data-reply-profile-dependencies', false)
            ->assertSee('data-reply-intent-editor', false)
            ->assertSee(route('crm.inbound-messaging.reply-profiles.update', $profile), false)
            ->assertSee(route('crm.inbound-messaging.reply-profiles.state', $profile), false)
            ->assertSee(route('crm.inbound-messaging.reply-profiles.destroy', $profile), false)
            ->assertSee('High intent')
            ->assertSee('CALL ME');
    }

    public function test_workspace_can_create_a_database_owned_profile(): void
    {
        config()->set('modules.enabled', ['inbound_messaging']);

        $this->actingAs(User::factory()->create())
            ->post(route('crm.inbound-messaging.reply-profiles.store'), [
                'form_mode' => 'create',
                'key' => 'past_client_nurture',
                'label' => 'Past client nurture replies',
                'intents' => [[
                    'key' => 'high_intent',
                    'label' => 'High intent',
                    'is_active' => true,
                    'exact' => "YES\nCALL ME",
                    'keywords' => 'ready to move',
                ]],
            ])
            ->assertRedirect(route('crm.inbound-messaging.reply-profiles.index', [
                'profile' => 'past_client_nurture',
            ]));

        $this->assertDatabaseHas('inbound_reply_profiles', [
            'key' => 'past_client_nurture',
            'source' => 'database',
            'is_customized' => true,
        ]);
        $this->assertDatabaseHas('inbound_reply_intents', [
            'key' => 'high_intent',
            'label' => 'High intent',
        ]);
        $this->assertDatabaseHas('inbound_reply_rules', [
            'match_type' => 'keyword',
            'normalized_value' => 'ready to move',
        ]);
    }

    private function profile(): InboundReplyProfile
    {
        $profile = InboundReplyProfile::query()->create([
            'key' => 'cold_lead_nurture',
            'label' => 'Cold lead nurture replies',
            'description' => 'Recognizes replies to cold-lead nurture messages.',
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
        $intent->rules()->create([
            'match_type' => 'exact',
            'value' => 'CALL ME',
            'normalized_value' => 'call me',
            'is_active' => true,
            'sort_order' => 10,
        ]);

        return $profile->refresh();
    }
}