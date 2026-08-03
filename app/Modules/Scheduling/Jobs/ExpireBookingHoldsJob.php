<?php

namespace App\Modules\Scheduling\Jobs;

use App\Modules\Scheduling\Models\BookingHold;
use App\Modules\Scheduling\Services\Availability\ResourceOccupancyResolver;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

class ExpireBookingHoldsJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;
    public int $uniqueFor = 55;

    public function uniqueId(): string
    {
        return 'scheduling:expire-booking-holds';
    }

    public function handle(?ResourceOccupancyResolver $resourceOccupancy = null): int
    {
        $resourceOccupancy ??= app(ResourceOccupancyResolver::class);
        $now = CarbonImmutable::now('UTC');
        $batchSize = max(
            1,
            (int) config('scheduling.booking_holds.expiration_batch_size', 500),
        );

        return DB::transaction(function () use (
            $resourceOccupancy,
            $now,
            $batchSize,
        ): int {
            $holds = BookingHold::query()
                ->dueForExpiration($now)
                ->orderBy('id')
                ->limit($batchSize)
                ->lockForUpdate()
                ->get();

            if ($holds->isEmpty()) {
                return 0;
            }

            $ids = $holds
                ->modelKeys();

            $resourceOccupancy->deleteForHoldIds($ids);

            return BookingHold::query()
                ->whereKey($ids)
                ->where('status', BookingHold::STATUS_ACTIVE)
                ->where('expires_at', '<=', $now)
                ->update([
                    'status' => BookingHold::STATUS_EXPIRED,
                    'updated_at' => $now,
                ]);
        });
    }
}