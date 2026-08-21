<?php

namespace App\Modules\Core\Contracts\Contacts;

use Illuminate\Database\Eloquent\Builder;

interface ContactFilterCriterion
{
    public function key(): string;

    public function label(): string;

    public function sortOrder(): int;

    public function help(): ?string;

    /**
     * @return array<int, array{value: string, label: string}>
     */
    public function options(): array;

    /**
     * @return array<int, string>
     */
    public function normalize(mixed $values): array;

    /**
     * @param Builder<\App\Modules\Core\Models\Contact> $query
     * @param array<int, string> $values
     */
    public function apply(Builder $query, array $values): void;
}