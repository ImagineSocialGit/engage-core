<?php

namespace App\Services\Messaging;

use App\Data\WebinarMessageData;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;

class SmsSendGuard
{
    public function allows(WebinarMessageData $data, string $to, string $message, string $kind): bool
    {
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

        $ip = $this->sourceIp($data);

        if ($ip && RateLimiter::tooManyAttempts(
            $this->ipKey($ip),
            (int) config('sms.rate_limits.per_ip_per_hour', 5)
        )) {
            Log::warning('SMS per-IP hourly limit exceeded.', compact('kind', 'ip'));

            return false;
        }

        $dailyKey = 'sms:daily-send-count:'.now()->toDateString();

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

    public function record(WebinarMessageData $data, string $to, string $message, string $kind): void
    {
        Cache::put(
            $this->duplicateKey($to, $message),
            true,
            now()->addMinutes((int) config('sms.cooldowns.duplicate_window_minutes', 15))
        );

        RateLimiter::hit(
            $this->phoneKey($to),
            now()->diffInSeconds(now()->endOfDay())
        );

        if ($ip = $this->sourceIp($data)) {
            RateLimiter::hit($this->ipKey($ip), 3600);
        }

        $dailyKey = 'sms:daily-send-count:'.now()->toDateString();

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

    private function sourceIp(WebinarMessageData $data): ?string
    {
        return $data->requestIp;
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
}