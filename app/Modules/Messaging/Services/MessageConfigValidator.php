<?php

namespace App\Modules\Messaging\Services;

use App\Modules\Messaging\Enums\MessageChannel;
use App\Modules\Messaging\Enums\MessagePurpose;
use App\Modules\Messaging\Support\MessageDefinitionConfigPath;
use Illuminate\Support\Arr;

class MessageConfigValidator
{
    /**
     * @param array<int, string> $allowedTokens
     * @return array<int, array{level: string, path: string, message: string}>
     */
    public function validateRoute(
        MessageChannel|string $channel,
        MessagePurpose|string $purpose,
        string $scope,
        array $allowedTokens = [],
    ): array {
        $channel = $this->normalizeEnumValue($channel);
        $purpose = $this->normalizeEnumValue($purpose);
        $scope = $this->normalizeSegment($scope);

        $issues = [];

        if (! in_array($channel, MessageChannel::values(), true)) {
            $issues[] = $this->issue('error', 'messaging.'.$channel, 'Unsupported message channel.');
        }

        if (! in_array($purpose, MessagePurpose::values(), true)) {
            $issues[] = $this->issue('error', 'messaging.'.$channel.'.'.$purpose, 'Unsupported message purpose.');
        }

        $scopePath = MessageDefinitionConfigPath::scope($channel, $purpose, $scope);
        $scopeConfig = config($scopePath);

        if (! is_array($scopeConfig)) {
            $issues[] = $this->issue('error', $scopePath, 'Message config route is missing or not an array.');

            return $issues;
        }

        foreach ($scopeConfig as $messageType => $definition) {
            if ($messageType === 'campaigns') {
                $issues = array_merge(
                    $issues,
                    $this->validateCampaigns(
                        campaigns: $definition,
                        basePath: "{$scopePath}.campaigns",
                        channel: $channel,
                        allowedTokens: $allowedTokens,
                    ),
                );

                continue;
            }

            if (! is_string($messageType) || trim($messageType) === '') {
                $issues[] = $this->issue('error', $scopePath, 'Message type keys must be non-empty strings.');

                continue;
            }

            if (! is_array($definition)) {
                $issues[] = $this->issue('error', "{$scopePath}.{$messageType}", 'Message definition must be an array.');

                continue;
            }

            $definitionList = array_is_list($definition) ? $definition : [$definition];

            foreach ($definitionList as $index => $nestedDefinition) {
                $definitionPath = "{$scopePath}.{$messageType}".(array_is_list($definition) ? ".{$index}" : '');

                if (! is_array($nestedDefinition)) {
                    $issues[] = $this->issue('error', $definitionPath, 'Message definition must be an array.');

                    continue;
                }

                if (($nestedDefinition['enabled'] ?? true) === false) {
                    continue;
                }

                $issues = array_merge(
                    $issues,
                    $this->validateDefinition(
                        definition: $nestedDefinition,
                        path: $definitionPath,
                        channel: $channel,
                        allowedTokens: $allowedTokens,
                        campaignTemplate: false,
                    ),
                );
            }
        }

        return $issues;
    }

    /**
     * @param array<int, string> $allowedTokens
     * @return array<int, array{level: string, path: string, message: string}>
     */
    private function validateCampaigns(
        mixed $campaigns,
        string $basePath,
        string $channel,
        array $allowedTokens,
    ): array
    {
        if (! is_array($campaigns)) {
            return [$this->issue('error', $basePath, 'Campaign message templates must be an array.')];
        }

        $issues = [];

        foreach ($campaigns as $campaignKey => $campaign) {
            $campaignPath = "{$basePath}.{$campaignKey}";

            if (! is_string($campaignKey) || trim($campaignKey) === '') {
                $issues[] = $this->issue('error', $basePath, 'Campaign keys must be non-empty strings.');

                continue;
            }

            if (! is_array($campaign)) {
                $issues[] = $this->issue('error', $campaignPath, 'Campaign template must be an array.');

                continue;
            }

            $steps = $campaign['steps'] ?? null;

            if (! is_array($steps)) {
                $issues[] = $this->issue('error', "{$campaignPath}.steps", 'Campaign template steps must be an array.');

                continue;
            }

            foreach ($steps as $stepNumber => $stepDefinition) {
                $stepPath = "{$campaignPath}.steps.{$stepNumber}";

                if (! is_int($stepNumber) || $stepNumber < 1) {
                    $issues[] = $this->issue('error', $stepPath, 'Campaign step keys must be positive integers.');
                }

                if (! is_array($stepDefinition)) {
                    $issues[] = $this->issue('error', $stepPath, 'Campaign step definition must be an array.');

                    continue;
                }

                if (($stepDefinition['enabled'] ?? true) === false) {
                    continue;
                }

                $variants = $stepDefinition['variants'] ?? null;

                if (! is_array($variants) || $variants === []) {
                    $issues[] = $this->issue(
                        'error',
                        "{$stepPath}.variants",
                        'Campaign message step must define at least one message variant.',
                    );

                    continue;
                }

                foreach ($variants as $variantIndex => $variantDefinition) {
                    $variantKey = is_string($variantIndex) && trim($variantIndex) !== ''
                        ? trim($variantIndex)
                        : (is_array($variantDefinition) && is_string($variantDefinition['key'] ?? null)
                            ? trim($variantDefinition['key'])
                            : (string) $variantIndex);

                    $variantPath = "{$stepPath}.variants.{$variantKey}";

                    if (! is_array($variantDefinition)) {
                        $issues[] = $this->issue(
                            'error',
                            $variantPath,
                            'Campaign message variant definition must be an array.',
                        );

                        continue;
                    }

                    if (($variantDefinition['enabled'] ?? true) === false) {
                        continue;
                    }

                    $issues = array_merge(
                        $issues,
                        $this->validateDefinition(
                            definition: $variantDefinition,
                            path: $variantPath,
                            channel: $channel,
                            allowedTokens: $allowedTokens,
                            campaignTemplate: true,
                        ),
                    );
                }
            }
        }

        return $issues;
    }

