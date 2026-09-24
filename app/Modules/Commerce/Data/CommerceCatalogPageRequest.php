<?php

namespace App\Modules\Commerce\Data;

use InvalidArgumentException;

final readonly class CommerceCatalogPageRequest
{
    public function __construct(
        public ?string $cursor = null,
        public int $limit = 100,
        public ?string $scope = null,
    ) {
        if ($this->cursor !== null && trim($this->cursor) === '') {
            throw new InvalidArgumentException(
                'Commerce catalog page cursor must be null or a non-blank string.',
            );
        }

        if ($this->limit < 1 || $this->limit > 1000) {
            throw new InvalidArgumentException(
                'Commerce catalog page limit must be between 1 and 1000.',
            );
        }

        if ($this->scope !== null && trim($this->scope) === '') {
            throw new InvalidArgumentException(
                'Commerce catalog scope must be null or a non-blank string.',
            );
        }
    }
}