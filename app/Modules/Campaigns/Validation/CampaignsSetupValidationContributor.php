<?php

namespace App\Modules\Campaigns\Validation;

use App\Modules\Messaging\Support\MessageDefinitionConfigPath;
use App\Modules\Campaigns\Data\CampaignPresetDefinition;
use App\Modules\Campaigns\Models\Campaign;
use App\Modules\Campaigns\Models\CampaignStep;
use App\Modules\Campaigns\Models\CampaignStepVariant;
use App\Modules\Campaigns\Services\CampaignMessageDefinitionResolver;
use App\Modules\Messaging\Enums\MessageChannel;
use App\Modules\Messaging\Enums\MessagePurpose;
use App\Modules\Messaging\Services\MessageChannelAvailability;
use App\Support\SetupValidation\Contracts\SetupValidationContributor;
use App\Support\SetupValidation\Data\SetupValidationFinding;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use Throwable;
use App\Support\Presets\Enums\PresetDomain;
use App\Support\Presets\PresetCompositionResolver;
use App\Support\Presets\PresetPackageResolver;

class CampaignsSetupValidationContributor implements SetupValidationContributor
{
    private const SOURCE = 'preset_composition.campaigns';
    private const MODULE = 'campaigns';

    private const VARIANT_STRATEGIES = [
        'first_available',
        'send_all_eligible',
        'dependency_aware',
    ];

    private const DEPENDENCY_STATES = [
        'scheduled',
        'pending',
        'sent',
        'skipped',
        'failed',
        'terminal',
        'unavailable',
    ];

    public function __construct(
        private readonly CampaignMessageDefinitionResolver $campaignMessageDefinitionResolver,
        private readonly MessageChannelAvailability $messageChannelAvailability,
        private readonly PresetCompositionResolver $compositionResolver,
        private readonly PresetPackageResolver $packageResolver,
    ) {}

    public function findings(): iterable
    {
        yield from $this->validateSelectedPresetDefinitions();
        yield from $this->validateRuntimeCampaigns();
    }

    /**
     * @return iterable<int, SetupValidationFinding>
     */
private function validateSelectedPresetDefinitions(): iterable
    {
        $presetKey = $this->packageResolver->resolvePresetKey();

        if ($presetKey === null) {
            return;
        }

        try {
            $resolved = $this->compositionResolver->resolve(
                presetKey: $presetKey,
                domain: PresetDomain::Campaigns,
            );
        } catch (Throwable) {
            return;
        }

        foreach ($resolved->definitions as $campaignKey => $definition) {
            $groupKey = $resolved->definitionGroups[$campaignKey][0] ?? 'selected';
            $source = $resolved->provenance[$campaignKey]['source'] ?? self::SOURCE;
            $path = "{$source}.definitions.{$campaignKey}";

            yield from $this->validatePresetDefinition(
                presetKey: $presetKey,
                groupKey: $groupKey,
                campaignKey: $campaignKey,
                definition: $definition,
                path: $path,
            );
        }

        yield from $this->validateSelectedCampaignMessagingTemplateOrphans(
            selectedCampaignKeys: array_keys($resolved->definitions),
            allDefinitions: $resolved->definitions,
        );
    }

