<?php

namespace Tests\Feature\InboundMessaging;

use App\Modules\InboundMessaging\Actions\ReplyProfiles\DeleteInboundReplyProfileAction;
use App\Modules\InboundMessaging\Actions\ReplyProfiles\SaveInboundReplyProfileAction;
use App\Modules\InboundMessaging\Actions\ReplyProfiles\SyncInboundReplyProfilesAction;
use App\Modules\InboundMessaging\Models\InboundReplyProfile;
use App\Modules\InboundMessaging\Services\Reply\InboundReplyIntentClassifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InboundReplyProfileSyncTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('inbound_messaging.reply_profiles', []);
        config()->set('messaging.reply_profiles.test_nurture', [
            'label' => 'Test nurture replies',
            'intents' => [
                'high_intent' => [
                    'label' => 'High intent',
                    'exact' => ['YES'],
                    'keywords' => ['call me'],
                ],
                'not_interested' => [
                    'label' => 'Not interested',
                    'exact' => ['NO'],
                    'keywords' => ['not interested'],
                ],
            ],
        ]);
    }

    public function test_config_bootstraps_database_authority_and_classifier_reads_it(): void
    {
        $result = app(SyncInboundReplyProfilesAction::class)->handle();

        $this->assertSame(1, $result['created']);
        $this->assertDatabaseHas('inbound_reply_profiles', [
            'key' => 'test_nurture',
            'source' => 'client_config',
            'is_customized' => false,
        ]);
        $this->assertDatabaseCount('inbound_reply_intents', 2);
        $this->assertDatabaseCount('inbound_reply_rules', 4);

        $classifier = app(InboundReplyIntentClassifier::class);

        $this->assertSame('high_intent', $classifier->classify('test_nurture', 'YES!'));
        $this->assertSame('high_intent', $classifier->classify('test_nurture', 'Please call me today.'));
        $this->assertSame('not_interested', $classifier->classify('test_nurture', 'NO'));
    }

    public function test_customized_profile_is_preserved_until_force_sync(): void
    {
        app(SyncInboundReplyProfilesAction::class)->handle();
        $profile = InboundReplyProfile::query()->where('key', 'test_nurture')->sole();

        app(SaveInboundReplyProfileAction::class)->handle([
            'key' => 'test_nurture',
            'label' => 'Custom nurture replies',
            'intents' => [
                'high_intent' => [
                    'label' => 'High intent',
                    'is_active' => true,
                    'exact' => ['READY'],
                    'keywords' => ['ready now'],
                ],
            ],
        ], $profile);

        $result = app(SyncInboundReplyProfilesAction::class)->handle();

        $this->assertSame(1, $result['customized_skipped']);
        $this->assertSame(
            'Custom nurture replies',
            $profile->refresh()->label,
        );
        $this->assertSame(
            'high_intent',
            app(InboundReplyIntentClassifier::class)->classify('test_nurture', 'READY'),
        );

        $forced = app(SyncInboundReplyProfilesAction::class)->handle(force: true);

        $this->assertSame(1, $forced['updated']);
        $this->assertSame('Test nurture replies', $profile->refresh()->label);
    }

    public function test_removed_config_profile_is_not_recreated_by_sync(): void
    {
        app(SyncInboundReplyProfilesAction::class)->handle();
        $profile = InboundReplyProfile::query()->where('key', 'test_nurture')->sole();

        app(DeleteInboundReplyProfileAction::class)->handle($profile);
        $result = app(SyncInboundReplyProfilesAction::class)->handle();

        $this->assertSame(1, $result['removed_skipped']);
        $this->assertTrue(
            InboundReplyProfile::withTrashed()->where('key', 'test_nurture')->sole()->trashed(),
        );
        $this->assertNull(
            app(InboundReplyIntentClassifier::class)->classify('test_nurture', 'YES'),
        );
    }
}