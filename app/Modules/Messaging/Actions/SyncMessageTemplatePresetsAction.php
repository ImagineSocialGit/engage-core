<?php

namespace App\Modules\Messaging\Actions;

use App\Modules\Messaging\Models\MessageTemplateCatalogEntry;
use App\Modules\Messaging\Models\MessageTemplatePreset;
use App\Modules\Messaging\Models\MessageTemplatePresetAssignment;
use Illuminate\Support\Str;
use InvalidArgumentException;

class SyncMessageTemplatePresetsAction
{
    /**
     * @return array{created: int, updated: int, customized_skipped: int, assignments_created: int, assignments_updated: int, assignments_preserved: int, catalog_entries_created: int, catalog_entries_updated: int}
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
            'catalog_entries_created' => 0,
            'catalog_entries_updated' => 0,
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

            $catalogEntry = $this->syncCatalogEntry($preset, $definition['catalog_entry']);
            $result[$catalogEntry->wasRecentlyCreated ? 'catalog_entries_created' : 'catalog_entries_updated']++;
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $catalogEntryAttributes
     */
    private function syncCatalogEntry(
        MessageTemplatePreset $preset,
        array $catalogEntryAttributes,
    ): MessageTemplateCatalogEntry {
        $attributes = array_replace($catalogEntryAttributes, [
            'message_template_preset_id' => $preset->getKey(),
        ]);

        $catalogEntry = MessageTemplateCatalogEntry::query()
            ->where('message_template_preset_id', $preset->getKey())
            ->where('item_key', $attributes['item_key'])
            ->first();

        if (! $catalogEntry instanceof MessageTemplateCatalogEntry) {
            return MessageTemplateCatalogEntry::query()->create($attributes);
        }

        $catalogEntry->forceFill($attributes)->save();

        return $catalogEntry;
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
     * @return iterable<int, array{key: string, preset: array<string, mixed>, assignment: array<string, mixed>, catalog_entry: array<string, mixed>}>
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
     * @return iterable<int, array{key: string, preset: array<string, mixed>, assignment: array<string, mixed>, catalog_entry: array<string, mixed>}>
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

            $isList = array_is_list($definition);
            $definitionList = $isList ? $definition : [$definition];
            $needsIndexedMessageType = $isList && count($definitionList) > 1;

            foreach ($definitionList as $index => $nestedDefinition) {
                if (! is_array($nestedDefinition) || ! ($nestedDefinition['enabled'] ?? true)) {
                    continue;
                }

                $configPath = "{$scopeConfigPath}.{$messageType}".($isList ? ".{$index}" : '');
                $runtimeMessageType = $needsIndexedMessageType
                    ? $this->indexedMessageType($messageType, $nestedDefinition, (int) $index)
                    : $messageType;

                yield $this->definitionPayload(
                    definition: $nestedDefinition,
                    channel: $channel,
                    purpose: $purpose,
                    scope: $scope,
                    messageType: $runtimeMessageType,
                    sourceMessageType: $messageType,
                    configPath: $configPath,
                    campaignKey: null,
                    campaignStep: null,
                    surface: $this->surfaceForScope($scope),
                    campaignTemplate: false,
                    listIndex: $isList ? (int) $index : null,
                );
            }
        }
    }

    /**
     * @return iterable<int, array{key: string, preset: array<string, mixed>, assignment: array<string, mixed>, catalog_entry: array<string, mixed>}>
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

            $normalizedCampaignKey = $this->normalizeSegment($campaignKey);
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

                $messageType = "{$normalizedCampaignKey}_step_{$stepNumber}";
                $configPath = "{$baseConfigPath}.{$normalizedCampaignKey}.steps.{$stepNumber}";

                yield $this->definitionPayload(
                    definition: $stepDefinition,
                    channel: $channel,
                    purpose: $purpose,
                    scope: $scope,
                    messageType: $messageType,
                    sourceMessageType: 'campaign_step',
                    configPath: $configPath,
                    campaignKey: $normalizedCampaignKey,
                    campaignStep: $stepNumber,
                    surface: 'campaigns',
                    campaignTemplate: true,
                    listIndex: null,
                );
            }
        }
    }

    /**
     * @param array<string, mixed> $definition
     * @return array{key: string, preset: array<string, mixed>, assignment: array<string, mixed>, catalog_entry: array<string, mixed>}
     */
    private function definitionPayload(
        array $definition,
        string $channel,
        string $purpose,
        string $scope,
        string $messageType,
        string $sourceMessageType,
        string $configPath,
        ?string $campaignKey,
        ?int $campaignStep,
        ?string $surface,
        bool $campaignTemplate,
        ?int $listIndex,
    ): array {
        $messageType = $this->normalizeSegment($messageType);
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
        $catalog = $this->catalogEntryPayload(
            definition: $definition,
            channel: $channel,
            purpose: $purpose,
            scope: $scope,
            messageType: $messageType,
            sourceMessageType: $sourceMessageType,
            configPath: $configPath,
            campaignKey: $campaignKey,
            campaignStep: $campaignStep,
            surface: $surface,
            campaignTemplate: $campaignTemplate,
            listIndex: $listIndex,
        );

        $meta = array_replace_recursive(
            is_array($definition['meta'] ?? null) ? $definition['meta'] : [],
            [
                'seed' => [
                    'config_path' => $configPath,
                    'campaign_key' => $campaignKey,
                    'campaign_step' => $campaignStep,
                ],
                'catalog' => [
                    'group_key' => $catalog['group_key'],
                    'group_label' => $catalog['group_label'],
                    'item_key' => $catalog['item_key'],
                    'item_label' => $catalog['item_label'],
                    'usage_type' => $catalog['usage_type'],
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
                'name' => $catalog['display_name'],
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
                    'catalog' => [
                        'group_key' => $catalog['group_key'],
                        'group_label' => $catalog['group_label'],
                        'item_key' => $catalog['item_key'],
                        'item_label' => $catalog['item_label'],
                    ],
                ],
            ],
            'catalog_entry' => $catalog['attributes'],
        ];
    }

    /**
     * @param array<string, mixed> $definition
     * @return array{display_name: string, group_key: string, group_label: string, item_key: string, item_label: string, usage_type: string, attributes: array<string, mixed>}
     */
    private function catalogEntryPayload(
        array $definition,
        string $channel,
        string $purpose,
        string $scope,
        string $messageType,
        string $sourceMessageType,
        string $configPath,
        ?string $campaignKey,
        ?int $campaignStep,
        ?string $surface,
        bool $campaignTemplate,
        ?int $listIndex,
    ): array {
        if ($campaignTemplate && $campaignKey !== null && $campaignStep !== null) {
            $moduleKey = 'campaigns';
            $moduleLabel = 'Campaigns';
            $groupKey = "campaign:{$campaignKey}";
            $groupLabel = $this->headline($campaignKey);
            $itemLabel = 'Step '.$campaignStep.' '.$this->channelLabel($channel);
            $itemOrder = $campaignStep;
            $usageType = 'campaign_step';
        } else {
            $moduleKey = $this->moduleKeyForScope($scope);
            $moduleLabel = $this->moduleLabel($moduleKey);
            $normalizedSourceType = $this->normalizeSegment(Str::singular($sourceMessageType));
            $groupKey = implode(':', array_filter([$moduleKey, $purpose, $scope, $normalizedSourceType]));
            $groupLabel = $this->groupLabelForMessageType($scope, $sourceMessageType);
            $itemLabel = $this->itemLabelForMessage(
                channel: $channel,
                sourceMessageType: $sourceMessageType,
                messageType: $messageType,
                definition: $definition,
                listIndex: $listIndex,
            );
            $itemOrder = $this->itemOrderForMessage($definition, $listIndex);
            $usageType = $this->usageTypeForMessage($scope, $sourceMessageType);
        }

        $itemKey = $this->presetKey($configPath);
        $displayName = $groupLabel.' — '.$itemLabel;

        return [
            'display_name' => $displayName,
            'group_key' => $groupKey,
            'group_label' => $groupLabel,
            'item_key' => $itemKey,
            'item_label' => $itemLabel,
            'usage_type' => $usageType,
            'attributes' => [
                'message_template_preset_id' => null,
                'channel' => $channel,
                'purpose' => $purpose,
                'scope' => $scope,
                'module_key' => $moduleKey,
                'module_label' => $moduleLabel,
                'surface' => $surface,
                'group_key' => $groupKey,
                'group_label' => $groupLabel,
                'item_key' => $itemKey,
                'item_label' => $itemLabel,
                'item_order' => $itemOrder,
                'usage_type' => $usageType,
                'source' => 'config',
                'source_config_path' => $configPath,
                'context_type' => null,
                'context_id' => null,
                'is_active' => true,
                'meta' => [
                    'message_type' => $messageType,
                    'source_message_type' => $sourceMessageType,
                    'campaign_key' => $campaignKey,
                    'campaign_step' => $campaignStep,
                ],
            ],
        ];
    }

    private function indexedMessageType(string $messageType, array $definition, int $index): string
    {
        $base = $this->normalizeSegment(Str::singular($messageType));

        if ($base === 'reminder') {
            $suffix = $this->scheduleSuffix($definition);

            return $suffix !== null ? "reminder_{$suffix}" : "reminder_".($index + 1);
        }

        return $base.'_'.($index + 1);
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

    private function surfaceForScope(string $scope): ?string
    {
        return match ($scope) {
            'webinar' => 'webinar_registrations',
            'webinar_waitlist' => 'webinar_waitlists',
            default => null,
        };
    }

    private function moduleKeyForScope(string $scope): string
    {
        return str_starts_with($scope, 'webinar') ? 'webinars' : 'messaging';
    }

    private function moduleLabel(string $moduleKey): string
    {
        return match ($moduleKey) {
            'campaigns' => 'Campaigns',
            'webinars' => 'Webinars',
            default => 'Messaging',
        };
    }

    private function groupLabelForMessageType(string $scope, string $sourceMessageType): string
    {
        $sourceMessageType = $this->normalizeSegment($sourceMessageType);

        return match (true) {
            $scope === 'webinar' && in_array($sourceMessageType, ['confirmation', 'confirmations'], true) => 'Webinar Confirmations',
            $scope === 'webinar' && in_array($sourceMessageType, ['opt_in', 'opt_ins'], true) => 'Webinar Opt-Ins',
            $scope === 'webinar' && in_array($sourceMessageType, ['reminder', 'reminders'], true) => 'Webinar Reminders',
            $scope === 'webinar' && $sourceMessageType === 'post_attended' => 'Post-Webinar Follow-Up',
            $scope === 'webinar' && $sourceMessageType === 'post_missed' => 'Post-Webinar Follow-Up',
            $scope === 'webinar_waitlist' && in_array($sourceMessageType, ['alert', 'alerts'], true) => 'Webinar Waitlist Alerts',
            $scope === 'webinar_waitlist' && in_array($sourceMessageType, ['opt_in', 'opt_ins'], true) => 'Webinar Waitlist Opt-Ins',
            default => $this->headline($scope).' — '.$this->headline($sourceMessageType),
        };
    }

    /**
     * @param array<string, mixed> $definition
     */
    private function itemLabelForMessage(
        string $channel,
        string $sourceMessageType,
        string $messageType,
        array $definition,
        ?int $listIndex,
    ): string {
        if (str_starts_with($messageType, 'reminder_')) {
            $reminderLabel = $this->reminderLabel($definition);

            return $reminderLabel.' '.$this->channelLabel($channel);
        }

        $base = match ($this->normalizeSegment($sourceMessageType)) {
            'confirmation', 'confirmations' => 'Confirmation',
            'opt_in', 'opt_ins' => 'Opt-In',
            'alert', 'alerts' => 'Alert',
            'post_attended' => 'Attended Follow-Up',
            'post_missed' => 'Missed Follow-Up',
            default => $this->headline(Str::singular($sourceMessageType)),
        };

        if ($listIndex !== null && $listIndex > 0) {
            $base .= ' '.($listIndex + 1);
        }

        return $base.' '.$this->channelLabel($channel);
    }

    /**
     * @param array<string, mixed> $definition
     */
    private function itemOrderForMessage(array $definition, ?int $listIndex): int
    {
        $schedule = is_array($definition['schedule'] ?? null) ? $definition['schedule'] : null;

        if ($schedule !== null && is_int($schedule['minutes'] ?? null)) {
            return (int) $schedule['minutes'];
        }

        return $listIndex ?? 0;
    }

    private function usageTypeForMessage(string $scope, string $sourceMessageType): string
    {
        return $this->normalizeSegment($scope.'_'.Str::singular($sourceMessageType));
    }

    /**
     * @param array<string, mixed> $definition
     */
    private function reminderLabel(array $definition): string
    {
        $schedule = is_array($definition['schedule'] ?? null) ? $definition['schedule'] : [];
        $minutes = is_int($schedule['minutes'] ?? null) ? (int) $schedule['minutes'] : null;

        return match ($minutes) {
            -14400 => '10-Day Reminder',
            -10080 => '1-Week Reminder',
            -1440 => '1-Day Reminder',
            -30 => '30-Minute Reminder',
            -10 => '10-Minute Reminder',
            0, 5 => 'Live Reminder',
            default => $minutes === null
                ? 'Reminder'
                : abs($minutes).'-Minute '.($minutes < 0 ? 'Reminder' : 'Live Follow-Up'),
        };
    }

    /**
     * @param array<string, mixed> $definition
     */
    private function scheduleSuffix(array $definition): ?string
    {
        $schedule = is_array($definition['schedule'] ?? null) ? $definition['schedule'] : [];
        $minutes = is_int($schedule['minutes'] ?? null) ? (int) $schedule['minutes'] : null;

        return match ($minutes) {
            -14400 => '10_day',
            -10080 => '1_week',
            -1440 => '1_day',
            -30 => '30_minute',
            -10 => '10_minute',
            0, 5 => 'live',
            default => $minutes === null ? null : str_replace('-', 'minus_', (string) $minutes).'_minute',
        };
    }

    private function presetKey(string $configPath): string
    {
        return str_replace('-', '_', strtolower(preg_replace('/^messaging\./', '', $configPath) ?? $configPath));
    }

    private function headline(string $value): string
    {
        return Str::headline(str_replace(['.', '_', '-'], ' ', $value));
    }

    private function channelLabel(string $channel): string
    {
        return match ($channel) {
            'sms' => 'SMS',
            default => Str::headline($channel),
        };
    }

    private function normalizeSegment(string $value): string
    {
        return str_replace('-', '_', strtolower(trim($value)));
    }
}