    /**
     * @param array<int, string> $selectedCampaignKeys
     * @param array<string, mixed> $allDefinitions
     * @return iterable<int, SetupValidationFinding>
     */
    private function validateSelectedCampaignMessagingTemplateOrphans(
        array $selectedCampaignKeys,
        array $allDefinitions,
    ): iterable {
        foreach ($selectedCampaignKeys as $campaignKey) {
            $definition = $allDefinitions[$campaignKey] ?? null;

            if (! is_array($definition)) {
                continue;
            }

            $expectedIdentities = $this->selectedCampaignMessagingTemplateIdentities(
                campaignKey: $campaignKey,
                definition: $definition,
            );

            foreach (['email', 'sms'] as $channel) {
                foreach (['transactional', 'marketing', 'internal'] as $purpose) {
                    $purposeConfig = config(MessageDefinitionConfigPath::purpose($channel, $purpose), []);

                    if (! is_array($purposeConfig)) {
                        continue;
                    }

                    foreach ($purposeConfig as $scope => $scopeConfig) {
                        if (! $this->filledString($scope) || ! is_array($scopeConfig)) {
                            continue;
                        }

                        $messagingCampaign = data_get($scopeConfig, "campaigns.{$campaignKey}");

                        if (! is_array($messagingCampaign)) {
                            continue;
                        }

                        $steps = $messagingCampaign['steps'] ?? null;

                        if (! is_array($steps)) {
                            continue;
                        }

                        foreach ($steps as $stepNumber => $stepDefinition) {
                            $stepNumber = filter_var($stepNumber, FILTER_VALIDATE_INT);

                            if (! is_int($stepNumber) || $stepNumber < 1 || ! is_array($stepDefinition)) {
                                continue;
                            }

                            if (! ($stepDefinition['enabled'] ?? true)) {
                                continue;
                            }

                            $variants = $stepDefinition['variants'] ?? null;

                            if (! is_array($variants)) {
                                continue;
                            }

                            foreach ($variants as $variantKey => $variantDefinition) {
                                if (! $this->filledString($variantKey)
                                    || ! is_array($variantDefinition)
                                    || ! ($variantDefinition['enabled'] ?? true)
                                ) {
                                    continue;
                                }

                                $variantKey = $this->normalizeSegment($variantKey);
                                $normalizedScope = $this->normalizeSegment($scope);
                                $identity = $this->campaignMessagingTemplateIdentity(
                                    channel: $channel,
                                    purpose: $purpose,
                                    scope: $normalizedScope,
                                    campaignKey: $campaignKey,
                                    stepNumber: $stepNumber,
                                    variantKey: $variantKey,
                                );

                                if (isset($expectedIdentities[$identity])) {
                                    continue;
                                }

                                yield $this->warning(
                                    code: 'campaigns.messaging_template_orphaned_from_selected_campaign',
                                    message: "Messaging campaign template [{$campaignKey}:{$stepNumber}:{$variantKey}] has no matching step variant in the selected Campaign definition.",
                                    path: MessageDefinitionConfigPath::campaignVariant(
                                        channel: $channel,
                                        purpose: $purpose,
                                        scope: $normalizedScope,
                                        campaignKey: $campaignKey,
                                        stepNumber: $stepNumber,
                                        variantKey: $variantKey,
                                    ),
                                    context: [
                                        'campaign_key' => $campaignKey,
                                        'step_number' => $stepNumber,
                                        'variant_key' => $variantKey,
                                        'channel' => $channel,
                                        'purpose' => $purpose,
                                        'scope' => $normalizedScope,
                                    ],
                                );
                            }
                        }
                    }
                }
            }
        }
    }

    /**
     * @param array<string, mixed> $definition
     * @return array<string, true>
     */
    private function selectedCampaignMessagingTemplateIdentities(
        string $campaignKey,
        array $definition,
    ): array {
        $identities = [];
        $steps = $definition['steps'] ?? null;

        if (! is_array($steps)) {
            return $identities;
        }

        foreach ($steps as $step) {
            if (! is_array($step)) {
                continue;
            }

            $stepNumber = filter_var($step['step_number'] ?? null, FILTER_VALIDATE_INT);

            if (! is_int($stepNumber) || $stepNumber < 1) {
                continue;
            }

            $variants = $step['variants'] ?? null;

            if (! is_array($variants)) {
                continue;
            }

            foreach ($variants as $variant) {
                if (! is_array($variant) || ! $this->filledString($variant['key'] ?? null)) {
                    continue;
                }

                $channel = $variant['channel'] ?? $step['channel'] ?? $definition['channel'] ?? null;
                $purpose = $variant['purpose'] ?? $step['purpose'] ?? $definition['purpose'] ?? null;
                $scope = $variant['scope'] ?? $step['scope'] ?? $definition['scope'] ?? null;

                if (! $this->filledString($channel)
                    || ! $this->filledString($purpose)
                    || ! $this->filledString($scope)
                ) {
                    continue;
                }

                $identity = $this->campaignMessagingTemplateIdentity(
                    channel: $channel,
                    purpose: $purpose,
                    scope: $scope,
                    campaignKey: $campaignKey,
                    stepNumber: $stepNumber,
                    variantKey: $variant['key'],
                );

                $identities[$identity] = true;
            }
        }

        return $identities;
    }

    private function campaignMessagingTemplateIdentity(
        string $channel,
        string $purpose,
        string $scope,
        string $campaignKey,
        int $stepNumber,
        string $variantKey,
    ): string {
        return implode('|', [
            $this->normalizeSegment($channel),
            $this->normalizeSegment($purpose),
            $this->normalizeSegment($scope),
            $this->normalizeSegment($campaignKey),
            $stepNumber,
            $this->normalizeSegment($variantKey),
        ]);
    }

