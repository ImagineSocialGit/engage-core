<?php

namespace App\Modules\Billing\Services;

use App\Modules\Billing\Models\BillingCollectionRequirement;
use App\Modules\Billing\Models\BillingPrice;
use InvalidArgumentException;

final class BillingCollectionAmountResolver
{
    public function resolve(
        BillingPrice $price,
        BillingCollectionRequirement $requirement,
    ): int {
        $total = (int) $price->amount_cents;

        if ($total < 0) {
            throw new InvalidArgumentException(
                'Billing prices cannot contain a negative amount.',
            );
        }

        return match ($requirement->mode) {
            BillingCollectionRequirement::MODE_NONE => $this->none($total, $requirement),
            BillingCollectionRequirement::MODE_FULL => $this->full($total, $requirement),
            BillingCollectionRequirement::MODE_FIXED_DEPOSIT => $this->fixedDeposit($total, $requirement),
            BillingCollectionRequirement::MODE_PERCENTAGE_DEPOSIT => $this->percentageDeposit($total, $requirement),
            BillingCollectionRequirement::MODE_PAY_LATER => $this->payLater($requirement),
            default => throw new InvalidArgumentException(
                "Unsupported Billing collection mode [{$requirement->mode}].",
            ),
        };
    }

    private function none(
        int $total,
        BillingCollectionRequirement $requirement,
    ): int {
        $this->assertNoValue($requirement);

        if ($total !== 0) {
            throw new InvalidArgumentException(
                'Billing collection mode [none] is valid only for a zero-price obligation.',
            );
        }

        return 0;
    }

    private function full(
        int $total,
        BillingCollectionRequirement $requirement,
    ): int {
        $this->assertNoValue($requirement);

        return $total;
    }

    private function fixedDeposit(
        int $total,
        BillingCollectionRequirement $requirement,
    ): int {
        if ($requirement->percentage_basis_points !== null) {
            throw new InvalidArgumentException(
                'Fixed-deposit Billing requirements cannot also define a percentage.',
            );
        }

        $fixed = $requirement->fixed_amount_cents;

        if ($fixed === null || $fixed < 1) {
            throw new InvalidArgumentException(
                'Fixed-deposit Billing requirements require a positive fixed amount.',
            );
        }

        if ($total < 1 || $fixed > $total) {
            throw new InvalidArgumentException(
                'Fixed-deposit Billing requirements cannot exceed the total price.',
            );
        }

        return $fixed;
    }

    private function percentageDeposit(
        int $total,
        BillingCollectionRequirement $requirement,
    ): int {
        if ($requirement->fixed_amount_cents !== null) {
            throw new InvalidArgumentException(
                'Percentage-deposit Billing requirements cannot also define a fixed amount.',
            );
        }

        $basisPoints = $requirement->percentage_basis_points;

        if ($basisPoints === null || $basisPoints < 1 || $basisPoints > 10000) {
            throw new InvalidArgumentException(
                'Percentage-deposit Billing requirements must be between 1 and 10000 basis points.',
            );
        }

        if ($total < 1) {
            throw new InvalidArgumentException(
                'Percentage-deposit Billing requirements require a positive total price.',
            );
        }

        $rounded = intdiv(($total * $basisPoints) + 5000, 10000);

        return min($total, max(1, $rounded));
    }

    private function payLater(
        BillingCollectionRequirement $requirement,
    ): int {
        $this->assertNoValue($requirement);

        return 0;
    }

    private function assertNoValue(
        BillingCollectionRequirement $requirement,
    ): void {
        if ($requirement->fixed_amount_cents !== null
            || $requirement->percentage_basis_points !== null
        ) {
            throw new InvalidArgumentException(
                "Billing collection mode [{$requirement->mode}] cannot define deposit values.",
            );
        }
    }
}