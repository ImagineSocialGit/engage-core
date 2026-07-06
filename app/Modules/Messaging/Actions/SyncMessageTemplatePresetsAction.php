<?php

namespace App\Modules\Messaging\Actions;

use App\Modules\Messaging\Models\MessageTemplatePreset;
use App\Modules\Messaging\Models\MessageTemplatePresetAssignment;
use Illuminate\Support\Str;
use InvalidArgumentException;

class SyncMessageTemplatePresetsAction
{
    /**
     * @return array{created: int, updated: int, customized_skipped: int, assignments_created: int, assignments_updated: int, assignments_preserved: int}
     */
    public function handle(bool $force = false): array
    {
        $result = [
            'created' => 0,
            'updated' => 0,
            'customized_skipped' => 0,
            'assignments_created' => 0,
            'assignments_updated' => 0,
            'assignments_preserved' => 0,
        ];

        foreach ($this->definitionsFromConfig() as $definition) {
            $preset = MessageTemplatePreset::query()
                ->where('key', $definition['key'])
                ->first();

            if (! $preset instanceof MessageTemplatePreset) {
                $preset = MessageTemplatePreset::query()->create($definition['preset']);
                $result['created']++;
            } elseif ($preset->is_customized && ! $force) {
                $result['customized_skipped']++;
            } else {
                $preset->forceFill($definition['preset'] + [
                    'is_customized' => false,
                    'customized_at' => null,
                ])->save();
                $result['updated']++;
            }

            $assignmentAttributes = array_replace($definition['assignment'], [
                'message_template_preset_id' => $preset->getKey(),
            ]);

            $assignment = $this->matchingGlobalAssignment($definition['assignment']);

            if (! $assignment instanceof MessageTemplatePresetAssignment) {
                MessageTemplatePresetAssignment::query()->create($assignmentAttributes);
                $result['assignments_created']++;
            } elseif ($force) {
                $assignment->forceFill(array_replace($assignmentAttributes, [
                    'is_active' => true,
                ]))->save();

                $result['assignments_updated']++;
            } else {
                $result['assignments_preserved']++;
            }
        }

        return $result;
    }


    /**
     * @param array<string, mixed> $assignment
     */
    private function matchingGlobalAssignment(array $assignment): ?MessageTemplatePresetAssignment
    {
        $query = MessageTemplatePresetAssignment::query()
            ->where('channel', $assignment['channel'])
            ->where('purpose', $assignment['purpose'])
            ->where('scope', $assignment['scope'])
            ->whereNull('context_type')
            ->whereNull('context_id');

        foreach (['surface', 'message_type', 'campaign_key', 'campaign_step'] as $column) {
            if (($assignment[$column] ?? null) === null) {
                $query->whereNull($column);
            } else {
                $query->where($column, $assignment[$column]);
            }
        }

        return $query->orderByDesc('is_active')->orderByDesc('id')->first();
    }

    /**
     * @return iterable<int, array{key: string, preset: array<string, mixed>, assignment: array<string, mixed>}>
     */
    private function definitionsFromConfig(): iterable
    {
        foreach (['email', 'sms'] as $channel) {
            foreach (['transactional', 'marketing', 'internal'] as $purpose) {
                $purposeConfig = config("messaging.{$channel}.{$purpose}");

                if (! is_array($purposeConfig)) {
                    continue;
                }

                foreach ($purposeConfig as $scope => $scopeConfig) {
                    if (! is_string($scope) || trim($scope) === '' || ! is_array($scopeConfig)) {
                        continue;
                    }

                    yield from $this->definitionsFromScope(
                        channel: $channel,
                        purpose: $purpose,
                        scope: $scope,
                        scopeConfig: $scopeConfig,
                    );
                }
            }
        }
    }