    /**
     * @param array<string, mixed> $definition
     * @return iterable<int, SetupValidationFinding>
     */
    private function validatePresetDefinition(
        string $presetKey,
        string $groupKey,
        string $campaignKey,
        array $definition,
        string $path,
    ): iterable {
        $context = [
            'preset_key' => $presetKey,
            'group_key' => $groupKey,
            'campaign_key' => $campaignKey,
        ];

        try {
            CampaignPresetDefinition::fromArray(
                data: $definition,
                definitionKey: $campaignKey,
            );
        } catch (InvalidArgumentException $exception) {
            yield $this->error(
                code: 'campaigns.definition_invalid',
                message: $exception->getMessage(),
                path: $path,
                context: $context,
            );
        }

        if (array_key_exists('payload', $definition)) {
            yield $this->error(
                code: 'campaigns.reusable_copy_owned_by_campaign',
                message: "Campaign preset [{$campaignKey}] must not define reusable Messaging payload copy.",
                path: "{$path}.payload",
                context: $context,
            );
        }

        if (! is_array($definition['steps'] ?? null)) {
            yield $this->error(
                code: 'campaigns.steps_invalid',
                message: "Campaign preset [{$campaignKey}] [steps] must be an array.",
                path: "{$path}.steps",
                context: $context,
            );

            return;
        }

        $seenStepNumbers = [];

        foreach ($definition['steps'] as $stepIndex => $step) {
            $stepPath = "{$path}.steps.{$stepIndex}";

            if (! is_array($step)) {
                yield $this->error(
                    code: 'campaigns.step_invalid',
                    message: "Campaign preset [{$campaignKey}] contains a non-array step.",
                    path: $stepPath,
                    context: $context,
                );

                continue;
            }

            $stepNumber = filter_var($step['step_number'] ?? null, FILTER_VALIDATE_INT);

            if (! is_int($stepNumber) || $stepNumber < 1) {
                yield $this->error(
                    code: 'campaigns.step_number_invalid',
                    message: "Campaign preset [{$campaignKey}] contains a step with invalid [step_number].",
                    path: "{$stepPath}.step_number",
                    context: $context,
                );

                continue;
            }

            if (isset($seenStepNumbers[$stepNumber])) {
                yield $this->error(
                    code: 'campaigns.duplicate_step_number',
                    message: "Campaign preset [{$campaignKey}] contains duplicate step number [{$stepNumber}].",
                    path: "{$stepPath}.step_number",
                    context: $context + [
                        'step_number' => $stepNumber,
                    ],
                );
            } else {
                $seenStepNumbers[$stepNumber] = true;
            }

            yield from $this->validatePresetStep(
                campaignKey: $campaignKey,
                stepNumber: $stepNumber,
                step: $step,
                path: $stepPath,
                context: $context,
            );
        }
    }

    /**
     * @param array<string, mixed> $step
     * @param array<string, mixed> $context
     * @return iterable<int, SetupValidationFinding>
     */
    private function validatePresetStep(
        string $campaignKey,
        int $stepNumber,
        array $step,
        string $path,
        array $context,
    ): iterable {
        $strategy = $this->normalizeSegment((string) ($step['variant_strategy'] ?? 'first_available'));

        if (! in_array($strategy, self::VARIANT_STRATEGIES, true)) {
            yield $this->error(
                code: 'campaigns.variant_strategy_invalid',
                message: "Campaign [{$campaignKey}] step [{$stepNumber}] has unsupported variant strategy [{$strategy}].",
                path: "{$path}.variant_strategy",
                context: $context + [
                    'step_number' => $stepNumber,
                ],
            );
        }

        $dispatchKey = $this->normalizeSegment((string) ($step['dispatch_key'] ?? 'campaign_step_due'));

        if ($dispatchKey !== 'campaign_step_due') {
            yield $this->error(
                code: 'campaigns.dispatch_key_noncanonical',
                message: "Campaign [{$campaignKey}] step [{$stepNumber}] must use canonical dispatch key [campaign_step_due].",
                path: "{$path}.dispatch_key",
                context: $context + [
                    'step_number' => $stepNumber,
                    'dispatch_key' => $dispatchKey,
                ],
            );
        }

        if (array_key_exists('payload', $step)) {
            yield $this->error(
                code: 'campaigns.reusable_copy_owned_by_step',
                message: "Campaign [{$campaignKey}] step [{$stepNumber}] must not define reusable Messaging payload copy.",
                path: "{$path}.payload",
                context: $context + [
                    'step_number' => $stepNumber,
                ],
            );
        }

        yield from $this->validateTiming(
            campaignKey: $campaignKey,
            stepNumber: $stepNumber,
            criteria: $step['criteria'] ?? [],
            path: "{$path}.criteria",
            context: $context,
        );

        $variants = $step['variants'] ?? null;

        if (! is_array($variants) || $variants === []) {
            yield $this->error(
                code: 'campaigns.variants_missing',
                message: "Campaign [{$campaignKey}] step [{$stepNumber}] must define at least one variant.",
                path: "{$path}.variants",
                context: $context + [
                    'step_number' => $stepNumber,
                ],
            );

            return;
        }

        $variantKeys = [];

        foreach ($variants as $variantIndex => $variant) {
            $variantPath = "{$path}.variants.{$variantIndex}";

            if (! is_array($variant)) {
                yield $this->error(
                    code: 'campaigns.variant_invalid',
                    message: "Campaign [{$campaignKey}] step [{$stepNumber}] contains a non-array variant.",
                    path: $variantPath,
                    context: $context + [
                        'step_number' => $stepNumber,
                    ],
                );

                continue;
            }

            $variantKey = $variant['key'] ?? null;

            if (! $this->filledString($variantKey)) {
                yield $this->error(
                    code: 'campaigns.variant_key_missing',
                    message: "Campaign [{$campaignKey}] step [{$stepNumber}] contains a variant without a non-empty key.",
                    path: "{$variantPath}.key",
                    context: $context + [
                        'step_number' => $stepNumber,
                    ],
                );

                continue;
            }

            $variantKey = $this->normalizeSegment($variantKey);

            if (isset($variantKeys[$variantKey])) {
                yield $this->error(
                    code: 'campaigns.duplicate_variant_key',
                    message: "Campaign [{$campaignKey}] step [{$stepNumber}] contains duplicate variant key [{$variantKey}].",
                    path: "{$variantPath}.key",
                    context: $context + [
                        'step_number' => $stepNumber,
                        'variant_key' => $variantKey,
                    ],
                );
            } else {
                $variantKeys[$variantKey] = true;
            }

            yield from $this->validatePresetVariant(
                campaignKey: $campaignKey,
                stepNumber: $stepNumber,
                variantKey: $variantKey,
                variant: $variant,
                strategy: $strategy,
                siblingVariantKeys: array_values(array_unique(array_filter(array_map(
                    fn (mixed $candidate): ?string => is_array($candidate) && $this->filledString($candidate['key'] ?? null)
                        ? $this->normalizeSegment($candidate['key'])
                        : null,
                    $variants,
                )))),
                path: $variantPath,
                context: $context,
            );
        }
    }

