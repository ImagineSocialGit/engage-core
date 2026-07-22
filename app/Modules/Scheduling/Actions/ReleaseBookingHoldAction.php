<?php

namespace App\Modules\Scheduling\Actions;

use App\Modules\Scheduling\Models\BookingHold;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class ReleaseBookingHoldAction
{
    public function handle(string $holdId): BookingHold
    {
        $holdId = $this->requiredHoldId($holdId);

        return DB::transaction(function () use ($holdId): BookingHold {
            $hold = BookingHold::query()
                ->where('hold_id', $holdId)
                ->lockForUpdate()
                ->first();

            if (! $hold instanceof BookingHold) {
                throw new DomainException('The booking hold could not be found.');
            }

            if ($hold->isConverted()) {
                throw new DomainException(
                    'A converted booking hold cannot be released.',
                );
            }

            if ($hold->isReleased() || $hold->isExpired()) {
                return $hold;
            }

            $now = CarbonImmutable::now('UTC');

            if (! $hold->isEffectivelyActive($now)) {
                $hold->forceFill([
                    'status' => BookingHold::STATUS_EXPIRED,
                ])->save();

                return $hold->refresh();
            }

            $hold->forceFill([
                'status' => BookingHold::STATUS_RELEASED,
                'released_at' => $now,
            ])->save();

            return $hold->refresh();
        });
    }

    private function requiredHoldId(string $holdId): string
    {
        $holdId = trim($holdId);

        if ($holdId === '') {
            throw new InvalidArgumentException(
                'A non-empty booking hold ID is required.',
            );
        }

        if (mb_strlen($holdId) > 36) {
            throw new InvalidArgumentException(
                'The booking hold ID cannot exceed 36 characters.',
            );
        }

        return $holdId;
    }
}