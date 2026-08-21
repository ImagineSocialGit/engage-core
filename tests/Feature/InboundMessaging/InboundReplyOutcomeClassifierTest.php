<?php

namespace Tests\Feature\InboundMessaging;

use App\Modules\InboundMessaging\Services\Reply\InboundReplyIntentClassifier;
use Tests\TestCase;

class InboundReplyOutcomeClassifierTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('messaging.reply_profiles.test_outcomes', [
            'intents' => [
                'yes' => [
                    'exact' => ['yes'],
                    'keywords' => ['absolutely'],
                ],
                'later' => [
                    'exact' => ['later'],
                    'keywords' => ['maybe later'],
                ],
                'no' => [
                    'exact' => ['no'],
                    'keywords' => ['not interested'],
                ],
            ],
        ]);
    }

    public function test_exact_reply_outcomes_win_before_keyword_matching(): void
    {
        $classifier = app(InboundReplyIntentClassifier::class);

        $this->assertSame('yes', $classifier->classify('test_outcomes', 'YES!'));
        $this->assertSame('later', $classifier->classify('test_outcomes', 'Later.'));
        $this->assertSame('no', $classifier->classify('test_outcomes', 'NO'));
        $this->assertSame(
            'no',
            $classifier->classify('test_outcomes', 'I am not interested right now.'),
        );
    }

    public function test_short_no_does_not_match_inside_an_unrelated_sentence(): void
    {
        $this->assertNull(
            app(InboundReplyIntentClassifier::class)->classify(
                'test_outcomes',
                'No problem, call me tomorrow.',
            ),
        );
    }
}