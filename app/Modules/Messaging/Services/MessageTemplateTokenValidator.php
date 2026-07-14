<?php

namespace App\Modules\Messaging\Services;

use App\Support\TokenContracts\Data\TokenContextDefinition;
use App\Support\TokenContracts\TokenContractRegistry;
use Illuminate\Support\Arr;

class MessageTemplateTokenValidator
{
    public function __construct(
        private readonly TokenContractRegistry $tokenContracts,
    ) {}

    /**
     * @param array<string, mixed> $payload
     * @return array<int, string>
     */
    public function tokensFromPayload(array $payload): array
    {
        return array_values(array_unique(array_map(
            fn (array $occurrence): string => $occurrence['token'],
            $this->tokenOccurrences($payload, 'payload'),
        )));
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<int, string> $dispatchKeys
     * @return array<int, array{level: string, path: string, message: string}>
     */
    public function validatePayload(
        array $payload,
        array $dispatchKeys,
        ?string $channel = null,
        ?string $purpose = null,
        ?string $scope = null,
        ?string $surface = null,
        string $path = 'payload',
    ): array {
        $occurrences = array_values(array_filter(
            $this->tokenOccurrences($payload, $path),
            fn (array $occurrence): bool => ! $this->isAllowedRenderSlot(
                token: $occurrence['token'],
                payload: $payload,
            ),
        ));

        if ($occurrences === []) {
            return [];
        }

        $dispatchKeys = $this->normalizeList($dispatchKeys);

        if ($dispatchKeys === []) {
            return [[
                'level' => 'error',
                'path' => $path,
                'message' => 'Payload uses tokens but has no dispatch context available for token validation.',
            ]];
        }

        $contextIssues = [];

        foreach ($dispatchKeys as $contextKey) {
            if (! $this->tokenContracts->hasContext($contextKey)) {
                $contextIssues[] = [
                    'level' => 'error',
                    'path' => $path,
                    'message' => "Payload uses tokens but dispatch context [{$contextKey}] is not registered for token validation.",
                ];

                continue;
            }

            $context = $this->tokenContracts->context($contextKey);

            if (! $this->contextAllowsRoute(
                context: $context,
                channel: $channel,
                purpose: $purpose,
                scope: $scope,
                surface: $surface,
            )) {
                $contextIssues[] = [
                    'level' => 'error',
                    'path' => $path,
                    'message' => sprintf(
                        'Dispatch context [%s] is not compatible with message route [%s].',
                        $contextKey,
                        $this->routeLabel($channel, $purpose, $scope, $surface),
                    ),
                ];
            }
        }

        if ($contextIssues !== []) {
            return $contextIssues;
        }

        $allowedTokens = $this->tokenContracts->authorableTokensForContexts($dispatchKeys);
        $registeredTokens = $this->tokenContracts->allAuthorableTokens();
        $contextLabel = implode(', ', $dispatchKeys);
        $issues = [];
        $seen = [];

        foreach ($occurrences as $occurrence) {
            $token = $occurrence['token'];
            $occurrencePath = $occurrence['path'];
            $identity = $occurrencePath.'|'.$token;

            if (isset($seen[$identity])) {
                continue;
            }

            $seen[$identity] = true;

            if (! in_array($token, $registeredTokens, true)) {
                $issues[] = [
                    'level' => 'error',
                    'path' => $occurrencePath,
                    'message' => "Payload references unknown token [{{$token}}].",
                ];

                continue;
            }

            if (! in_array($token, $allowedTokens, true)) {
                $issues[] = [
                    'level' => 'error',
                    'path' => $occurrencePath,
                    'message' => "Payload references token [{{$token}}] that is not available for dispatch context [{$contextLabel}].",
                ];
            }
        }

        return $issues;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<int, array{token: string, path: string}>
     */
    private function tokenOccurrences(array $payload, string $path): array
    {
        $occurrences = [];

        foreach (Arr::dot($payload) as $key => $value) {
            if (! is_string($value) || trim($value) === '') {
                continue;
            }

            preg_match_all('/\{([a-zA-Z_][a-zA-Z0-9_.:-]*)\}/', $value, $matches);

            foreach (array_values(array_unique($matches[1] ?? [])) as $token) {
                $occurrences[] = [
                    'token' => $token,
                    'path' => $path.'.'.$key,
                ];
            }
        }

        return $occurrences;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function isAllowedRenderSlot(string $token, array $payload): bool
    {
        if ($token !== 'cta') {
            $value = $payload[$token] ?? null;

            return is_array($value)
                && $this->filledString($value['label'] ?? null)
                && $this->filledString($value['url'] ?? null);
        }

        $cta = $payload['cta'] ?? null;

        if (
            is_array($cta)
            && $this->filledString($cta['label'] ?? null)
            && $this->filledString($cta['url'] ?? null)
        ) {
            return true;
        }

        $ctas = $payload['ctas'] ?? null;

        if (! is_array($ctas) || ! array_is_list($ctas) || $ctas === []) {
            return false;
        }

        foreach ($ctas as $item) {
            if (
                ! is_array($item)
                || ! $this->filledString($item['label'] ?? null)
                || ! $this->filledString($item['url'] ?? null)
            ) {
                return false;
            }
        }

        return true;
    }

    private function contextAllowsRoute(
        TokenContextDefinition $context,
        ?string $channel,
        ?string $purpose,
        ?string $scope,
        ?string $surface,
    ): bool {
        return $this->dimensionAllows($context->channels, $channel)
            && $this->dimensionAllows($context->purposes, $purpose)
            && $this->dimensionAllows($context->scopes, $scope)
            && $this->dimensionAllows($context->surfaces, $surface);
    }

    /**
     * @param array<int, string> $allowedValues
     */
    private function dimensionAllows(array $allowedValues, ?string $actualValue): bool
    {
        if ($allowedValues === [] || $actualValue === null || trim($actualValue) === '') {
            return true;
        }

        $actualValue = $this->normalizeSegment($actualValue);

        return in_array(
            $actualValue,
            array_map(fn (string $value): string => $this->normalizeSegment($value), $allowedValues),
            true,
        );
    }

    /**
     * @param array<int, string> $values
     * @return array<int, string>
     */
    private function normalizeList(array $values): array
    {
        return array_values(array_unique(array_filter(array_map(
            fn (mixed $value): ?string => is_string($value) && trim($value) !== ''
                ? $this->normalizeSegment($value)
                : null,
            $values,
        ))));
    }

    private function routeLabel(
        ?string $channel,
        ?string $purpose,
        ?string $scope,
        ?string $surface,
    ): string {
        return implode(':', array_filter([
            $channel !== null ? $this->normalizeSegment($channel) : null,
            $purpose !== null ? $this->normalizeSegment($purpose) : null,
            $scope !== null ? $this->normalizeSegment($scope) : null,
            $surface !== null ? $this->normalizeSegment($surface) : null,
        ], fn (?string $value): bool => $value !== null && $value !== ''));
    }

    private function normalizeSegment(string $value): string
    {
        return str_replace('-', '_', strtolower(trim($value)));
    }

    private function filledString(mixed $value): bool
    {
        return is_string($value) && trim($value) !== '';
    }
}