    /**
     * @param array<string, mixed> $scopeConfig
     * @return iterable<int, array{key: string, preset: array<string, mixed>, assignment: array<string, mixed>}>
     */
    private function definitionsFromScope(
        string $channel,
        string $purpose,
        string $scope,
        array $scopeConfig,
    ): iterable {
        $channel = $this->normalizeSegment($channel);
        $purpose = $this->normalizeSegment($purpose);
        $scope = $this->normalizeSegment($scope);
        $scopeConfigPath = "messaging.{$channel}.{$purpose}.{$scope}";

        foreach ($scopeConfig as $messageType => $definition) {
            if ($messageType === 'campaigns') {
                yield from $this->campaignDefinitionsFromConfig(
                    channel: $channel,
                    purpose: $purpose,
                    scope: $scope,
                    campaigns: $definition,
                    baseConfigPath: "{$scopeConfigPath}.campaigns",
                );

                continue;
            }

            if (! is_string($messageType) || trim($messageType) === '' || ! is_array($definition)) {
                continue;
            }

            $definitionList = array_is_list($definition) ? $definition : [$definition];

            foreach ($definitionList as $index => $nestedDefinition) {
                if (! is_array($nestedDefinition) || ! ($nestedDefinition['enabled'] ?? true)) {
                    continue;
                }

                $configPath = "{$scopeConfigPath}.{$messageType}".(array_is_list($definition) ? ".{$index}" : '');

                yield $this->definitionPayload(
                    definition: $nestedDefinition,
                    channel: $channel,
                    purpose: $purpose,
                    scope: $scope,
                    messageType: $messageType,
                    configPath: $configPath,
                    campaignKey: null,
                    campaignStep: null,
                    surface: null,
                    campaignTemplate: false,
                );
            }
        }
    }

    /**
     * @return iterable<int, array{key: string, preset: array<string, mixed>, assignment: array<string, mixed>}>
     */
    private function campaignDefinitionsFromConfig(
        string $channel,
        string $purpose,
        string $scope,
        mixed $campaigns,
        string $baseConfigPath,
    ): iterable {
        if (! is_array($campaigns)) {
            return;
        }

        foreach ($campaigns as $campaignKey => $campaign) {
            if (! is_string($campaignKey) || trim($campaignKey) === '' || ! is_array($campaign)) {
                continue;
            }

            $steps = $campaign['steps'] ?? null;

            if (! is_array($steps)) {
                continue;
            }

            foreach ($steps as $stepNumber => $stepDefinition) {
                if (! is_int($stepNumber) || $stepNumber < 1 || ! is_array($stepDefinition)) {
                    continue;
                }

                if (! ($stepDefinition['enabled'] ?? true)) {
                    continue;
                }

                $campaignKey = $this->normalizeSegment($campaignKey);
                $messageType = "{$campaignKey}_step_{$stepNumber}";
                $configPath = "{$baseConfigPath}.{$campaignKey}.steps.{$stepNumber}";

                yield $this->definitionPayload(
                    definition: $stepDefinition,
                    channel: $channel,
                    purpose: $purpose,
                    scope: $scope,
                    messageType: $messageType,
                    configPath: $configPath,
                    campaignKey: $campaignKey,
                    campaignStep: $stepNumber,
                    surface: 'campaigns',
                    campaignTemplate: true,
                );
            }
        }
    }

