<?php

namespace App\Modules\Messaging\Automation;

use App\Modules\Messaging\Data\Automation\SendMessageAutomationDefinition;
use App\Modules\Messaging\Enums\MessageChannel;
use App\Modules\Messaging\Services\DirectMessageTemplateResolver;
use App\Modules\Messaging\Services\MessageDefinitionResolver;
use App\Modules\Messaging\Services\MessageChannelAvailability;
use App\Support\AutomationCapabilities\Contracts\AutomationPointDefinitionContributor;
use App\Support\AutomationCapabilities\Data\AutomationPointDefinition;
use App\Support\AutomationCapabilities\Data\AutomationPointValidationContext;
use App\Support\ConfigContracts\Data\ConfigField;
use App\Support\ConfigContracts\Data\ConfigSchema;
use Throwable;

class MessagingAutomationPointDefinitionContributor implements AutomationPointDefinitionContributor
{
    private const OUTCOMES = ['completed', 'skipped', 'blocked', 'failed'];

    public function __construct(
        private readonly MessageDefinitionResolver $messageDefinitionResolver,
        private readonly DirectMessageTemplateResolver $directTemplates,
        private readonly MessageChannelAvailability $channelAvailability,
    ) {}

    public function definitions(): iterable
    {
        $open = $this->openSchema();

        yield new AutomationPointDefinition(
            pointType: 'send_message',
            schema: ConfigSchema::object([
                'message_template_key' => ConfigField::optional(ConfigSchema::string()),
                'message_template_preset_key' => ConfigField::optional(ConfigSchema::string()),
                'message_template_keys_by_channel' => ConfigField::optional($open),
                'message_template_channel_context_path' => ConfigField::optional(
                    ConfigSchema::string(),
                ),
                'message_role' => ConfigField::optional(
                    ConfigSchema::string(
                        allowedValues: SendMessageAutomationDefinition::ROLES,
                    ),
                ),
                'channel' => ConfigField::optional(
                    ConfigSchema::string(allowedValues: MessageChannel::values()),
                ),
                'purpose' => ConfigField::optional(ConfigSchema::string()),
                'scope' => ConfigField::optional(ConfigSchema::string()),
                'dispatch_key' => ConfigField::optional(ConfigSchema::string()),
                'dispatch_keys' => ConfigField::optional(
                    ConfigSchema::listOf(ConfigSchema::string()),
                ),
                'payload' => ConfigField::defaulted($open, []),
                'criteria' => ConfigField::defaulted($open, []),
                'anchor' => ConfigField::optional(ConfigSchema::mixed()),
                'on_no_messages' => ConfigField::defaulted(
                    ConfigSchema::string(allowedValues: self::OUTCOMES),
                    'skipped',
                ),
                'meta' => ConfigField::defaulted($open, []),
            ]),
        );
    }

