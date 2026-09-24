<?php

namespace App\Modules\Commerce\Data;

final readonly class CommerceOfferPublicationState
{
    /**
     * @param array<int, string> $reasonCodes
     * @param array<int, int> $readyVariantIds
     */
    public function __construct(
        public array $reasonCodes,
        public array $readyVariantIds,
    ) {}

    public function eligible(): bool
    {
        return $this->reasonCodes === [] && $this->readyVariantIds !== [];
    }
}