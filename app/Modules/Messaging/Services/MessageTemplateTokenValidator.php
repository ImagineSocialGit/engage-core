<?php

namespace App\Modules\Messaging\Services;

use App\Modules\Messaging\Support\MessageMediaPayload;
use App\Support\TokenContracts\Data\TokenContextDefinition;
use App\Support\TokenContracts\TokenContractRegistry;
use Illuminate\Support\Arr;

class MessageTemplateTokenValidator
{
    public function __construct(
        private readonly TokenContractRegistry $tokenContracts,
        private readonly MessageTokenFallbackResolver $tokenFallbackResolver,
    ) {}

    /**
     * @param array<string, mixed> $payload
     * @return array<int, string>
     */
    public function tokensFromPayload(array $payload): array
    {
        return array_values(array_unique(array_map(
            fn (array $occurrence): string => $occurrence['token'],
            $this->tokenOccurrences($this->contentPayload($payload), 'payload'),
        )));
    }

    /**
     * Tokens that actually require scalar runtime resolution. Structured slots
     * such as {cta} are presentation placeholders and are intentionally omitted.
     *
     * @param array<string, mixed> $payload
     * @return array<int, string>
     */
    public function resolvableTokensFromPayload(array $payload): array
    {
        return array_values(array_unique(array_map(
            fn (array $occurrence): string => $occurrence['token'],
            array_values(array_filter(
                $this->tokenOccurrences($this->contentPayload($payload), 'payload'),
                fn (array $occurrence): bool => ! $this->isAllowedRenderSlot(
                    token: $occurrence['token'],
                    payload: $payload,
                ),
            )),
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
        $contentPayload = $this->contentPayload($payload);
        $occurrences = array_values(array_filter(
            $this->tokenOccurrences($contentPayload, $path),
            fn (array $occurrence): bool => ! $this->isAllowedRenderSlot(
                token: $occurrence['token'],
                payload: $payload,
            ),
        ));

        if ($occurrences === []) {
            return $this->validateTokenFallbacks(
                payload: $payload,
                contentPayload: $contentPayload,
                contentTokens: [],
                path: $path,
            );
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

        return [
            ...$issues,
            ...$this->validateTokenFallbacks(
                payload: $payload,
                contentPayload: $contentPayload,
                contentTokens: array_values(array_unique(array_column($occurrences, 'token'))),
                path: $path,
            ),
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $contentPayload
     * @param array<int, string> $contentTokens
     * @return array<int, array{level: string, path: string, message: string}>
     */
    private function validateTokenFallbacks(
        array $payload,
        array $contentPayload,
        array $contentTokens,
        string $path,
    ): array {
        if (! array_key_exists('token_fallbacks', $payload)) {
            return [];
        }

        $raw = $payload['token_fallbacks'];

        if ($raw === null || $raw === []) {
            return [];
        }

        if (! is_array($raw) || ! array_is_list($raw)) {
            return [[
                'level' => 'error',
                'path' => $path.'.token_fallbacks',
                'message' => 'Missing-field behavior must be a list of token rules.',
            ]];
        }

        $issues = [];
        $seen = [];

        foreach ($raw as $index => $policy) {
            $policyPath = $path.'.token_fallbacks.'.$index;

            if (! is_array($policy)) {
                $issues[] = [
                    'level' => 'error',
                    'path' => $policyPath,
                    'message' => 'Each missing-field rule must be a keyed rule.',
                ];

                continue;
            }

            $token = is_string($policy['token'] ?? null)
                ? trim($policy['token'])
                : '';
            $behavior = is_string($policy['missing_behavior'] ?? null)
                ? trim($policy['missing_behavior'])
                : '';

            if ($token === '' || preg_match('/^[a-zA-Z_][a-zA-Z0-9_.:-]*$/', $token) !== 1) {
                $issues[] = [
                    'level' => 'error',
                    'path' => $policyPath.'.token',
                    'message' => 'Each missing-field rule must identify a valid message token.',
                ];

                continue;
            }

            if (isset($seen[$token])) {
                $issues[] = [
                    'level' => 'error',
                    'path' => $policyPath.'.token',
                    'message' => "Missing-field behavior for [{{$token}}] may only be configured once per message.",
                ];

                continue;
            }

            $seen[$token] = true;

            if (! in_array($token, $contentTokens, true)) {
                $issues[] = [
                    'level' => 'error',
                    'path' => $policyPath.'.token',
                    'message' => "Missing-field behavior references [{{$token}}], but that token is not used by this message.",
                ];
            }

            if (! in_array($behavior, MessageTokenFallbackResolver::BEHAVIORS, true)) {
                $issues[] = [
                    'level' => 'error',
                    'path' => $policyPath.'.missing_behavior',
                    'message' => "Missing-field behavior for [{{$token}}] is invalid.",
                ];

                continue;
            }

            if ($behavior === MessageTokenFallbackResolver::BEHAVIOR_REQUIRED) {
                continue;
            }

            $fallback = $policy['fallback'] ?? null;

            if (! is_string($fallback)) {
                $issues[] = [
                    'level' => 'error',
                    'path' => $policyPath.'.fallback',
                    'message' => "Fallback text for [{{$token}}] must be text.",
                ];

                continue;
            }

            if ($behavior === MessageTokenFallbackResolver::BEHAVIOR_FALLBACK_VALUE
                && trim($fallback) === ''
            ) {
                $issues[] = [
                    'level' => 'error',
                    'path' => $policyPath.'.fallback',
                    'message' => "Choose a fallback value for [{{$token}}].",
                ];
            }

            if ($this->tokenFallbackResolver->tokenReferences(['fallback' => $fallback]) !== []) {
                $issues[] = [
                    'level' => 'error',
                    'path' => $policyPath.'.fallback',
                    'message' => 'Fallback text must be literal text and cannot contain another dynamic field.',
                ];
            }

            if ($behavior !== MessageTokenFallbackResolver::BEHAVIOR_REPLACE_SEGMENT) {
                continue;
            }

            $segment = $policy['segment'] ?? null;

            if (! is_string($segment) || trim($segment) === '') {
                $issues[] = [
                    'level' => 'error',
                    'path' => $policyPath.'.segment',
                    'message' => "Choose the exact phrase to replace when [{{$token}}] is missing.",
                ];

                continue;
            }

            if (! $this->tokenFallbackResolver->containsTokenReference($segment, $token)) {
                $issues[] = [
                    'level' => 'error',
                    'path' => $policyPath.'.segment',
                    'message' => "The replacement phrase for [{{$token}}] must include that dynamic field.",
                ];
            }

            if (! $this->payloadContainsExactSegment($contentPayload, $segment)) {
                $issues[] = [
                    'level' => 'error',
                    'path' => $policyPath.'.segment',
                    'message' => 'The replacement phrase must exactly match text that appears in the message.',
                ];

                continue;
            }

            $simulated = $contentPayload;
            $simulated['token_fallbacks'] = [[
                'token' => $token,
                'missing_behavior' => MessageTokenFallbackResolver::BEHAVIOR_REPLACE_SEGMENT,
                'segment' => $segment,
                'fallback' => $fallback,
            ]];
            $remaining = $this->tokenFallbackResolver->tokenReferences(
                $this->tokenFallbackResolver->apply($simulated),
            );

            if (in_array($token, $remaining, true)) {
                $issues[] = [
                    'level' => 'error',
                    'path' => $policyPath.'.segment',
                    'message' => "The replacement phrase for [{{$token}}] must cover every use of that field in this message.",
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

            preg_match_all('/\{([a-zA-Z_][a-zA-Z0-9_.:-]*)\}/', $value, $bracedMatches);
            preg_match_all(
                '/(?<![a-zA-Z0-9_]):([a-zA-Z_][a-zA-Z0-9_-]*(?:\.[a-zA-Z_][a-zA-Z0-9_-]*)*)/',
                $value,
                $colonMatches,
            );

            foreach (array_values(array_unique([
                ...($bracedMatches[1] ?? []),
                ...($colonMatches[1] ?? []),
            ])) as $token) {
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
     * @return array<string, mixed>
     */
    private function contentPayload(array $payload): array
    {
        unset($payload['token_fallbacks'], $payload['tokens']);

        return $payload;
    }

    /** @param array<string, mixed> $payload */
    private function payloadContainsExactSegment(array $payload, string $segment): bool
    {
        foreach (Arr::dot($payload) as $value) {
            if (is_string($value) && str_contains($value, $segment)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function isAllowedRenderSlot(string $token, array $payload): bool
    {
        if ($token === 'media') {
            return MessageMediaPayload::validationErrors(
                $payload['media'] ?? null,
            ) === [];
        }

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