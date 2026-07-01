<?php

namespace App\Modules\Broadcasts\Services;

use App\Modules\Broadcasts\Models\Broadcast;
use App\Modules\Core\Models\Contact;
use Illuminate\Database\Eloquent\Collection;

class BroadcastRecipientResolver
{
    /**
     * @return Collection<int, Contact>
     */
    public function resolve(Broadcast $broadcast): Collection
    {
        $recipientFilter = $broadcast->recipient_filter ?? [];
        $type = $this->stringValue($recipientFilter['type'] ?? 'all');

        return match ($type) {
            'contact_ids' => $this->resolveContactIds($recipientFilter),
            'tag' => $this->resolveTags($recipientFilter),
            default => $this->resolveAll(),
        };
    }

    /**
     * @param array<string, mixed> $recipientFilter
     * @return Collection<int, Contact>
     */
    private function resolveContactIds(array $recipientFilter): Collection
    {
        $contactIds = $this->integerValues($recipientFilter['contact_ids'] ?? []);

        if ($contactIds === []) {
            return new Collection();
        }

        return Contact::query()
            ->whereIn('id', $contactIds)
            ->orderBy('id')
            ->get();
    }

    /**
     * @param array<string, mixed> $recipientFilter
     * @return Collection<int, Contact>
     */
    private function resolveTags(array $recipientFilter): Collection
    {
        $tags = $this->stringValues($recipientFilter['tags'] ?? $recipientFilter['tag'] ?? []);

        if ($tags === []) {
            return new Collection();
        }

        return Contact::query()
            ->whereHas('tags', function ($query) use ($tags): void {
                $query->whereIn('tag', $tags);
            })
            ->orderBy('id')
            ->get();
    }

    /**
     * @return Collection<int, Contact>
     */
    private function resolveAll(): Collection
    {
        return Contact::query()
            ->orderBy('id')
            ->get();
    }

    private function stringValue(mixed $value): string
    {
        return is_string($value) && trim($value) !== ''
            ? str_replace('-', '_', strtolower(trim($value)))
            : 'all';
    }

    /**
     * @return array<int, int>
     */
    private function integerValues(mixed $values): array
    {
        if (! is_array($values)) {
            return [];
        }

        return array_values(array_unique(array_filter(array_map(
            fn (mixed $value): ?int => is_numeric($value) ? (int) $value : null,
            $values,
        ), fn (?int $value): bool => $value !== null && $value > 0)));
    }

    /**
     * @return array<int, string>
     */
    private function stringValues(mixed $values): array
    {
        if (is_string($values)) {
            $values = [$values];
        }

        if (! is_array($values)) {
            return [];
        }

        return array_values(array_unique(array_filter(array_map(
            fn (mixed $value): ?string => is_string($value) && trim($value) !== ''
                ? str_replace('-', '_', strtolower(trim($value)))
                : null,
            $values,
        ))));
    }
}