    /**
     * @param array<string, mixed> $definition
     * @param array<int, string> $allowedTokens
     * @return array<int, array{level: string, path: string, message: string}>
     */
    public function validateDefinitionArray(
        array $definition,
        string $path,
        MessageChannel|string|null $channel = null,
        array $allowedTokens = [],
    ): array {
        $channel = $channel === null
            ? null
            : $this->normalizeEnumValue($channel);

        return $this->validateDefinition(
            definition: $definition,
            path: $path,
            channel: $channel,
            allowedTokens: $allowedTokens,
            campaignTemplate: false,
        );
    }

    /**
     * @param array<string, mixed> $definition
     * @param array<int, string> $allowedTokens
     * @return array<int, array{level: string, path: string, message: string}>
     */
    private function validateDefinition(
        array $definition,
        string $path,
        ?string $channel,
        array $allowedTokens,
        bool $campaignTemplate,
    ): array {
        $issues = [];
        $requiredKeys = ['payload_class', 'queue', 'payload'];

        foreach ($requiredKeys as $requiredKey) {
            if (! array_key_exists($requiredKey, $definition)) {
                $issues[] = $this->issue('error', $path, "Message definition is missing [{$requiredKey}].");
            }
        }

        $dispatchKeys = $this->normalizeDispatchKeys($definition);

        if ($dispatchKeys === []) {
            $issues[] = $this->issue('error', $path, 'Message definition has invalid [dispatch_key] or [dispatch_keys].');
        }

        foreach (['payload_class', 'queue'] as $stringKey) {
            if (array_key_exists($stringKey, $definition) && (! is_string($definition[$stringKey]) || trim($definition[$stringKey]) === '')) {
                $issues[] = $this->issue('error', "{$path}.{$stringKey}", "Message definition has invalid [{$stringKey}].");
            }
        }

        $payloadClass = $definition['payload_class'] ?? null;

        if (is_string($payloadClass) && trim($payloadClass) !== '') {
            if (! class_exists($payloadClass)) {
                $issues[] = $this->issue('error', "{$path}.payload_class", 'Payload class does not exist.');
            } elseif (! method_exists($payloadClass, 'fromArray')) {
                $issues[] = $this->issue('error', "{$path}.payload_class", 'Payload class must define fromArray().');
            }
        }

        foreach (['timing', 'schedule', 'conditions'] as $behaviorField) {
            if (array_key_exists($behaviorField, $definition)) {
                $issues[] = $this->issue(
                    'error',
                    "{$path}.{$behaviorField}",
                    "Reusable Messaging templates must not own [{$behaviorField}] behavior.",
                );
            }
        }

        $payload = $definition['payload'] ?? null;

        if (! is_array($payload)) {
            $issues[] = $this->issue('error', "{$path}.payload", 'Message definition has invalid [payload].');

            return $issues;
        }

        $issues = array_merge(
            $issues,
            $this->validatePayloadShape(
                payload: $payload,
                path: "{$path}.payload",
                channel: $channel,
                payloadClass: is_string($payloadClass) ? $payloadClass : null,
            ),
            $this->validatePayloadTokens(
                payload: $payload,
                path: "{$path}.payload",
                allowedTokens: $allowedTokens,
            ),
        );

        return $issues;
    }

