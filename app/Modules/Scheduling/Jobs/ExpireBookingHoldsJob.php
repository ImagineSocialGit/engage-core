<?php

namespace App\Modules\Scheduling\Jobs;

use App\Modules\Scheduling\Models\BookingHold;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ExpireBookingHoldsJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;
    public int $uniqueFor = 55;

    public function uniqueId(): string
    {
        return 'scheduling:expire-booking-holds';
    }

    public function handle(): int
    {
        $now = CarbonImmutable::now('UTC');
        $batchSize = max(
            1,
            (int) config('scheduling.booking_holds.expiration_batch_size', 500),
        );

        $ids = BookingHold::query()
            ->dueForExpiration($now)
            ->orderBy('id')
            ->limit($batchSize)
            ->pluck('id');

        if ($ids->isEmpty()) {
            return 0;
        }

        return BookingHold::query()
            ->whereKey($ids->all())
            ->where('status', BookingHold::STATUS_ACTIVE)
            ->where('expires_at', '<=', $now)
            ->update([
                'status' => BookingHold::STATUS_EXPIRED,
                'updated_at' => $now,
            ]);
    }
}