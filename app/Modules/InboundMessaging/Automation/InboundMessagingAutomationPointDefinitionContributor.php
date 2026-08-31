<?php

namespace App\Modules\InboundMessaging\Automation;

use App\Modules\Messaging\Automation\MessagingAutomationPointDefinitionContributor;
use App\Modules\Messaging\Data\Automation\SendMessageAutomationDefinition;
use App\Support\AutomationCapabilities\Contracts\AutomationPointDefinitionContributor;
use App\Support\AutomationCapabilities\Data\AutomationPointDefinition;
use App\Support\AutomationCapabilities\Data\AutomationPointValidationContext;

class InboundMessagingAutomationPointDefinitionContributor implements AutomationPointDefinitionContributor
{
    private const CHANNEL_CONTEXT_PATH =
        'automation_event.payload.inbound_message.channel';

    public function __construct(
        private readonly MessagingAutomationPointDefinitionContributor $messaging,
    ) {}

    public function definitions(): iterable
    {
        foreach ($this->messaging->definitions() as $definition) {
            if ($definition->pointType !== 'send_message') {
                continue;
            }

            yield new AutomationPointDefinition(
                pointType: 'automatic_message',
                schema: $definition->schema,
            );
        }
    }

    public function validate(
        string $pointType,
        array $definition,
        array $settings,
        AutomationPointValidationContext $context,
    ): iterable {
        if ($pointType !== 'automatic_message') {
            return;
        }

        $values = array_replace_recursive($definition, $settings);
        $parsed = SendMessageAutomationDefinition::from($values);

        if (! $parsed->usesContextualTemplateSelection()) {
            yield $context->error(
                code: 'flow_routes.inbound_automatic_message_templates_missing',
                message: "FlowRoute [{$context->containerKey}] point [{$context->pointKey}] requires channel-specific automatic-message templates.",
                path: "{$context->path}.definition.message_template_keys_by_channel",
                context: ['point_key' => $context->pointKey],
            );

            return;
        }

        if ($parsed->messageTemplateChannelContextPath
            !== self::CHANNEL_CONTEXT_PATH
        ) {
            yield $context->error(
                code: 'flow_routes.inbound_automatic_message_channel_invalid',
                message: "FlowRoute [{$context->containerKey}] point [{$context->pointKey}] must reply on the channel that received the inbound message.",
                path: "{$context->path}.definition.message_template_channel_context_path",
                context: ['point_key' => $context->pointKey],
            );

            return;
        }

        yield from $this->messaging->validate(
            'send_message',
            $definition,
            $settings,
            $context,
        );
    }
}