    /**
     * @return array<int, array{level: string, path: string, message: string}>
     */
    private function validateSchedule(mixed $schedule, string $path): array
    {
        if (! is_array($schedule)) {
            return [$this->issue('error', $path, 'Scheduled message definition is missing [schedule].')];
        }

        $issues = [];
        $type = $schedule['type'] ?? null;
        $minutes = $schedule['minutes'] ?? null;

        if (! in_array($type, ['delay', 'anchored'], true)) {
            $issues[] = $this->issue('error', "{$path}.type", 'Schedule type must be delay or anchored.');
        }

        if (! is_int($minutes)) {
            $issues[] = $this->issue('error', "{$path}.minutes", 'Schedule minutes must be an integer.');
        }

        return $issues;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<int, array{level: string, path: string, message: string}>
     */
    private function validatePayloadShape(array $payload, string $path, ?string $channel, ?string $payloadClass): array
    {
        $issues = [];

        if (($channel === MessageChannel::Email->value || str_ends_with((string) $payloadClass, '\\EmailPayload'))) {
            if (! $this->filledString($payload['subject'] ?? null)) {
                $issues[] = $this->issue('error', "{$path}.subject", 'Email payload requires a subject.');
            }

            if (! $this->filledString($payload['body'] ?? null)) {
                $issues[] = $this->issue('error', "{$path}.body", 'Email payload requires a body.');
            }
        }

        if (($channel === MessageChannel::Sms->value || str_ends_with((string) $payloadClass, '\\SmsPayload'))
            && ! $this->filledString($payload['message'] ?? null)
        ) {
            $issues[] = $this->issue('error', "{$path}.message", 'SMS payload requires a message.');
        }

        foreach (['cta', 'secondary_link'] as $linkKey) {
            if (! array_key_exists($linkKey, $payload)) {
                continue;
            }

            if (! is_array($payload[$linkKey])) {
                $issues[] = $this->issue('error', "{$path}.{$linkKey}", "Payload [{$linkKey}] must be an array.");

                continue;
            }

            foreach (['label', 'url'] as $requiredLinkKey) {
                if (! $this->filledString($payload[$linkKey][$requiredLinkKey] ?? null)) {
                    $issues[] = $this->issue('error', "{$path}.{$linkKey}.{$requiredLinkKey}", "Payload [{$linkKey}] requires [{$requiredLinkKey}].");
                }
            }
        }

        return $issues;
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<int, string> $allowedTokens
     * @return array<int, array{level: string, path: string, message: string}>
     */
    private function validatePayloadTokens(array $payload, string $path, array $allowedTokens): array
    {
        $issues = [];
        $allowedTokens = array_values(array_unique(array_merge($this->universalTokens(), $allowedTokens)));

        foreach (Arr::dot($payload) as $key => $value) {
            if (! is_string($value) || trim($value) === '') {
                continue;
            }

            foreach ($this->tokensIn($value) as $token) {
                if ($this->isAllowedRenderSlot($token, $payload)) {
                    continue;
                }

                if (! $this->tokenAllowed($token, $allowedTokens)) {
                    $issues[] = $this->issue(
                        'warning',
                        "{$path}.{$key}",
                        "Payload references token [{{$token}}] that is not declared for this config validation context.",
                    );
                }
            }
        }

        return $issues;
    }

    /**
     * @return array<int, string>
     */
    private function normalizeDispatchKeys(array $definition): array
    {
        $dispatchKeys = $definition['dispatch_keys'] ?? null;

        if ($dispatchKeys === null && array_key_exists('dispatch_key', $definition)) {
            $dispatchKeys = [$definition['dispatch_key']];
        }

        if (! is_array($dispatchKeys)) {
            return [];
        }

        return array_values(array_unique(array_filter(array_map(
            fn (mixed $dispatchKey): ?string => is_string($dispatchKey) && trim($dispatchKey) !== ''
                ? $this->normalizeSegment($dispatchKey)
                : null,
            $dispatchKeys,
        ))));
    }

    /**
     * @return array<int, string>
     */
    private function tokensIn(string $value): array
    {
        preg_match_all('/\{([a-zA-Z_][a-zA-Z0-9_.:-]*)\}/', $value, $matches);

        return array_values(array_unique($matches[1] ?? []));
    }

    /**
     * @param array<int, string> $allowedTokens
     */
    private function tokenAllowed(string $token, array $allowedTokens): bool
    {
        if (in_array($token, $allowedTokens, true)) {
            return true;
        }

        foreach ($allowedTokens as $allowedToken) {
            if (str_ends_with($allowedToken, '.*') && str_starts_with($token, rtrim($allowedToken, '*'))) {
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
        $value = $payload[$token] ?? null;

        return is_array($value)
            && $this->filledString($value['label'] ?? null)
            && $this->filledString($value['url'] ?? null);
    }

    /**
     * @return array<int, string>
     */
    private function universalTokens(): array
    {
        return [
            'contact_id',
            'contact_first_name',
            'contact_last_name',
            'contact_full_name',
            'contact_email',
            'contact_phone',
            'first_name',
            'last_name',
            'full_name',
            'name',
            'email',
            'phone',
            'request_ip',
            'contact.*',
        ];
    }

    /**
     * @return array{level: string, path: string, message: string}
     */
    private function issue(string $level, string $path, string $message): array
    {
        return [
            'level' => $level,
            'path' => $path,
            'message' => $message,
        ];
    }

    private function normalizeEnumValue(MessageChannel|MessagePurpose|string $value): string
    {
        return $value instanceof MessageChannel || $value instanceof MessagePurpose
            ? $value->value
            : $this->normalizeSegment($value);
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
