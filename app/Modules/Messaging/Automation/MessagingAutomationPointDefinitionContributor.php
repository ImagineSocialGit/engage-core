<?php

namespace App\Modules\Messaging\Automation;

use App\Modules\Messaging\Data\Automation\SendMessageAutomationDefinition;
use App\Modules\Messaging\Enums\MessageChannel;
use App\Modules\Messaging\Services\DirectMessageTemplateResolver;
use App\Modules\Messaging\Services\MessageDefinitionResolver;
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
    ) {}

    public function definitions(): iterable
    {
        $open = $this->openSchema();

        yield new AutomationPointDefinition(
            pointType: 'send_message',
            schema: ConfigSchema::object([
                'message_template_key' => ConfigField::optional(ConfigSchema::string()),
                'message_template_preset_key' => ConfigField::optional(ConfigSchema::string()),
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
}