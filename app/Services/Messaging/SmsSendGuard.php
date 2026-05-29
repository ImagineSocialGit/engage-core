<?php

namespace App\Services\Messaging;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;

class SmsSendGuard
{
    public function allows(
        string $to,
        string $message,
        string $kind,
        ?string $sourceIp = null,
    ): bool {
        if (Cache::has($this->duplicateKey($to, $message))) {
            Log::warning('Duplicate SMS suppressed.', compact('kind', 'to'));

            return false;
        }

        if (RateLimiter::tooManyAttempts(
            $this->phoneKey($to),
            (int) config('sms.rate_limits.per_phone_per_day', 10)
        )) {
            Log::warning('SMS per-phone daily limit exceeded.', compact('kind', 'to'));

            return false;
        }

        if ($sourceIp && RateLimiter::tooManyAttempts(
            $this->ipKey($sourceIp),
            (int) config('sms.rate_limits.per_ip_per_hour', 5)
        )) {
            Log::warning('SMS per-IP hourly limit exceeded.', [
                'kind' => $kind,
                'ip' => $sourceIp,
            ]);

            return false;
        }

        $dailyKey = $this->dailySendCountKey();

        $count = (int) Cache::get($dailyKey, 0);

        if ($count >= (int) config('sms.monitoring.daily_send_hard_limit', 2000)) {
            Log::critical('SMS daily hard limit reached.', [
                'count' => $count,
                'kind' => $kind,
            ]);

            return false;
        }

        return true;
    }

    public function record(
        string $to,
        string $message,
        string $kind,
        ?string $sourceIp = null,
    ): void {
        Cache::put(
            $this->duplicateKey($to, $message),
            true,
            now()->addMinutes((int) config('sms.cooldowns.duplicate_window_minutes', 15))
        );

        RateLimiter::hit(
            $this->phoneKey($to),
            now()->diffInSeconds(now()->endOfDay())
        );

        if ($sourceIp) {
            RateLimiter::hit($this->ipKey($sourceIp), 3600);
        }

        $dailyKey = $this->dailySendCountKey();

        $count = Cache::increment($dailyKey);

        if ($count === 1) {
            Cache::put($dailyKey, $count, now()->endOfDay());
        }

        if ($count === (int) config('sms.monitoring.daily_send_alert_threshold', 500)) {
            Log::warning('SMS daily send alert threshold reached.', [
                'threshold' => $count,
                'kind' => $kind,
            ]);
        }
    }

    private function duplicateKey(string $to, string $message): string
    {
        return 'sms:duplicate:'.hash('sha256', $to.'|'.$message);
    }

    private function phoneKey(string $to): string
    {
        return 'sms:phone:daily:'.$to.':'.now()->toDateString();
    }

    private function ipKey(string $ip): string
    {
        return 'sms:ip:hourly:'.$ip;
    }

    private function dailySendCountKey(): string
    {
        return 'sms:daily-send-count:'.now()->toDateString();
    }
}