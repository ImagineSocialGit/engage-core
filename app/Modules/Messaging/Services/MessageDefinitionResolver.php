<?php

namespace App\Modules\Messaging\Services;

use App\Modules\Messaging\Enums\MessageChannel;
use Illuminate\Support\Str;
use InvalidArgumentException;

class MessageDefinitionResolver
{
    public function __construct(
        private readonly MessageTemplatePresetAssignmentResolver $assignmentResolver,
    ) {}

    /**
     * Resolve all enabled non-campaign message definitions for a channel/purpose/scope config route.
     *
     * Campaign step templates are resolved separately through resolveCampaignStep().
     *
     * @return array<int, array<string, mixed>>
     */
    public function resolve(
        MessageChannel|string $channel,
        string $purpose,
        string $scope,
    ): array {
        $channel = $this->normalizeChannel($channel);
        $purpose = $this->normalizeSegment($purpose);
        $scope = $this->normalizeSegment($scope);

        if ($channel === '' || $purpose === '' || $scope === '') {
            return [];
        }

        $assignedDefinitions = $this->assignmentResolver->resolveDefinitions(
            channel: $channel,
            purpose: $purpose,
            scope: $scope,
        );

        $scopeConfigPath = "messaging.{$channel}.{$purpose}.{$scope}";
        $definitions = config($scopeConfigPath);

        if (! is_array($definitions)) {
            return $assignedDefinitions;
        }

        $resolved = [];

        foreach ($definitions as $messageType => $definition) {
            if ($messageType === 'campaigns') {
                continue;
            }

            if (! is_string($messageType) || trim($messageType) === '' || ! is_array($definition)) {
                continue;
            }

            $definitionList = array_is_list($definition) ? $definition : [$definition];

            foreach ($definitionList as $index => $nestedDefinition) {
                if (! is_array($nestedDefinition)) {
                    continue;
                }

                if (! ($nestedDefinition['enabled'] ?? true)) {
                    continue;
                }

                $resolved[] = $this->validateDefinition($this->hydrateDefinitionFromPath(
                    definition: $nestedDefinition,
                    channel: $channel,
                    purpose: $purpose,
                    scope: $scope,
                    messageType: trim($messageType),
                    configPath: "{$scopeConfigPath}.{$messageType}".(array_is_list($definition) ? ".{$index}" : ''),
                ));
            }
        }

        if ($assignedDefinitions !== []) {
            $assignedMessageTypes = array_values(array_unique(array_filter(array_map(
                fn (array $definition): ?string => is_string($definition['message_type'] ?? null)
                    ? $this->normalizeSegment($definition['message_type'])
                    : null,
                $assignedDefinitions,
            ))));

            $resolved = collect($resolved)
                ->reject(fn (array $definition): bool => in_array(
                    $this->normalizeSegment((string) ($definition['message_type'] ?? '')),
                    $assignedMessageTypes,
                    true,
                ))
                ->merge($assignedDefinitions)
                ->values()
                ->all();
        }

        return $resolved;
    }

    /**
     * Resolve a Campaign-owned step template from the Messaging template library.
     *
     * Config path:
     * messaging.{channel}.{purpose}.{scope}.campaigns.{campaign_key}.steps.{step_number}
     *
     * @return array<string, mixed>|null
     */
    public function resolveCampaignStep(
        MessageChannel|string $channel,
        string $purpose,
        string $scope,
        string $campaignKey,
        int $stepNumber,
        string $dispatchKey,
    ): ?array {
        $channel = $this->normalizeChannel($channel);
        $purpose = $this->normalizeSegment($purpose);
        $scope = $this->normalizeSegment($scope);
        $campaignKey = $this->normalizeSegment($campaignKey);
        $dispatchKey = $this->normalizeSegment($dispatchKey);

        if ($channel === '' || $purpose === '' || $scope === '' || $campaignKey === '' || $stepNumber < 1 || $dispatchKey === '') {
            return null;
        }

        $assignedDefinition = $this->assignmentResolver->resolveCampaignStep(
            channel: $channel,
            purpose: $purpose,
            scope: $scope,
            campaignKey: $campaignKey,
            stepNumber: $stepNumber,
        );

        if (is_array($assignedDefinition)) {
            return $assignedDefinition;
        }

        $configPath = "messaging.{$channel}.{$purpose}.{$scope}.campaigns.{$campaignKey}.steps.{$stepNumber}";
        $definition = config($configPath);

        if (! is_array($definition)) {
            return null;
        }

        if (! ($definition['enabled'] ?? true)) {
            return null;
        }

        if (! array_key_exists('dispatch_key', $definition) && ! array_key_exists('dispatch_keys', $definition)) {
            $definition['dispatch_key'] = $dispatchKey;
        }

        $hydrated = $this->hydrateDefinitionFromPath(
            definition: $definition,
            channel: $channel,
            purpose: $purpose,
            scope: $scope,
            messageType: "{$campaignKey}_step_{$stepNumber}",
            configPath: $configPath,
        );

        $hydrated['meta'] = array_replace_recursive($hydrated['meta'] ?? [], [
            'campaign_template' => [
                'campaign_key' => $campaignKey,
                'step_number' => $stepNumber,
            ],
        ]);

        return $this->validateCampaignStepDefinition($hydrated);
    }