    /**
     * @param array<string, mixed> $variant
     * @param array<int, string> $siblingVariantKeys
     * @param array<string, mixed> $context
     * @return iterable<int, SetupValidationFinding>
     */
    private function validatePresetVariant(
        string $campaignKey,
        int $stepNumber,
        string $variantKey,
        array $variant,
        string $strategy,
        array $siblingVariantKeys,
        string $path,
        array $context,
    ): iterable {
        foreach (['dispatch_key', 'channel', 'purpose', 'scope'] as $field) {
            if (! $this->filledString($variant[$field] ?? null)) {
                yield $this->error(
                    code: 'campaigns.variant_identity_missing',
                    message: "Campaign [{$campaignKey}] step [{$stepNumber}] variant [{$variantKey}] is missing [{$field}].",
                    path: "{$path}.{$field}",
                    context: $context + [
                        'step_number' => $stepNumber,
                        'variant_key' => $variantKey,
                        'field' => $field,
                    ],
                );
            }
        }

        if ($this->filledString($variant['dispatch_key'] ?? null)
            && $this->normalizeSegment($variant['dispatch_key']) !== 'campaign_step_due'
        ) {
            yield $this->error(
                code: 'campaigns.variant_dispatch_key_noncanonical',
                message: "Campaign [{$campaignKey}] step [{$stepNumber}] variant [{$variantKey}] must use [campaign_step_due].",
                path: "{$path}.dispatch_key",
                context: $context + [
                    'step_number' => $stepNumber,
                    'variant_key' => $variantKey,
                ],
            );
        }

        if (array_key_exists('payload', $variant)) {
            yield $this->error(
                code: 'campaigns.reusable_copy_owned_by_variant',
                message: "Campaign [{$campaignKey}] step [{$stepNumber}] variant [{$variantKey}] must not define reusable Messaging payload copy.",
                path: "{$path}.payload",
                context: $context + [
                    'step_number' => $stepNumber,
                    'variant_key' => $variantKey,
                ],
            );
        }

        $rules = $variant['dependency_rules'] ?? [];

        if ($rules !== [] && ! is_array($rules)) {
            yield $this->error(
                code: 'campaigns.dependency_rules_invalid',
                message: "Campaign [{$campaignKey}] step [{$stepNumber}] variant [{$variantKey}] dependency_rules must be an array.",
                path: "{$path}.dependency_rules",
                context: $context + [
                    'step_number' => $stepNumber,
                    'variant_key' => $variantKey,
                ],
            );

            return;
        }

        if ($strategy !== 'dependency_aware' && is_array($rules) && $rules !== []) {
            yield $this->warning(
                code: 'campaigns.dependency_rules_dormant',
                message: "Campaign [{$campaignKey}] step [{$stepNumber}] variant [{$variantKey}] defines dependency rules but strategy [{$strategy}] does not evaluate them.",
                path: "{$path}.dependency_rules",
                context: $context + [
                    'step_number' => $stepNumber,
                    'variant_key' => $variantKey,
                    'variant_strategy' => $strategy,
                ],
            );
        }

        if (is_array($rules)) {
            yield from $this->validateDependencyRules(
                campaignKey: $campaignKey,
                stepNumber: $stepNumber,
                variantKey: $variantKey,
                rules: $rules,
                siblingVariantKeys: $siblingVariantKeys,
                path: "{$path}.dependency_rules",
                context: $context,
            );
        }
    }

