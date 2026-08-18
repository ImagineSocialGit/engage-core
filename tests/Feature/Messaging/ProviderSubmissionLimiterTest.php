<?php

namespace Tests\Feature\Messaging;

use App\Modules\Messaging\Services\ProviderSubmissionLimiter;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Sleep;
use Tests\TestCase;

class ProviderSubmissionLimiterTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-08-18 18:00:00 UTC');
        Cache::store('array')->clear();
        Sleep::fake(syncWithCarbon: true);

        config()->set('messaging.delivery.provider_rate_limits.cache_store', 'array');
        config()->set('messaging.delivery.provider_rate_limits.email.resend', [
            'enabled' => true,
            'max_requests' => 2,
            'decay_seconds' => 1,
            'scope' => 'test-team',
        ]);
    }

    protected function tearDown(): void
    {
        Sleep::fake(false);
        Carbon::setTestNow();
        Cache::store('array')->clear();

        parent::tearDown();
    }

    public function test_shared_provider_limit_waits_before_exceeding_the_configured_window(): void
    {
        $limiter = app(ProviderSubmissionLimiter::class);

        $limiter->acquire('email', 'resend');
        $limiter->acquire('email', 'resend');
        $limiter->acquire('email', 'resend');

        Sleep::assertSleptTimes(1);
        $this->assertSame(
            '2026-08-18 18:00:01',
            now('UTC')->toDateTimeString(),
        );
    }

    public function test_unconfigured_provider_does_not_consume_or_wait_on_a_limit(): void
    {
        app(ProviderSubmissionLimiter::class)->acquire('email', 'lease_test');

        Sleep::assertNeverSlept();
    }

    public function test_disabled_provider_limit_is_a_no_op(): void
    {
        config()->set('messaging.delivery.provider_rate_limits.email.resend.enabled', false);

        $limiter = app(ProviderSubmissionLimiter::class);

        $limiter->acquire('email', 'resend');
        $limiter->acquire('email', 'resend');
        $limiter->acquire('email', 'resend');

        Sleep::assertNeverSlept();
    }
}