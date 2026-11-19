<?php

namespace App\Modules\Commerce\Contracts;

/**
 * Optional capability for authoritative inventory providers with a physical
 * stock destination. The identity is provider-defined, never inferred from a
 * SKU, barcode, or mutable client configuration during a retry.
 */
interface CommerceInventoryLocationProvider
{
    public function inventoryLocationId(?string $scope = null): string;
}