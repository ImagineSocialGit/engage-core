<?php

namespace Tests\Feature\ProjectState;

use App\Modules\InboundMessaging\Models\InboundReplyProfile;
use App\Support\ProjectState\ProjectStateManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class InboundReplyProfileProjectStateRoundTripTest extends TestCase
{
    use RefreshDatabase;

    public function test_reply_profile_intents_and_rules_round_trip_by_stable_identity(): void
    {
        config()->set('client.key', 'test-client');
        config()->set('project_state.enforce_client_key', true);

        $profile = InboundReplyProfile::query()->create([
            'key' => 'cold_lead_nurture',
            'label' => 'Cold lead nurture replies',
            'is_active' => true,
            'source' => 'database',
            'is_customized' => true,
            'customized_at' => now()->startOfSecond(),
        ]);
        $intent = $profile->intents()->create([
            'key' => 'high_intent',
            'label' => 'High intent',
            'is_active' => true,
            'sort_order' => 10,
        ]);
        $intent->rules()->create([
            'match_type' => 'keyword',
            'value' => 'call me',
            'normalized_value' => 'call me',
            'is_active' => true,
            'sort_order' => 10,
        ]);

        $projectState = app(ProjectStateManager::class);
        $document = $projectState->export();

        $this->assertCount(
            1,
            $document['sections']['inbound_messaging']['tables']['inbound_reply_profiles'],
        );
        $this->assertCount(
            1,
            $document['sections']['inbound_messaging']['tables']['inbound_reply_intents'],
        );
        $this->assertCount(
            1,
            $document['sections']['inbound_messaging']['tables']['inbound_reply_rules'],
        );

        DB::table('inbound_reply_rules')->delete();
        DB::table('inbound_reply_intents')->delete();
        DB::table('inbound_reply_profiles')->delete();

        $report = $projectState->validate($document);
        $this->assertTrue($report['valid'], implode(' ', $report['errors']));
        $this->assertTrue($projectState->import($document)['applied']);

        $restored = InboundReplyProfile::query()
            ->with('intents.rules')
            ->where('key', 'cold_lead_nurture')
            ->sole();

        $this->assertSame('high_intent', $restored->intents->sole()->key);
        $this->assertSame('call me', $restored->intents->sole()->rules->sole()->value);
        $this->assertTrue($restored->is_customized);
    }
}