<?php

namespace App\Modules\InboundMessaging\Actions\EmailRoutes;

use App\Modules\InboundMessaging\Models\InboundEmailRoute;
use App\Modules\InboundMessaging\Services\Email\InboundEmailRouteResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class SaveInboundEmailRouteAction
{
    public function __construct(
        private readonly InboundEmailRouteResolver $resolver,
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    public function handle(
        array $data,
        ?InboundEmailRoute $route = null,
    ): InboundEmailRoute {
        $key = $this->requiredKey($data['key'] ?? null, 'key', 96);
        $localPart = $this->resolver->normalizeLocalPart($data['local_part'] ?? null);

        if ($localPart === null) {
            throw ValidationException::withMessages([
                'local_part' => 'Enter a valid inbound email local part.',
            ]);
        }

        if ($this->resolver->isReservedLocalPart($localPart)) {
            throw ValidationException::withMessages([
                'local_part' => 'The reply+ namespace is reserved for signed Engage replies.',
            ]);
        }

        $label = $this->requiredText($data['label'] ?? null, 'label', 255);
        $source = $this->requiredSemanticKey($data['source'] ?? null, 'source', 96);
        $contextKey = $this->nullableSemanticKey(
            $data['context_key'] ?? null,
            'context_key',
            191,
        );

        return DB::transaction(function () use (
            $route,
            $key,
            $localPart,
            $label,
            $source,
            $contextKey,
        ): InboundEmailRoute {
            $locked = null;

            if ($route instanceof InboundEmailRoute) {
                $locked = InboundEmailRoute::query()
                    ->lockForUpdate()
                    ->findOrFail($route->getKey());

                if (! hash_equals((string) $locked->key, $key)) {
                    throw ValidationException::withMessages([
                        'key' => 'Inbound route keys cannot be changed after creation.',
                    ]);
                }
            } elseif (InboundEmailRoute::query()
                ->where('key', $key)
                ->lockForUpdate()
                ->exists()
            ) {
                throw ValidationException::withMessages([
                    'key' => 'That inbound route key is already in use.',
                ]);
            }

            $localPartConflict = InboundEmailRoute::query()
                ->whereRaw('LOWER(local_part) = ?', [$localPart])
                ->when(
                    $locked instanceof InboundEmailRoute,
                    fn ($query) => $query->whereKeyNot($locked->getKey()),
                )
                ->lockForUpdate()
                ->exists();

            if ($localPartConflict) {
                throw ValidationException::withMessages([
                    'local_part' => 'That inbound email address is already in use.',
                ]);
            }

            $route = $locked ?? new InboundEmailRoute();

            $route->forceFill([
                'key' => $key,
                'local_part' => $localPart,
                'label' => $label,
                'source' => $source,
                'context_key' => $contextKey,
                'is_active' => $route->exists
                    ? (bool) $route->is_active
                    : true,
            ])->save();

            return $route->refresh();
        }, 3);
    }

    private function requiredKey(
        mixed $value,
        string $field,
        int $maxLength,
    ): string {
        $value = is_string($value)
            ? mb_strtolower(trim($value))
            : '';

        if ($value === ''
            || mb_strlen($value) > $maxLength
            || preg_match('/^[a-z0-9]+(?:_[a-z0-9]+)*$/', $value) !== 1
        ) {
            throw ValidationException::withMessages([
                $field => 'Use lowercase letters, numbers, and underscores only.',
            ]);
        }

        return $value;
    }

    private function requiredSemanticKey(
        mixed $value,
        string $field,
        int $maxLength,
    ): string {
        $value = $this->nullableSemanticKey($value, $field, $maxLength);

        if ($value === null) {
            throw ValidationException::withMessages([
                $field => 'This field is required.',
            ]);
        }

        return $value;
    }

    private function nullableSemanticKey(
        mixed $value,
        string $field,
        int $maxLength,
    ): ?string {
        if ($value === null || $value === '') {
            return null;
        }

        $value = is_string($value)
            ? mb_strtolower(trim($value))
            : '';

        if ($value === ''
            || mb_strlen($value) > $maxLength
            || preg_match('/^[a-z0-9]+(?:[._-][a-z0-9]+)*$/', $value) !== 1
        ) {
            throw ValidationException::withMessages([
                $field => 'Use lowercase letters and numbers separated by dots, dashes, or underscores.',
            ]);
        }

        return $value;
    }

    private function requiredText(
        mixed $value,
        string $field,
        int $maxLength,
    ): string {
        $value = is_string($value) ? trim($value) : '';

        if ($value === '' || mb_strlen($value) > $maxLength) {
            throw ValidationException::withMessages([
                $field => 'This field is required.',
            ]);
        }

        return $value;
    }
}