    /**
     * @param  array<string, mixed>  $definition
     * @return array<string, mixed>
     */
    private function hydrateDefinitionFromPath(
        array $definition,
        string $channel,
        string $purpose,
        string $scope,
        string $messageType,
        string $configPath,
    ): array {
        $dispatchKeys = $this->normalizeDispatchKeys($definition);

        unset(
            $definition['channel'],
            $definition['purpose'],
            $definition['scope'],
            $definition['message_type'],
            $definition['config_path'],
            $definition['dispatch_key'],
            $definition['dispatch_keys'],
        );

        return array_replace_recursive($definition, [
            'channel' => $channel,
            'purpose' => $purpose,
            'scope' => $scope,
            'message_type' => Str::singular($this->normalizeSegment($messageType)),
            'config_path' => $configPath,
            'dispatch_keys' => $dispatchKeys,

            'timing' => $definition['timing'] ?? 'immediate',

            'schedule' => [
                'type' => data_get($definition, 'schedule.type'),
                'minutes' => data_get($definition, 'schedule.minutes'),
            ],
        ]);
    }

    /**
     * @param  array<string, mixed>  $definition
     * @return array<string, mixed>
     */
    private function validateDefinition(array $definition): array
    {
        foreach (['payload_class', 'queue', 'payload', 'timing', 'dispatch_keys'] as $requiredKey) {
            if (! array_key_exists($requiredKey, $definition)) {
                throw new InvalidArgumentException("Message definition [{$definition['config_path']}] is missing [{$requiredKey}].");
            }
        }

        foreach (['payload_class', 'queue', 'timing'] as $requiredStringKey) {
            if (! is_string($definition[$requiredStringKey]) || trim($definition[$requiredStringKey]) === '') {
                throw new InvalidArgumentException("Message definition [{$definition['config_path']}] has invalid [{$requiredStringKey}].");
            }
        }

        if (! in_array($definition['timing'], ['immediate', 'scheduled'], true)) {
            throw new InvalidArgumentException("Message definition [{$definition['config_path']}] has invalid [timing].");
        }

        if (! is_array($definition['payload'])) {
            throw new InvalidArgumentException("Message definition [{$definition['config_path']}] has invalid [payload].");
        }

        if (array_key_exists('conditions', $definition) && ! is_array($definition['conditions'])) {
            throw new InvalidArgumentException("Message definition [{$definition['config_path']}] has invalid [conditions].");
        }

        if (! is_array($definition['dispatch_keys']) || $definition['dispatch_keys'] === []) {
            throw new InvalidArgumentException("Message definition [{$definition['config_path']}] has invalid [dispatch_keys].");
        }

        foreach ($definition['dispatch_keys'] as $dispatchKey) {
            if (! is_string($dispatchKey) || trim($dispatchKey) === '') {
                throw new InvalidArgumentException("Message definition [{$definition['config_path']}] has invalid [dispatch_keys].");
            }
        }

        if ($definition['timing'] === 'scheduled') {
            $this->validateSchedule($definition);
        }

        return $definition;
    }

    /**
     * Campaign step templates are reusable message payload templates.
     *
     * Campaigns own the actual step timing/schedule and overlay it before
     * dispatching through Messaging.
     *
     * @param  array<string, mixed>  $definition
     * @return array<string, mixed>
     */
    private function validateCampaignStepDefinition(array $definition): array
    {
        foreach (['payload_class', 'queue', 'payload', 'dispatch_keys'] as $requiredKey) {
            if (! array_key_exists($requiredKey, $definition)) {
                throw new InvalidArgumentException("Campaign message definition [{$definition['config_path']}] is missing [{$requiredKey}].");
            }
        }

        foreach (['payload_class', 'queue'] as $requiredStringKey) {
            if (! is_string($definition[$requiredStringKey]) || trim($definition[$requiredStringKey]) === '') {
                throw new InvalidArgumentException("Campaign message definition [{$definition['config_path']}] has invalid [{$requiredStringKey}].");
            }
        }

        if (! is_array($definition['payload'])) {
            throw new InvalidArgumentException("Campaign message definition [{$definition['config_path']}] has invalid [payload].");
        }

        if (array_key_exists('conditions', $definition) && ! is_array($definition['conditions'])) {
            throw new InvalidArgumentException("Campaign message definition [{$definition['config_path']}] has invalid [conditions].");
        }

        if (! is_array($definition['dispatch_keys']) || $definition['dispatch_keys'] === []) {
            throw new InvalidArgumentException("Campaign message definition [{$definition['config_path']}] has invalid [dispatch_keys].");
        }

        foreach ($definition['dispatch_keys'] as $dispatchKey) {
            if (! is_string($dispatchKey) || trim($dispatchKey) === '') {
                throw new InvalidArgumentException("Campaign message definition [{$definition['config_path']}] has invalid [dispatch_keys].");
            }
        }

        return $definition;
    }

    /**
     * @param  array<string, mixed>  $definition
     */
    private function validateSchedule(array $definition): void
    {
        if (! is_array($definition['schedule'] ?? null)) {
            throw new InvalidArgumentException("Scheduled message definition [{$definition['config_path']}] is missing [schedule].");
        }

        $type = $definition['schedule']['type'] ?? null;
        $minutes = $definition['schedule']['minutes'] ?? null;

        if (! in_array($type, ['delay', 'anchored'], true)) {
            throw new InvalidArgumentException("Scheduled message definition [{$definition['config_path']}] has invalid [schedule.type].");
        }

        if (! is_int($minutes)) {
            throw new InvalidArgumentException("Scheduled message definition [{$definition['config_path']}] has invalid [schedule.minutes].");
        }
    }

    /**
     * @param  array<string, mixed>  $definition
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

    private function normalizeChannel(MessageChannel|string $channel): string
    {
        return $channel instanceof MessageChannel
            ? $channel->value
            : strtolower(trim($channel));
    }

    private function normalizeSegment(string $value): string
    {
        return str_replace('-', '_', strtolower(trim($value)));
    }
}

