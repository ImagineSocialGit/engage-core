<?php

namespace App\Modules\Core\Data\Contacts;

final readonly class ContactImportTreatmentResolution
{
    /**
     * @param array<string, string> $fieldOverrides
     * @param array<string, array{state: string, source_column: ?string, source_value: ?string, values: array<int, string>}> $targets
     */
    public function __construct(
        public array $fieldOverrides,
        public array $targets,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toMeta(): array
    {
        return $this->targets;
    }
}