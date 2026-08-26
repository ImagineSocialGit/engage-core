<?php

namespace App\Modules\InboundMessaging\Actions\EmailRoutes;

use App\Modules\InboundMessaging\Models\InboundEmailRoute;
use App\Modules\InboundMessaging\Services\Email\InboundEmailRouteResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
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
        $localPart = $this->resolver->normalizeLocalPart(
            $data['local_part'] ?? null,
        );

        if ($localPart === null) {
            throw ValidationException::withMessages([
                'local_part' => 'Enter a valid inbound email address.',
            ]);
        }

        if ($this->resolver->isReservedLocalPart($localPart)) {
            throw ValidationException::withMessages([
                'local_part' => 'That address prefix is reserved for direct replies to Engage messages.',
            ]);
        }

        $label = $this->requiredText(
            $data['label'] ?? null,
            'label',
            255,
        );

        return DB::transaction(function () use (
            $route,
            $localPart,
            $label,
        ): InboundEmailRoute {
            $locked = $route instanceof InboundEmailRoute
                ? InboundEmailRoute::query()
                    ->lockForUpdate()
                    ->findOrFail($route->getKey())
                : null;

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
                'key' => $route->exists
                    ? $route->key
                    : $this->availableKey($label, $localPart),
                'local_part' => $localPart,
                'label' => $label,
                'source' => $route->exists
                    ? $route->source
                    : 'crm',
                'context_key' => $route->exists
                    ? $route->context_key
                    : null,
                'is_active' => $route->exists
                    ? (bool) $route->is_active
                    : true,
            ])->save();

            return $route->refresh();
        }, 3);
    }

    private function availableKey(
        string $label,
        string $localPart,
    ): string {
        $base = $this->keySeed($label);

        if ($base === '') {
            $base = $this->keySeed($localPart);
        }

        if ($base === '') {
            $base = 'inbound_address';
        }

        $candidate = mb_substr($base, 0, 96);
        $suffix = 2;

        while (InboundEmailRoute::query()
            ->where('key', $candidate)
            ->exists()
        ) {
            $tail = '_'.$suffix++;
            $candidate = mb_substr(
                $base,
                0,
                max(1, 96 - mb_strlen($tail)),
            ).$tail;
        }

        return $candidate;
    }

    private function keySeed(string $value): string
    {
        $value = mb_strtolower(Str::ascii($value));
        $value = preg_replace('/[^a-z0-9]+/', '_', $value) ?? '';
        $value = trim($value, '_');

        return preg_replace('/_+/', '_', $value) ?? '';
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