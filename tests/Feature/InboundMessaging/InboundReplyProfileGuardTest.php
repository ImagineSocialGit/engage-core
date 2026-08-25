<?php

namespace Tests\Feature\InboundMessaging;

use App\Modules\InboundMessaging\Actions\ReplyProfiles\DeleteInboundReplyProfileAction;
use App\Modules\InboundMessaging\Actions\ReplyProfiles\SaveInboundReplyProfileAction;
use App\Modules\InboundMessaging\Actions\ReplyProfiles\SetInboundReplyProfileStateAction;
use App\Modules\InboundMessaging\Models\InboundReplyProfile;
use App\Modules\InboundMessaging\Services\ReplyProfiles\ReplyProfileDefinitionNormalizer;
use App\Support\ReplyHandling\Contracts\ReplyProfileDependencyContributor;
use App\Support\ReplyHandling\Data\ReplyProfileDependency;
use App\Support\ReplyHandling\ReplyProfileDependencyRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class InboundReplyProfileGuardTest extends TestCase
{
    use RefreshDatabase;

    public function test_referenced_profile_cannot_be_disabled_or_removed(): void
    {
        $profile = $this->profile();
        $registry = $this->registry();

        try {
            (new SetInboundReplyProfileStateAction($registry))->handle($profile, false);
            $this->fail('Expected referenced profile disablement to be blocked.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('profile', $exception->errors());
        }

        try {
            (new DeleteInboundReplyProfileAction($registry))->handle($profile);
            $this->fail('Expected referenced profile removal to be blocked.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('profile', $exception->errors());
        }

        $this->assertTrue($profile->refresh()->is_active);
        $this->assertFalse($profile->trashed());
    }

    public function test_referenced_intent_cannot_be_removed_but_its_rules_can_change(): void
    {
        $profile = $this->profile();
        $save = new SaveInboundReplyProfileAction(
            new ReplyProfileDefinitionNormalizer(),
            $this->registry(),
        );

        try {
            $save->handle([
                'key' => 'test_nurture',
                'label' => 'Test nurture',
                'intents' => [
                    'later' => [
                        'label' => 'Later',
                        'is_active' => true,
                        'exact' => ['LATER'],
                    ],
                ],
            ], $profile);
            $this->fail('Expected referenced intent removal to be blocked.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('intents', $exception->errors());
        }

        $updated = $save->handle([
            'key' => 'test_nurture',
            'label' => 'Test nurture',
            'intents' => [
                'high_intent' => [
                    'label' => 'High intent',
                    'is_active' => true,
                    'exact' => ['READY'],
                    'keywords' => ['call today'],
                ],
                'later' => [
                    'label' => 'Later',
                    'is_active' => true,
                    'exact' => ['LATER'],
                ],
            ],
        ], $profile);

        $this->assertEqualsCanonicalizing(
            ['READY', 'call today'],
            $updated->intents
                ->firstWhere('key', 'high_intent')
                ->rules
                ->pluck('value')
                ->all(),
        );
    }

    private function profile(): InboundReplyProfile
    {
        $profile = InboundReplyProfile::query()->create([
            'key' => 'test_nurture',
            'label' => 'Test nurture',
            'is_active' => true,
            'source' => 'database',
            'is_customized' => true,
        ]);

        foreach (['high_intent', 'later'] as $index => $key) {
            $intent = $profile->intents()->create([
                'key' => $key,
                'label' => str($key)->headline()->toString(),
                'is_active' => true,
                'sort_order' => ($index + 1) * 10,
            ]);
            $intent->rules()->create([
                'match_type' => 'exact',
                'value' => strtoupper($key),
                'normalized_value' => $key,
                'is_active' => true,
                'sort_order' => 10,
            ]);
        }

        return $profile->refresh();
    }

    private function registry(): ReplyProfileDependencyRegistry
    {
        $contributor = new class implements ReplyProfileDependencyContributor
        {
            public function dependencies(): iterable
            {
                return [new ReplyProfileDependency(
                    key: 'flow_routes:fixture:test_nurture:high_intent',
                    profileKey: 'test_nurture',
                    intentKey: 'high_intent',
                    moduleKey: 'flow_routes',
                    type: 'flow_route',
                    label: 'High-intent follow-up',
                    detail: 'Fixture dependency.',
                    active: true,
                )];
            }
        };

        return new ReplyProfileDependencyRegistry([$contributor]);
    }
}