    /**
     * @param array<string, mixed> $rules
     * @param array<int, string> $siblingVariantKeys
     * @param array<string, mixed> $context
     * @return iterable<int, SetupValidationFinding>
     */
    private function validateDependencyRules(
        string $campaignKey,
        int $stepNumber,
        string $variantKey,
        array $rules,
        array $siblingVariantKeys,
        string $path,
        array $context,
    ): iterable {
        $requirements = [];

        foreach (($rules['requires_scheduled_variant_keys'] ?? []) as $requiredKey) {
            if ($this->filledString($requiredKey)) {
                $requirements[] = [
                    'variant_key' => $this->normalizeSegment($requiredKey),
                    'states' => ['scheduled'],
                ];
            }
        }

        $variantStates = $rules['requires_variant_states'] ?? [];

        if (is_array($variantStates)) {
            foreach ($variantStates as $requiredKey => $states) {
                if (! $this->filledString($requiredKey)) {
                    continue;
                }

                $requirements[] = [
                    'variant_key' => $this->normalizeSegment($requiredKey),
                    'states' => $this->normalizeStringList($states),
                ];
            }
        }

        $requires = $rules['requires'] ?? [];

        if (is_array($requires)) {
            foreach ($requires as $requirement) {
                if (! is_array($requirement)) {
                    continue;
                }

                $requiredKey = $requirement['variant_key']
                    ?? $requirement['variant']
                    ?? $requirement['key']
                    ?? null;

                if (! $this->filledString($requiredKey)) {
                    continue;
                }

                $requirements[] = [
                    'variant_key' => $this->normalizeSegment($requiredKey),
                    'states' => $this->normalizeStringList(
                        $requirement['states']
                            ?? $requirement['state']
                            ?? $requirement['status']
                            ?? 'scheduled',
                    ),
                ];
            }
        }

        foreach ($requirements as $requirement) {
            $requiredKey = $requirement['variant_key'];

            if ($requiredKey === $variantKey) {
                yield $this->error(
                    code: 'campaigns.dependency_self_reference',
                    message: "Campaign [{$campaignKey}] step [{$stepNumber}] variant [{$variantKey}] cannot depend on itself.",
                    path: $path,
                    context: $context + [
                        'step_number' => $stepNumber,
                        'variant_key' => $variantKey,
                    ],
                );
            } elseif (! in_array($requiredKey, $siblingVariantKeys, true)) {
                yield $this->error(
                    code: 'campaigns.dependency_sibling_missing',
                    message: "Campaign [{$campaignKey}] step [{$stepNumber}] variant [{$variantKey}] depends on missing sibling variant [{$requiredKey}].",
                    path: $path,
                    context: $context + [
                        'step_number' => $stepNumber,
                        'variant_key' => $variantKey,
                        'required_variant_key' => $requiredKey,
                    ],
                );
            }

            if ($requirement['states'] === []) {
                yield $this->error(
                    code: 'campaigns.dependency_states_missing',
                    message: "Campaign [{$campaignKey}] step [{$stepNumber}] variant [{$variantKey}] dependency [{$requiredKey}] requires at least one state.",
                    path: $path,
                    context: $context + [
                        'step_number' => $stepNumber,
                        'variant_key' => $variantKey,
                        'required_variant_key' => $requiredKey,
                    ],
                );

                continue;
            }

            foreach ($requirement['states'] as $state) {
                if (! in_array($state, self::DEPENDENCY_STATES, true)) {
                    yield $this->error(
                        code: 'campaigns.dependency_state_invalid',
                        message: "Campaign [{$campaignKey}] step [{$stepNumber}] variant [{$variantKey}] uses unsupported dependency state [{$state}].",
                        path: $path,
                        context: $context + [
                            'step_number' => $stepNumber,
                            'variant_key' => $variantKey,
                            'required_variant_key' => $requiredKey,
                            'state' => $state,
                        ],
                    );
                }
            }
        }
    }

