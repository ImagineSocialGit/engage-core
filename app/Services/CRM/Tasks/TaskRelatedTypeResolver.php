<?php

namespace App\Services\CRM\Tasks;

use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

class TaskRelatedTypeResolver
{
    /**
     * @return array<int, class-string<Model>>
     */
    public function allowedTypes(): array
    {
        return collect(config('tasks.related_types', []))
            ->filter(fn (mixed $type): bool => is_string($type) && is_subclass_of($type, Model::class))
            ->values()
            ->all();
    }

    /**
     * @return array<int, string>
     */
    public function allowedTypeKeys(): array
    {
        return collect($this->allowedTypes())
            ->flatMap(fn (string $type): array => [
                $type,
                (new $type())->getMorphClass(),
            ])
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @return class-string<Model>|null
     */
    public function normalize(?string $type): ?string
    {
        if ($type === null || trim($type) === '') {
            return null;
        }

        foreach ($this->allowedTypes() as $allowedType) {
            if ($type === $allowedType || $type === (new $allowedType())->getMorphClass()) {
                return $allowedType;
            }
        }

        throw new InvalidArgumentException('The selected related type is invalid.');
    }

    public function exists(string $type, int|string $id): bool
    {
        $normalized = $this->normalize($type);

        if (! $normalized) {
            return false;
        }

        return $normalized::query()->whereKey($id)->exists();
    }
}