<?php

namespace App\Modules\Messaging\ConfigContracts;

use App\Modules\Messaging\Enums\MessagePurpose;
use App\Modules\Messaging\Services\MessageDefinitionConfigSetResolver;
use App\Modules\Messaging\Support\MessageDefinitionConfigPath;
use App\Support\ConfigContracts\Contracts\ConfigContractTargetProvider;
use App\Support\ConfigContracts\Data\ConfigContractTarget;
use App\Support\ConfigContracts\Data\ConfigContractTargetContext;

final class MessagingConfigContractTargetProvider implements ConfigContractTargetProvider
{
    public function __construct(
        private readonly MessageDefinitionConfigSetResolver $configSetResolver,
    ) {}

    public function contractKeys(): array
    {
        return [
            'messaging.email_definition',
            'messaging.permission_invitation',
            'messaging.sms_definition',
        ];
    }

    public function targets(ConfigContractTargetContext $context): iterable
    {
        foreach (['email', 'sms'] as $channel) {
            foreach ($this->messageDefinitionTargets($context, $channel) as $target) {
                yield $target;
            }
        }

        yield new ConfigContractTarget(
            contractKey: 'messaging.permission_invitation',
            path: 'messaging.permission_invitations',
            value: $context->config('messaging.permission_invitations', []),
        );
    }

    /**
     * @return iterable<int, ConfigContractTarget>
     */
    private function messageDefinitionTargets(
        ConfigContractTargetContext $context,
        string $channel,
    ): iterable {
        $definitionsConfig = $context->config(
            MessageDefinitionConfigPath::definitionsRoot($channel),
            [],
        );

        if (! is_array($definitionsConfig)) {
            return;
        }

        foreach (MessagePurpose::values() as $purpose) {
            $purposeConfig = $definitionsConfig[$purpose] ?? null;

            if (! is_array($purposeConfig)) {
                continue;
            }

            foreach ($purposeConfig as $scope => $scopeConfig) {
                if (! is_array($scopeConfig)) {
                    continue;
                }

                $scopePath = MessageDefinitionConfigPath::scope(
                    $channel,
                    $purpose,
                    (string) $scope,
                );

                foreach (
                    $this->configSetResolver->sets(
                        (string) $scope,
                        $scopeConfig,
                    ) as $set
                ) {
                    $templateSetKey = $set['key'];
            $templateSetSourceKey = $set['source_key'];
                    $setPath = $templateSetKey === null
                        || $templateSetKey === MessageDefinitionConfigSetResolver::DEFAULT_TEMPLATE_SET_KEY
                            && ! array_key_exists(
                                MessageDefinitionConfigSetResolver::DEFAULT_TEMPLATE_SET_KEY,
                                $scopeConfig,
                            )
                        ? $scopePath
                        : MessageDefinitionConfigPath::templateSet(
                            $channel,
                            $purpose,
                            (string) $scope,
                            $templateSetSourceKey ?? $templateSetKey,
                        );

                    foreach ($this->definitionTargetsForSet(
                        channel: $channel,
                        purpose: (string) $purpose,
                        scope: (string) $scope,
                        templateSetKey: $templateSetKey,
                        definitions: $set['definitions'],
                        setPath: $setPath,
                    ) as $target) {
                        yield $target;
                    }
                }
            }
        }
    }

    /**
     * @param array<string|int, mixed> $definitions
     * @return iterable<int, ConfigContractTarget>
     */
    private function definitionTargetsForSet(
        string $channel,
        string $purpose,
        string $scope,
        ?string $templateSetKey,
        array $definitions,
        string $setPath,
    ): iterable {
        foreach ($definitions as $messageType => $definition) {
            if ($messageType === 'campaigns') {
                foreach ($this->campaignDefinitionTargets(
                    channel: $channel,
                    purpose: $purpose,
                    scope: $scope,
                    campaigns: $definition,
                    basePath: "{$setPath}.campaigns",
                ) as $target) {
                    yield $target;
                }

                continue;
            }

            $definitionPath = "{$setPath}.".(string) $messageType;
            $context = [
                'channel' => $channel,
                'purpose' => $purpose,
                'scope' => $scope,
                'template_set_key' => $templateSetKey,
                'message_type' => $messageType,
            ];

            if (
                is_array($definition)
                && array_is_list($definition)
                && $definition !== []
            ) {
                foreach ($definition as $index => $nestedDefinition) {
                    yield $this->messageTarget(
                        channel: $channel,
                        path: "{$definitionPath}.{$index}",
                        value: $nestedDefinition,
                        context: $context + [
                            'definition_index' => $index,
                        ],
                    );
                }

                continue;
            }

            yield $this->messageTarget(
                channel: $channel,
                path: $definitionPath,
                value: $definition,
                context: $context,
            );
        }
    }

    /**
     * @return iterable<int, ConfigContractTarget>
     */
    private function campaignDefinitionTargets(
        string $channel,
        string $purpose,
        string $scope,
        mixed $campaigns,
        string $basePath,
    ): iterable {
        if (! is_array($campaigns)) {
            return;
        }

        foreach ($campaigns as $campaignKey => $campaign) {
            if (! is_array($campaign)) {
                continue;
            }

            $steps = $campaign['steps'] ?? null;

            if (! is_array($steps)) {
                continue;
            }

            foreach ($steps as $stepNumber => $step) {
                if (! is_array($step)) {
                    continue;
                }

                $variants = $step['variants'] ?? null;

                if (! is_array($variants)) {
                    continue;
                }

                foreach ($variants as $variantKey => $variant) {
                    $path = sprintf(
                        '%s.%s.steps.%s.variants.%s',
                        $basePath,
                        (string) $campaignKey,
                        (string) $stepNumber,
                        (string) $variantKey,
                    );

                    yield $this->messageTarget(
                        channel: $channel,
                        path: $path,
                        value: $variant,
                        context: [
                            'channel' => $channel,
                            'purpose' => $purpose,
                            'scope' => $scope,
                            'campaign_key' => $campaignKey,
                            'step_number' => $stepNumber,
                            'variant_key' => $variantKey,
                            'campaign_template' => true,
                        ],
                    );
                }
            }
        }
    }

    /**
     * @param array<string, mixed> $context
     */
    private function messageTarget(
        string $channel,
        string $path,
        mixed $value,
        array $context,
    ): ConfigContractTarget {
        return new ConfigContractTarget(
            contractKey: $channel === 'email'
                ? 'messaging.email_definition'
                : 'messaging.sms_definition',
            path: $path,
            value: $value,
            context: $context,
        );
    }
}