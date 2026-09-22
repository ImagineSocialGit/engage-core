<?php

namespace App\Modules\Commerce\Data;

use App\Modules\Commerce\Enums\CommerceInventoryAuthorityMode;
use Carbon\CarbonInterface;

final readonly class CommerceInventoryEffectData
{
    /**
     * @param array<string, mixed> $meta
     */
    public function __construct(
        public int $commerceProductVariantId,
        public string $quantityDelta,
        public string $reason,
        public string $sourceType,
        public string $sourceKey,
        public string $idempotencyKey,
        public CommerceInventoryAuthorityMode $authorityMode,
        public ?string $sourceReference = null,
        public ?string $inventoryScope = null,
        public ?CarbonInterface $occurredAt = null,
        public array $meta = [],
    ) {}
}