    /**
     * @param array<string, mixed>|mixed $criteria
     * @param array<string, mixed> $context
     * @return iterable<int, SetupValidationFinding>
     */
    private function validateTiming(
        string $campaignKey,
        int $stepNumber,
        mixed $criteria,
        string $path,
        array $context,
    ): iterable {
        if (! is_array($criteria)) {
            yield $this->error(
                code: 'campaigns.criteria_invalid',
                message: "Campaign [{$campaignKey}] step [{$stepNumber}] criteria must be an array.",
                path: $path,
                context: $context + [
                    'step_number' => $stepNumber,
                ],
            );

            return;
        }

        $timing = $criteria['timing'] ?? $criteria['schedule'] ?? null;

        if ($timing === null) {
            return;
        }

        if (! is_array($timing)) {
            yield $this->error(
                code: 'campaigns.timing_invalid',
                message: "Campaign [{$campaignKey}] step [{$stepNumber}] timing must be an array.",
                path: "{$path}.timing",
                context: $context + [
                    'step_number' => $stepNumber,
                ],
            );

            return;
        }

        $type = $this->normalizeSegment((string) ($timing['type'] ?? 'immediate'));

        if (! in_array($type, ['immediate', 'delay', 'anchored'], true)) {
            yield $this->error(
                code: 'campaigns.timing_type_invalid',
                message: "Campaign [{$campaignKey}] step [{$stepNumber}] has unsupported timing type [{$type}].",
                path: "{$path}.timing.type",
                context: $context + [
                    'step_number' => $stepNumber,
                ],
            );

            return;
        }

        if ($type === 'immediate') {
            return;
        }

        $units = array_values(array_filter(
            ['minutes', 'hours', 'days'],
            fn (string $unit): bool => array_key_exists($unit, $timing),
        ));

        if (count($units) !== 1) {
            yield $this->error(
                code: 'campaigns.timing_unit_invalid',
                message: "Campaign [{$campaignKey}] step [{$stepNumber}] scheduled timing must define exactly one of minutes, hours, or days.",
                path: "{$path}.timing",
                context: $context + [
                    'step_number' => $stepNumber,
                ],
            );

            return;
        }

        $unit = $units[0];

        if (! is_int($timing[$unit]) && ! (is_string($timing[$unit]) && preg_match('/^-?\d+$/', trim($timing[$unit])) === 1)) {
            yield $this->error(
                code: 'campaigns.timing_value_invalid',
                message: "Campaign [{$campaignKey}] step [{$stepNumber}] timing [{$unit}] must be an integer.",
                path: "{$path}.timing.{$unit}",
                context: $context + [
                    'step_number' => $stepNumber,
                    'unit' => $unit,
                ],
            );
        }
    }

    /**
     * @return iterable<int, SetupValidationFinding>
     */
    private function validateRuntimeCampaigns(): iterable
    {
        /** @var Collection<int, Campaign> $campaigns */
        $campaigns = Campaign::query()
            ->with(['steps.variants'])
            ->orderBy('key')
            ->get();

        foreach ($campaigns as $campaign) {
            if (! $campaign->isActive()) {
                continue;
            }

            $campaignPath = "campaigns.{$campaign->getKey()}";

            if (! $this->filledString($campaign->key)) {
                yield $this->error(
                    code: 'campaigns.runtime_key_missing',
                    message: "Active Campaign [{$campaign->getKey()}] has no stable key.",
                    path: "{$campaignPath}.key",
                    context: [
                        'campaign_id' => $campaign->getKey(),
                    ],
                );

                continue;
            }

            $duplicateStepNumbers = $campaign->steps
                ->groupBy(fn (CampaignStep $step): int => (int) $step->step_number)
                ->filter(fn (Collection $steps): bool => $steps->count() > 1);

            foreach ($duplicateStepNumbers as $stepNumber => $steps) {
                yield $this->error(
                    code: 'campaigns.runtime_duplicate_step_number',
                    message: "Active Campaign [{$campaign->key}] contains duplicate step number [{$stepNumber}].",
                    path: "{$campaignPath}.steps",
                    context: [
                        'campaign_id' => $campaign->getKey(),
                        'campaign_key' => $campaign->key,
                        'step_number' => (int) $stepNumber,
                        'step_ids' => $steps->pluck('id')->map(fn (mixed $id): int => (int) $id)->all(),
                    ],
                );
            }

            foreach ($campaign->steps as $step) {
                if (! $step->is_active) {
                    continue;
                }

                yield from $this->validateRuntimeStep($campaign, $step);
            }
        }
    }

