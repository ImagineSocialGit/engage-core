<?php

namespace App\Modules\Core\Events;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Events\Dispatchable;

final class ContactFilterFactsChanged
{
    use Dispatchable;

    public readonly CarbonImmutable $occurredAt;

    /**
     * @param array<int, string> $criterionKeys
     * @param array<string, mixed> $changes
     * @param array<string, mixed> $meta
     */
    public function __construct(
        public readonly int $contactId,
        public readonly array $criterionKeys,
        public readonly string $source,
        public readonly array $changes = [],
        public readonly ?string $subjectType = null,
        public readonly string|int|null $subjectId = null,
        public readonly array $meta = [],
        ?CarbonInterface $occurredAt = null,
    ) {
        $this->occurredAt = $occurredAt instanceof CarbonInterface
            ? CarbonImmutable::instance($occurredAt)
            : CarbonImmutable::now();
    }
}