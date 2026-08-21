<?php

namespace Tests\Feature\Messaging;

use App\Modules\Messaging\Validation\MessagingSetupValidationContributor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class ReplyProfileSetupValidationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('messaging.email.definitions', []);
        Config::set('messaging.sms.definitions', []);
    }

    public function test_exact_only_reply_intent_is_valid(): void
    {
        Config::set('messaging.reply_profiles', [
            'test_profile' => [
                'intents' => [
                    'later' => [
                        'exact' => ['LATER'],
                    ],
                ],
            ],
        ]);

        $findings = collect(app(MessagingSetupValidationContributor::class)->findings())
            ->where('code', 'messaging.reply_profile_intent_invalid')
            ->values();

        $this->assertCount(0, $findings);
    }

    public function test_reply_intent_requires_at_least_one_exact_or_keyword_value(): void
    {
        Config::set('messaging.reply_profiles', [
            'test_profile' => [
                'intents' => [
                    'empty' => [],
                ],
            ],
        ]);

        $finding = collect(app(MessagingSetupValidationContributor::class)->findings())
            ->firstWhere('code', 'messaging.reply_profile_intent_invalid');

        $this->assertNotNull($finding);
        $this->assertSame('error', $finding->severity);
    }

    public function test_reply_intent_rejects_blank_exact_values(): void
    {
        Config::set('messaging.reply_profiles', [
            'test_profile' => [
                'intents' => [
                    'later' => [
                        'exact' => [''],
                    ],
                ],
            ],
        ]);

        $finding = collect(app(MessagingSetupValidationContributor::class)->findings())
            ->firstWhere('code', 'messaging.reply_profile_intent_invalid');

        $this->assertNotNull($finding);
        $this->assertSame('error', $finding->severity);
    }
}