    /**
     * @return iterable<int, SetupValidationFinding>
     */
    private function validateRuntimeStep(Campaign $campaign, CampaignStep $step): iterable
    {
        $path = "campaign_steps.{$step->getKey()}";
        $context = [
            'campaign_id' => $campaign->getKey(),
            'campaign_key' => $campaign->key,
            'campaign_step_id' => $step->getKey(),
            'step_number' => $step->step_number,
        ];

        $strategy = $this->normalizeSegment((string) $step->variant_strategy);

        if (! in_array($strategy, self::VARIANT_STRATEGIES, true)) {
            yield $this->error(
                code: 'campaigns.runtime_variant_strategy_invalid',
                message: "Active Campaign [{$campaign->key}] step [{$step->step_number}] has unsupported variant strategy [{$strategy}].",
                path: "{$path}.variant_strategy",
                context: $context,
            );
        }

        if ($this->normalizeSegment((string) $step->dispatch_key) !== 'campaign_step_due') {
            yield $this->error(
                code: 'campaigns.runtime_dispatch_key_noncanonical',
                message: "Active Campaign [{$campaign->key}] step [{$step->step_number}] must use [campaign_step_due].",
                path: "{$path}.dispatch_key",
                context: $context,
            );
        }

        $activeVariants = $step->variants
            ->filter(fn (CampaignStepVariant $variant): bool => $variant->is_active)
            ->values();

        if ($activeVariants->isEmpty()) {
            yield $this->error(
                code: 'campaigns.runtime_active_variants_missing',
                message: "Active Campaign [{$campaign->key}] step [{$step->step_number}] has no active variants.",
                path: "{$path}.variants",
                context: $context,
            );

            return;
        }

        $duplicateVariantKeys = $activeVariants
            ->groupBy(fn (CampaignStepVariant $variant): string => $this->normalizeSegment((string) $variant->key))
            ->filter(fn (Collection $variants): bool => $variants->count() > 1);

        foreach ($duplicateVariantKeys as $variantKey => $variants) {
            yield $this->error(
                code: 'campaigns.runtime_duplicate_variant_key',
                message: "Active Campaign [{$campaign->key}] step [{$step->step_number}] contains duplicate active variant key [{$variantKey}].",
                path: "{$path}.variants",
                context: $context + [
                    'variant_key' => $variantKey,
                    'variant_ids' => $variants->pluck('id')->map(fn (mixed $id): int => (int) $id)->all(),
                ],
            );
        }

        $siblingKeys = $activeVariants
            ->map(fn (CampaignStepVariant $variant): string => $this->normalizeSegment((string) $variant->key))
            ->all();

        foreach ($activeVariants as $variant) {
            yield from $this->validateRuntimeVariant(
                campaign: $campaign,
                step: $step,
                variant: $variant,
                strategy: $strategy,
                siblingVariantKeys: $siblingKeys,
            );
        }
    }

