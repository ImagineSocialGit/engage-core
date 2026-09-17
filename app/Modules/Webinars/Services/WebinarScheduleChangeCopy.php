<?php

namespace App\Modules\Webinars\Services;

use App\Modules\Webinars\Models\WebinarScheduleChange;
use Illuminate\Support\Carbon;

class WebinarScheduleChangeCopy
{
    public function labels(WebinarScheduleChange $change): array
    {
        return [
            'previous' => $this->format($change->previous_starts_at, $change->previous_timezone),
            'current' => $this->format($change->current_starts_at, $change->current_timezone),
        ];
    }

    private function format(Carbon $instant, string $timezone): string
    {
        $zone = app(WebinarTimezoneResolver::class)->resolve($timezone);
        $label = match ($zone) {
            'America/New_York' => 'Eastern',
            'America/Chicago' => 'Central',
            'America/Denver' => 'Mountain',
            'America/Los_Angeles' => 'Pacific',
            default => $zone,
        };

        return $instant->copy()->setTimezone($zone)->format('M j, Y \a\t g:i A').' '.$label;
    }
}