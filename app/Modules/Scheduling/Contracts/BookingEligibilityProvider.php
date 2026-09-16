<?php

namespace App\Modules\Scheduling\Contracts;

use App\Modules\Scheduling\Data\BookingEligibilityIdentity;
use Carbon\CarbonInterface;

interface BookingEligibilityProvider
{
    public function key(): string;

    public function label(): string;

    /**
     * @return array<int, array{
     *     value:string,
     *     label:string,
     *     group:string|null,
     *     criteria:array<string, mixed>
     * }>
     */
    public function options(): array;

    /** @param array<string, mixed> $criteria */
    public function resolve(
        array $criteria,
        string $email,
        ?CarbonInterface $evaluatedAt = null,
    ): ?BookingEligibilityIdentity;
}