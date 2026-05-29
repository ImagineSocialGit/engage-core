<?php

namespace Tests\Feature\Messaging;

use App\Services\Messaging\SmsSendGuard;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class SmsSendGuardTest extends TestCase
{
    public function test_it_allows_sms_when_no_limits_are_exceeded(): void
    {
        $guard = app(SmsSendGuard::class);

        $this->assertTrue(
            $guard->allows('+15555555555', 'Test message', 'test')
        );
    }

    public function test_it_blocks_duplicate_sms_within_duplicate_window(): void
    {
        config(['sms.cooldowns.duplicate_window_minutes' => 15]);

        $guard = app(SmsSendGuard::class);
        $to = '+15555555555';
        $message = 'Test message';

        $guard->record($to, $message, 'test');

        $this->assertFalse(
            $guard->allows($to, $message, 'test')
        );
    }

    public function test_it_blocks_sms_when_phone_daily_limit_is_exceeded(): void
    {
        config(['sms.rate_limits.per_phone_per_day' => 1]);

        $guard = app(SmsSendGuard::class);
        $to = '+15555555555';

        $guard->record($to, 'First message', 'test');

        $this->assertFalse(
            $guard->allows($to, 'Second message', 'test')
        );
    }

    public function test_it_blocks_sms_when_ip_hourly_limit_is_exceeded(): void
    {
        config(['sms.rate_limits.per_ip_per_hour' => 1]);

        $guard = app(SmsSendGuard::class);
        $ip = '203.0.113.10';

        $guard->record('+15555555555', 'First message', 'test', $ip);

        $this->assertFalse(
            $guard->allows('+15555555556', 'Second message', 'test', $ip)
        );
    }

    protected function tearDown(): void
    {
        Cache::flush();
        RateLimiter::clear('sms:phone:daily:+15555555555:'.now()->toDateString());
        RateLimiter::clear('sms:phone:daily:+15555555556:'.now()->toDateString());
        RateLimiter::clear('sms:ip:hourly:203.0.113.10');

        parent::tearDown();
    }
}