    public function validate(
        string $pointType,
        array $definition,
        array $settings,
        AutomationPointValidationContext $context,
    ): iterable {
        if ($pointType !== 'send_message') {
            return;
        }

        $parsed = SendMessageAutomationDefinition::from(
            array_replace_recursive($definition, $settings),
        );

        if (! $parsed->isValid()) {
            yield $context->error(
                code: 'flow_routes.point_definition_invalid',
                message: "FlowRoute [{$context->containerKey}] point [{$context->pointKey}] has invalid [{$pointType}] definition [{$parsed->invalidReason}].",
                path: "{$context->path}.definition",
                context: [
                    'point_key' => $context->pointKey,
                    'point_type' => $pointType,
                    'invalid_reason' => $parsed->invalidReason,
                ],
            );

            return;
        }

        if ($parsed->usesContextualTemplateSelection()) {
            if ($parsed->isReply()) {
                foreach ($this->availableChannels() as $channel) {
                    if (isset($parsed->messageTemplateKeysByChannel[$channel])) {
                        continue;
                    }

                    yield $context->error(
                        code: 'flow_routes.messaging_reply_template_missing',
                        message: "FlowRoute [{$context->containerKey}] point [{$context->pointKey}] requires a reply Message Template for available channel [{$channel}].",
                        path: "{$context->path}.definition.message_template_keys_by_channel.{$channel}",
                        context: [
                            'point_key' => $context->pointKey,
                            'channel' => $channel,
                        ],
                    );
                }
            }

            foreach ($parsed->messageTemplateKeysByChannel as $channel => $templateKey) {
                if (! in_array($channel, MessageChannel::values(), true)) {
                    yield $context->error(
                        code: 'flow_routes.messaging_template_channel_invalid',
                        message: "FlowRoute [{$context->containerKey}] point [{$context->pointKey}] uses unsupported contextual message channel [{$channel}].",
                        path: "{$context->path}.definition.message_template_keys_by_channel.{$channel}",
                        context: [
                            'point_key' => $context->pointKey,
                            'channel' => $channel,
                        ],
                    );

                    continue;
                }

                $direct = $this->directTemplates->definition($templateKey);

                if (! is_array($direct)) {
                    yield $context->error(
                        code: 'flow_routes.messaging_template_missing',
                        message: "FlowRoute [{$context->containerKey}] point [{$context->pointKey}] references unavailable Message Template [{$templateKey}].",
                        path: "{$context->path}.definition.message_template_keys_by_channel.{$channel}",
                        context: [
                            'point_key' => $context->pointKey,
                            'message_template_key' => $templateKey,
                            'channel' => $channel,
                        ],
                    );

                    continue;
                }

                $templateChannel = trim((string) ($direct['channel'] ?? ''));

                if ($templateChannel !== $channel) {
                    yield $context->error(
                        code: 'flow_routes.messaging_template_channel_mismatch',
                        message: "FlowRoute [{$context->containerKey}] point [{$context->pointKey}] maps [{$channel}] to Message Template [{$templateKey}] on channel [{$templateChannel}].",
                        path: "{$context->path}.definition.message_template_keys_by_channel.{$channel}",
                        context: [
                            'point_key' => $context->pointKey,
                            'message_template_key' => $templateKey,
                            'expected_channel' => $channel,
                            'actual_channel' => $templateChannel,
                        ],
                    );
                }
            }

            return;
        }

        $candidateKey = $parsed->directTemplateCandidateKey();

        if ($candidateKey !== null) {
            $direct = $this->directTemplates->definition($candidateKey);

            if (is_array($direct)) {
                return;
            }

            if ($parsed->hasAuthoritativeTemplateKey()) {
                yield $context->error(
                    code: 'flow_routes.messaging_template_missing',
                    message: "FlowRoute [{$context->containerKey}] point [{$context->pointKey}] references unavailable Message Template [{$candidateKey}].",
                    path: "{$context->path}.definition.message_template_key",
                    context: [
                        'point_key' => $context->pointKey,
                        'message_template_key' => $candidateKey,
                    ],
                );

                return;
            }
        }

        try {
            $definitions = $this->messageDefinitionResolver->resolve(
                channel: $parsed->channel,
                purpose: $parsed->purpose,
                scope: $parsed->scope,
            );
        } catch (Throwable $exception) {
            yield $context->error(
                code: 'flow_routes.messaging_resolution_failed',
                message: "FlowRoute [{$context->containerKey}] point [{$context->pointKey}] could not resolve Messaging definitions: {$exception->getMessage()}",
                path: "{$context->path}.definition",
                context: ['point_key' => $context->pointKey],
                meta: ['exception' => $exception::class],
            );

            return;
        }

        foreach ($parsed->dispatchKeys as $dispatchKey) {
            $found = collect($definitions)->contains(function (mixed $definition) use ($dispatchKey): bool {
                if (! is_array($definition)) {
                    return false;
                }

                $keys = $definition['dispatch_keys'] ?? $definition['dispatch_key'] ?? [];
                $keys = is_string($keys) ? [$keys] : $keys;

                return is_array($keys) && in_array($dispatchKey, $keys, true);
            });

            if ($found) {
                continue;
            }

            yield $context->error(
                code: 'flow_routes.messaging_definition_missing',
                message: "FlowRoute [{$context->containerKey}] point [{$context->pointKey}] cannot resolve Messaging dispatch key [{$dispatchKey}] for [{$parsed->channel}:{$parsed->purpose}:{$parsed->scope}].",
                path: "{$context->path}.definition.dispatch_keys",
                context: [
                    'point_key' => $context->pointKey,
                    'dispatch_key' => $dispatchKey,
                    'channel' => $parsed->channel,
                    'purpose' => $parsed->purpose,
                    'scope' => $parsed->scope,
                ],
            );
        }
    }

    private function openSchema(): ConfigSchema
    {
        return ConfigSchema::object([], allowUnknown: true);
    }

    /** @return array<int, string> */
    private function availableChannels(): array
    {
        return array_values(array_intersect(
            MessageChannel::values(),
            array_values(array_unique([
                ...$this->channelAvailability->visibleChannelsForSurface(
                    surface: 'route_send_message_points',
                    purpose: 'marketing',
                    scope: 'general',
                ),
                ...$this->channelAvailability->visibleChannelsForSurface(
                    surface: 'route_send_message_points',
                    purpose: 'transactional',
                    scope: 'general',
                ),
            ])),
        ));
    }
}