    /**
     * @param array<string, mixed> $definition
     * @return array{key: string, preset: array<string, mixed>, assignment: array<string, mixed>}
     */
    private function definitionPayload(
        array $definition,
        string $channel,
        string $purpose,
        string $scope,
        string $messageType,
        string $configPath,
        ?string $campaignKey,
        ?int $campaignStep,
        ?string $surface,
        bool $campaignTemplate,
    ): array {
        $messageType = Str::singular($this->normalizeSegment($messageType));
        $dispatchKeys = $this->normalizeDispatchKeys($definition);
        $payload = $definition['payload'] ?? null;

        if ($dispatchKeys === []) {
            throw new InvalidArgumentException("Message template preset source [{$configPath}] has invalid [dispatch_key] or [dispatch_keys].");
        }

        foreach (['payload_class', 'queue'] as $requiredStringKey) {
            if (! is_string($definition[$requiredStringKey] ?? null) || trim($definition[$requiredStringKey]) === '') {
                throw new InvalidArgumentException("Message template preset source [{$configPath}] has invalid [{$requiredStringKey}].");
            }
        }

        if (! is_array($payload)) {
            throw new InvalidArgumentException("Message template preset source [{$configPath}] has invalid [payload].");
        }

        $timing = $campaignTemplate
            ? (is_string($definition['timing'] ?? null) ? $this->normalizeSegment($definition['timing']) : 'immediate')
            : $this->normalizeSegment((string) ($definition['timing'] ?? ''));

        if (! in_array($timing, ['immediate', 'scheduled'], true)) {
            throw new InvalidArgumentException("Message template preset source [{$configPath}] has invalid [timing].");
        }

        $schedule = is_array($definition['schedule'] ?? null) ? $definition['schedule'] : null;

        if ($timing === 'scheduled') {
            $this->validateSchedule($schedule, $configPath);
        }

        $conditions = $definition['conditions'] ?? [];

        if (! is_array($conditions)) {
            throw new InvalidArgumentException("Message template preset source [{$configPath}] has invalid [conditions].");
        }

        $key = $this->presetKey($configPath);
        $now = now();
        $meta = array_replace_recursive(
            is_array($definition['meta'] ?? null) ? $definition['meta'] : [],
            [
                'seed' => [
                    'config_path' => $configPath,
                    'campaign_key' => $campaignKey,
                    'campaign_step' => $campaignStep,
                ],
            ],
        );

        if (array_key_exists('skip_when_join_clicked', $definition)) {
            $meta['skip_when_join_clicked'] = (bool) $definition['skip_when_join_clicked'];
        }

        if (is_string($definition['notification_type'] ?? null)) {
            $meta['notification_type'] = trim($definition['notification_type']);
        }

        return [
            'key' => $key,
            'preset' => [
                'key' => $key,
                'name' => $this->nameFromKey($key),
                'description' => is_string($definition['description'] ?? null) ? trim($definition['description']) : null,
                'channel' => $channel,
                'purpose' => $purpose,
                'scope' => $scope,
                'message_type' => $messageType,
                'payload_class' => trim($definition['payload_class']),
                'queue' => trim($definition['queue']),
                'dispatch_keys' => $dispatchKeys,
                'timing' => $timing,
                'schedule' => $schedule,
                'conditions' => $conditions,
                'payload' => $payload,
                'tokens' => $this->tokensFromPayload($payload),
                'status' => MessageTemplatePreset::STATUS_ACTIVE,
                'is_active' => true,
                'source' => 'config',
                'source_config_path' => $configPath,
                'source_version' => is_numeric($definition['source_version'] ?? null) ? (int) $definition['source_version'] : null,
                'last_synced_at' => $now,
                'meta' => $meta,
            ],
            'assignment' => [
                'message_template_preset_id' => null,
                'channel' => $channel,
                'purpose' => $purpose,
                'scope' => $scope,
                'surface' => $surface,
                'message_type' => $messageType,
                'campaign_key' => $campaignKey,
                'campaign_step' => $campaignStep,
                'context_type' => null,
                'context_id' => null,
                'is_active' => true,
                'starts_at' => null,
                'ends_at' => null,
                'meta' => [
                    'source' => 'config_sync',
                    'source_config_path' => $configPath,
                ],
            ],
        ];
    }

    /**
     * @param array<string, mixed>|null $schedule
     */
    private function validateSchedule(?array $schedule, string $configPath): void
    {
        if (! is_array($schedule)) {
            throw new InvalidArgumentException("Message template preset source [{$configPath}] is missing [schedule].");
        }

        if (! in_array($schedule['type'] ?? null, ['delay', 'anchored'], true)) {
            throw new InvalidArgumentException("Message template preset source [{$configPath}] has invalid [schedule.type].");
        }

        if (! is_int($schedule['minutes'] ?? null)) {
            throw new InvalidArgumentException("Message template preset source [{$configPath}] has invalid [schedule.minutes].");
        }
    }

    /**
     * @param array<string, mixed> $definition
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
     * @param array<string, mixed> $payload
     * @return array<int, string>
     */
    private function tokensFromPayload(array $payload): array
    {
        $tokens = [];

        array_walk_recursive($payload, function (mixed $value) use (&$tokens): void {
            if (! is_string($value) || trim($value) === '') {
                return;
            }

            preg_match_all('/\{([a-zA-Z_][a-zA-Z0-9_.:-]*)\}/', $value, $matches);

            $tokens = array_merge($tokens, $matches[1] ?? []);
        });

        return array_values(array_unique($tokens));
    }

    private function presetKey(string $configPath): string
    {
        return str_replace('-', '_', strtolower(preg_replace('/^messaging\./', '', $configPath) ?? $configPath));
    }

    private function nameFromKey(string $key): string
    {
        return Str::headline(str_replace(['.', '_'], ' ', $key));
    }

    private function normalizeSegment(string $value): string
    {
        return str_replace('-', '_', strtolower(trim($value)));
    }
}
