<?php

namespace App\Support\ProcessHighway\Data;

final readonly class ProcessHighwayAuthority
{
    /**
     * @param array<int, ProcessHighwayEditTarget> $editTargets
     */
    public function __construct(
        public string $ownerKey,
        public array $editTargets,
    ) {}

    public function merge(self $other): self
    {
        $targets = [];

        foreach ([...$this->editTargets, ...$other->editTargets] as $target) {
            if (! $target instanceof ProcessHighwayEditTarget) {
                continue;
            }

            $targets[$target->identity()] = $target;
        }

        return new self(
            ownerKey: $this->ownerKey,
            editTargets: array_values($targets),
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $ownerLabel = (string) config(
            "modules.modules.{$this->ownerKey}.name",
            str($this->ownerKey)->headline()->toString(),
        );

        return [
            'owner_key' => $this->ownerKey,
            'owner_label' => $ownerLabel,
            'tone' => (string) config(
                "modules.modules.{$this->ownerKey}.ui.tone",
                'slate',
            ),
            'editable' => $this->editTargets !== [],
            'edit_targets' => array_map(
                fn (ProcessHighwayEditTarget $target): array => $target->toArray(),
                $this->editTargets,
            ),
        ];
    }
}