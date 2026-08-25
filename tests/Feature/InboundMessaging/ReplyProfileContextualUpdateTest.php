<?php

namespace Tests\Feature\InboundMessaging;

use App\Models\User;
use App\Modules\InboundMessaging\Models\InboundReplyProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReplyProfileContextualUpdateTest extends TestCase
{
    use RefreshDatabase;

    public function test_contextual_rule_update_returns_safely_and_updates_future_matching_rules(): void
    {
        config()->set('modules.enabled', ['inbound_messaging']);
        $profile = $this->profile();
        $returnTo = '/campaigns/41/edit?panel=messages';

        $this->actingAs(User::factory()->create())
            ->patch(route('crm.inbound-messaging.reply-profiles.update', $profile), [
                'key' => $profile->key,
                'label' => $profile->label,
                'description' => $profile->description,
                'return_to' => $returnTo,
                'reply_editor_profile_key' => $profile->key,
                'intents' => [[
                    'key' => 'high_intent',
                    'label' => 'High intent',
                    'is_active' => true,
                    'exact' => "YES\nCALL ME",
                    'keywords' => 'ready this week',
                ]],
            ])
            ->assertRedirect('http://crm.'.config('app.root_domain').$returnTo);

        $intent = $profile->fresh('intents.rules')->intents->firstOrFail();

        $this->assertEqualsCanonicalizing(
            ['YES', 'CALL ME', 'ready this week'],
            $intent->rules->pluck('value')->all(),
        );
    }

    public function test_contextual_rule_update_rejects_an_external_return_target(): void
    {
        config()->set('modules.enabled', ['inbound_messaging']);
        $profile = $this->profile();

        $this->actingAs(User::factory()->create())
            ->patch(route('crm.inbound-messaging.reply-profiles.update', $profile), [
                'key' => $profile->key,
                'label' => $profile->label,
                'return_to' => 'https://example.test/steal',
                'intents' => [[
                    'key' => 'high_intent',
                    'label' => 'High intent',
                    'is_active' => true,
                    'exact' => 'YES',
                    'keywords' => '',
                ]],
            ])
            ->assertRedirect(route('crm.inbound-messaging.reply-profiles.index', [
                'profile' => $profile->key,
            ]));
    }

    private function profile(): InboundReplyProfile
    {
        $profile = InboundReplyProfile::query()->create([
            'key' => 'contextual_fixture',
            'label' => 'Contextual fixture replies',
            'description' => 'Fixture profile.',
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
            'value' => 'YES',
            'normalized_value' => 'yes',
            'is_active' => true,
            'sort_order' => 10,
        ]);

        return $profile->refresh();
    }
}