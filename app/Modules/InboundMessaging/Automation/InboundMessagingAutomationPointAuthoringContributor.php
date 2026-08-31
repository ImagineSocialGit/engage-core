<?php

namespace App\Modules\InboundMessaging\Automation;

use App\Modules\Messaging\Automation\MessagingAutomationPointAuthoringContributor;
use App\Modules\Messaging\Services\MessageChannelAvailability;
use App\Support\AutomationCapabilities\Contracts\AutomationPointAuthoringContributor;
use App\Support\AutomationCapabilities\Data\AutomationPointAuthoringContext;
use App\Support\AutomationCapabilities\Data\AutomationPointAuthoringDefinition;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class InboundMessagingAutomationPointAuthoringContributor implements AutomationPointAuthoringContributor
{
    private const CHANNEL_CONTEXT_PATH =
        'automation_event.payload.inbound_message.channel';

    public function __construct(
        private readonly MessagingAutomationPointAuthoringContributor $messaging,
        private readonly MessageChannelAvailability $channelAvailability,
    ) {}

    public function definitions(): iterable
    {
        yield new AutomationPointAuthoringDefinition(
            pointType: 'automatic_message',
            moduleKey: 'inbound_messaging',
            name: 'Automatic message',
            description: 'Reply automatically on the same channel as the inbound message.',
            tip: 'Email replies use only the email template. Text replies use only the text template. If no message can be scheduled, the Inbox item remains open for a person.',
            useCases: [
                'Acknowledge a high-intent reply immediately.',
                'Record the automatic response and remove answered work from review.',
            ],
            typeLabel: 'Automatic message',
            genericLabels: ['automatic reply', 'automatic response'],
        );
    }

    public function available(
        string $pointType,
        AutomationPointAuthoringContext $context,
    ): bool {
        return $pointType === 'automatic_message'
            && $this->availableChannels() !== [];
    }

    public function fields(
        string $pointType,
        array $definition,
        AutomationPointAuthoringContext $context,
    ): array {
        $definition = $this->withContextualTemplateFields($definition);
        $fields = $this->messaging->fields(
            'send_message',
            $definition,
            $context,
        );

        return array_map(function (array $field): array {
            $name = (string) ($field['name'] ?? '');

            if (Str::endsWith($name, '_email')) {
                $field['label'] = 'Email reply template';
                $field['help'] = 'Used only when the incoming reply was received by email.';
            } elseif (Str::endsWith($name, '_sms')) {
                $field['label'] = 'Text reply template';
                $field['help'] = 'Used only when the incoming reply was received by text message.';
            }

            return $field;
        }, $fields);
    }

    public function rules(
        string $pointType,
        AutomationPointAuthoringContext $context,
    ): array {
        return $this->messaging->rules('send_message', $context);
    }

    public function buildDefinition(
        string $pointType,
        array $input,
        AutomationPointAuthoringContext $context,
    ): array {
        $definition = $this->messaging->buildDefinition(
            'send_message',
            $input,
            $context,
        );

        if (! is_array($definition['message_template_keys_by_channel'] ?? null)
            || $definition['message_template_keys_by_channel'] === []
        ) {
            throw ValidationException::withMessages([
                'message_template_preset_id' =>
                    'Choose at least one channel-specific reply template.',
            ]);
        }

        $definition['message_template_channel_context_path'] =
            self::CHANNEL_CONTEXT_PATH;

        return $definition;
    }

    public function pointName(
        string $pointType,
        string $fallback,
        array $input,
        array $definition,
        AutomationPointAuthoringContext $context,
    ): string {
        return trim((string) ($input['name'] ?? ''))
            ?: 'Automatic message';
    }

    public function summary(
        string $pointType,
        array $definition,
        AutomationPointAuthoringContext $context,
    ): string {
        return 'Reply automatically on the same channel as the inbound message.';
    }

    public function editorSummary(
        string $pointType,
        array $definition,
        AutomationPointAuthoringContext $context,
    ): string {
        return 'Same channel as inbound reply';
    }

    /** @param array<string, mixed> $definition */
    private function withContextualTemplateFields(array $definition): array
    {
        $keys = $definition['message_template_keys_by_channel'] ?? null;

        if (! is_array($keys) || $keys === []) {
            $keys = array_fill_keys(
                $this->availableChannels(),
                '__unselected__',
            );
        }

        return array_replace($definition, [
            'message_template_keys_by_channel' => $keys,
            'message_template_channel_context_path' =>
                self::CHANNEL_CONTEXT_PATH,
        ]);
    }

    /** @return array<int, string> */
    private function availableChannels(): array
    {
        return array_values(array_intersect(
            ['email', 'sms'],
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