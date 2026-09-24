<?php

namespace App\Modules\Commerce\Data;

final readonly class CommerceCatalogSyncResult
{
    public function __construct(
        public string $providerKey,
        public int $pagesProcessed,
        public int $productsCreated,
        public int $productsUpdated,
        public int $variantsCreated,
        public int $variantsUpdated,
    ) {}

    public function productsProcessed(): int
    {
        return $this->productsCreated + $this->productsUpdated;
    }

    public function variantsProcessed(): int
    {
        return $this->variantsCreated + $this->variantsUpdated;
    }
}