    /**
     * @param array<int, string> $siblingVariantKeys
     * @return iterable<int, SetupValidationFinding>
     */
    private function validateRuntimeVariant(
        Campaign $campaign,
        CampaignStep $step,
        CampaignStepVariant $variant,
        string $strategy,
        array $siblingVariantKeys,
    ): iterable {
        $path = "campaign_step_variants.{$variant->getKey()}";
        $variantKey = $this->normalizeSegment((string) $variant->key);
        $context = [
            'campaign_id' => $campaign->getKey(),
            'campaign_key' => $campaign->key,
            'campaign_step_id' => $step->getKey(),
            'step_number' => $step->step_number,
            'campaign_step_variant_id' => $variant->getKey(),
            'variant_key' => $variantKey,
        ];

        foreach (['key', 'dispatch_key', 'channel', 'purpose', 'scope'] as $field) {
            if (! $this->filledString($variant->{$field})) {
                yield $this->error(
                    code: 'campaigns.runtime_variant_identity_missing',
                    message: "Active Campaign variant [{$campaign->key}:{$step->step_number}:{$variantKey}] is missing [{$field}].",
                    path: "{$path}.{$field}",
                    context: $context + [
                        'field' => $field,
                    ],
                );
            }
        }

        if ($this->normalizeSegment((string) $variant->dispatch_key) !== 'campaign_step_due') {
            yield $this->error(
                code: 'campaigns.runtime_variant_dispatch_key_noncanonical',
                message: "Active Campaign variant [{$campaign->key}:{$step->step_number}:{$variantKey}] must use [campaign_step_due].",
                path: "{$path}.dispatch_key",
                context: $context,
            );
        }

        if (! in_array($variant->channel, MessageChannel::values(), true)) {
            yield $this->error(
                code: 'campaigns.runtime_variant_channel_invalid',
                message: "Active Campaign variant [{$campaign->key}:{$step->step_number}:{$variantKey}] has unsupported channel [{$variant->channel}].",
                path: "{$path}.channel",
                context: $context,
            );

            return;
        }

        if (! in_array($variant->purpose, MessagePurpose::values(), true)) {
            yield $this->error(
                code: 'campaigns.runtime_variant_purpose_invalid',
                message: "Active Campaign variant [{$campaign->key}:{$step->step_number}:{$variantKey}] has unsupported purpose [{$variant->purpose}].",
                path: "{$path}.purpose",
                context: $context,
            );

            return;
        }

        $rules = is_array($variant->dependency_rules) ? $variant->dependency_rules : [];

        if ($strategy !== 'dependency_aware' && $rules !== []) {
            yield $this->warning(
                code: 'campaigns.runtime_dependency_rules_dormant',
                message: "Active Campaign variant [{$campaign->key}:{$step->step_number}:{$variantKey}] defines dependency rules but strategy [{$strategy}] does not evaluate them.",
                path: "{$path}.dependency_rules",
                context: $context,
            );
        }

        yield from $this->validateDependencyRules(
            campaignKey: $campaign->key,
            stepNumber: (int) $step->step_number,
            variantKey: $variantKey,
            rules: $rules,
            siblingVariantKeys: $siblingVariantKeys,
            path: "{$path}.dependency_rules",
            context: $context,
        );

        if (! $this->messageChannelAvailability->isVisibleForSurface(
            channel: $variant->channel,
            surface: 'campaigns',
            purpose: $variant->purpose,
            scope: $variant->scope,
        )) {
            yield $this->warning(
                code: 'campaigns.channel_unavailable_for_surface',
                message: "Active Campaign variant [{$campaign->key}:{$step->step_number}:{$variantKey}] references channel [{$variant->channel}] that is not currently available for Campaigns.",
                path: "{$path}.channel",
                context: $context,
            );

            return;
        }

        try {
            $definition = $this->campaignMessageDefinitionResolver->resolve(
                campaign: $campaign,
                step: $step,
                variant: $variant,
            );
        } catch (Throwable $exception) {
            yield $this->error(
                code: 'campaigns.messaging_definition_missing',
                message: "Active Campaign variant [{$campaign->key}:{$step->step_number}:{$variantKey}] cannot resolve a compatible Messaging definition: {$exception->getMessage()}",
                path: $path,
                context: $context,
                meta: [
                    'exception' => $exception::class,
                ],
            );

            return;
        }

        $payload = $definition['payload'] ?? null;

        if (! is_array($payload) || $payload === []) {
            yield $this->warning(
                code: 'campaigns.messaging_definition_payload_unusable',
                message: "Active Campaign variant [{$campaign->key}:{$step->step_number}:{$variantKey}] resolves to a Messaging definition with no usable payload and will be skipped safely.",
                path: $path,
                context: $context,
            );
        }
    }

    /**
     * @return array<int, string>
     */
    private function normalizeStringList(mixed $values): array
    {
        if (is_string($values)) {
            $values = [$values];
        }

        if (! is_array($values)) {
            return [];
        }

        return array_values(array_unique(array_filter(array_map(
            fn (mixed $value): ?string => $this->filledString($value)
                ? $this->normalizeSegment($value)
                : null,
            $values,
        ))));
    }

    private function filledString(mixed $value): bool
    {
        return is_string($value) && trim($value) !== '';
    }

    private function normalizeSegment(string $value): string
    {
        return str_replace('-', '_', strtolower(trim($value)));
    }

    /**
     * @param array<string, mixed> $context
     * @param array<string, mixed> $meta
     */
    private function error(
        string $code,
        string $message,
        string $path,
        array $context = [],
        array $meta = [],
    ): SetupValidationFinding {
        return new SetupValidationFinding(
            severity: SetupValidationFinding::SEVERITY_ERROR,
            code: $code,
            message: $message,
            source: self::SOURCE,
            path: $path,
            module: self::MODULE,
            context: $context,
            meta: $meta,
        );
    }

    /**
     * @param array<string, mixed> $context
     */
    private function warning(
        string $code,
        string $message,
        string $path,
        array $context = [],
    ): SetupValidationFinding {
        return new SetupValidationFinding(
            severity: SetupValidationFinding::SEVERITY_WARNING,
            code: $code,
            message: $message,
            source: self::SOURCE,
            path: $path,
            module: self::MODULE,
            context: $context,
        );
    }
}
