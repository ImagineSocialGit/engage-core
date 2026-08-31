<?php

namespace App\Modules\Messaging\Automation;

use App\Modules\Messaging\Data\Automation\SendMessageAutomationDefinition;
use App\Modules\Messaging\Models\MessageTemplatePreset;
use App\Modules\Messaging\Services\DirectMessageTemplateResolver;
use App\Modules\Messaging\Services\MessageChannelAvailability;
use App\Modules\Messaging\Services\MessageTemplateAuthoringFieldPresenter;
use App\Modules\Messaging\Services\MessageTemplateDisplayLabelResolver;
use App\Modules\Messaging\Services\RouteAuthoringMessageTemplateEligibilityResolver;
use App\Support\AutomationCapabilities\Contracts\AutomationPointAuthoringContributor;
use App\Support\AutomationCapabilities\Data\AutomationPointAuthoringContext;
use App\Support\AutomationCapabilities\Data\AutomationPointAuthoringDefinition;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class MessagingAutomationPointAuthoringContributor implements AutomationPointAuthoringContributor
{
    public function __construct(
        private readonly RouteAuthoringMessageTemplateEligibilityResolver $eligibility,
        private readonly DirectMessageTemplateResolver $directTemplates,
        private readonly MessageChannelAvailability $channelAvailability,
        private readonly MessageTemplateAuthoringFieldPresenter $authoringFields,
        private readonly MessageTemplateDisplayLabelResolver $displayLabels,
    ) {}

    public function definitions(): iterable
    {
        yield new AutomationPointAuthoringDefinition(
            pointType: 'send_message',
            moduleKey: 'messaging',
            name: 'Message',
            description: 'Start a conversation or reply automatically using a reusable message.',
            tip: 'For replies, configure every available channel. The Route sends only the template matching the channel of the inbound message.',
            useCases: [
                'Start a new conversation at this point in the Route.',
                'Reply automatically on the same channel as an inbound message.',
            ],
            typeLabel: 'Message',
            genericLabels: ['message', 'send message', 'automatic message'],
        );
    }

    public function available(string $pointType, AutomationPointAuthoringContext $context): bool
    {
        return $this->availableChannels() !== [];
    }

    public function fields(string $pointType, array $definition, AutomationPointAuthoringContext $context): array
    {
        $role = $this->role($definition);
        $availableChannels = $this->availableChannels();
        $replyAvailable = $this->replyAvailable($definition, $context);
        $fields = [[
            'type' => 'select',
            'name' => 'message_role',
            'label' => 'What kind of message is this?',
            'required' => true,
            'state' => true,
            'value' => $role,
            'options' => array_values(array_filter([
                [
                    'value' => SendMessageAutomationDefinition::ROLE_INITIATORY,
                    'label' => 'Starts a conversation',
                ],
                $replyAvailable ? [
                    'value' => SendMessageAutomationDefinition::ROLE_REPLY,
                    'label' => 'Replies to an inbound message',
                ] : null,
            ])),
            'help' => $replyAvailable
                ? 'A reply uses only the template matching the inbound message channel.'
                : 'Reply mode is available on Routes triggered by an inbound message.',
        ]];

        $selectedKey = trim((string) (
            $definition['message_template_key']
            ?? $definition['message_template_preset_key']
            ?? ''
        ));
        $fields[] = $this->pickerField(
            name: 'message_template_preset_id',
            label: 'Message template',
            selectedKey: $selectedKey,
            availableChannels: $availableChannels,
            role: SendMessageAutomationDefinition::ROLE_INITIATORY,
        );

        if ($replyAvailable) {
            $contextualKeys = $this->contextualTemplateKeys($definition);

            foreach ($availableChannels as $channel) {
                $fields[] = $this->pickerField(
                    name: 'message_template_preset_id_'.$channel,
                    label: $this->channelLabel($channel).' reply template',
                    selectedKey: $contextualKeys[$channel] ?? '',
                    availableChannels: [$channel],
                    role: SendMessageAutomationDefinition::ROLE_REPLY,
                );
            }
        }

        return $fields;
    }

    public function rules(string $pointType, AutomationPointAuthoringContext $context): array
    {
        $roles = [SendMessageAutomationDefinition::ROLE_INITIATORY];

        if ($this->replyAvailable([], $context)) {
            $roles[] = SendMessageAutomationDefinition::ROLE_REPLY;
        }

        $rules = [
            'message_role' => ['required', 'string', Rule::in($roles)],
            'message_template_preset_id' => [
                'nullable',
                'required_if:message_role,'.SendMessageAutomationDefinition::ROLE_INITIATORY,
                'integer',
                'exists:message_template_presets,id',
            ],
        ];

        foreach ($this->availableChannels() as $channel) {
            $rules['message_template_preset_id_'.$channel] = [
                'nullable',
                'required_if:message_role,'.SendMessageAutomationDefinition::ROLE_REPLY,
                'integer',
                'exists:message_template_presets,id',
            ];
        }

        return $rules;
    }

    public function buildDefinition(string $pointType, array $input, AutomationPointAuthoringContext $context): array
    {
        $role = trim((string) ($input['message_role'] ?? ''));

        if ($role === SendMessageAutomationDefinition::ROLE_REPLY) {
            if (! $this->replyAvailable([], $context)) {
                throw ValidationException::withMessages([
                    'message_role' => 'Reply messages are available only on Routes triggered by an inbound message.',
                ]);
            }

            $contextualTemplateKeys = [];

            foreach ($this->availableChannels() as $channel) {
                $field = 'message_template_preset_id_'.$channel;
                $preset = $this->eligiblePreset(
                    isset($input[$field]) ? (int) $input[$field] : 0,
                    $field,
                );

                if ((string) $preset->channel !== $channel
                    || $this->directTemplates->definition((string) $preset->key) === null
                ) {
                    throw ValidationException::withMessages([
                        $field => 'Choose a reusable '.$this->channelLabel($channel).' message that is available for direct Route use.',
                    ]);
                }

                $contextualTemplateKeys[$channel] = (string) $preset->key;
            }

            return [
                'message_role' => SendMessageAutomationDefinition::ROLE_REPLY,
                'message_template_keys_by_channel' => $contextualTemplateKeys,
                'message_template_channel_context_path' =>
                    SendMessageAutomationDefinition::REPLY_CHANNEL_CONTEXT_PATH,
                'on_no_messages' => 'skipped',
            ];
        }

        if ($role !== SendMessageAutomationDefinition::ROLE_INITIATORY) {
            throw ValidationException::withMessages([
                'message_role' => 'Choose whether this message starts a conversation or replies to an inbound message.',
            ]);
        }

        $preset = $this->eligiblePreset(
            isset($input['message_template_preset_id'])
                ? (int) $input['message_template_preset_id']
                : 0,
            'message_template_preset_id',
        );

        if ($this->directTemplates->definition((string) $preset->key) !== null) {
            return [
                'message_role' => SendMessageAutomationDefinition::ROLE_INITIATORY,
                'message_template_key' => (string) $preset->key,
                'on_no_messages' => 'skipped',
            ];
        }

        // Compatibility for older explicitly route-eligible presets that do not
        // yet have a canonical immutable MessageTemplate/current version.
        return [
            'message_role' => SendMessageAutomationDefinition::ROLE_INITIATORY,
            'message_template_preset_key' => (string) $preset->key,
            'channel' => (string) $preset->channel,
            'purpose' => (string) $preset->purpose,
            'scope' => (string) $preset->scope,
            'dispatch_keys' => $preset->dispatchKeys(),
            'on_no_messages' => 'skipped',
        ];
    }

    public function pointName(
        string $pointType,
        string $fallback,
        array $input,
        array $definition,
        AutomationPointAuthoringContext $context,
    ): string {
        return trim((string) ($input['name'] ?? '')) ?: 'Message';
    }

    public function summary(string $pointType, array $definition, AutomationPointAuthoringContext $context): string
    {
        return $this->role($definition) === SendMessageAutomationDefinition::ROLE_REPLY
            ? 'Reply on the inbound message channel.'
            : 'Start a conversation.';
    }

    public function editorSummary(string $pointType, array $definition, AutomationPointAuthoringContext $context): string
    {
        $contextualKeys = $this->contextualTemplateKeys($definition);

        if ($contextualKeys !== []) {
            return 'Replies automatically on the inbound channel';
        }

        return 'Starts conversation · '.$this->templateLabel(trim((string) (
            $definition['message_template_key']
            ?? $definition['message_template_preset_key']
            ?? ''
        )));
    }

    /** @return array<int, string> */
    private function availableChannels(): array
    {
        return array_values(array_unique([
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
        ]));
    }

    private function replyAvailable(array $definition, AutomationPointAuthoringContext $context): bool
    {
        if ($this->contextualTemplateKeys($definition) !== []) {
            return true;
        }

        $triggerType = $context->container?->getAttribute('trigger_type');
        $triggerKey = $context->container?->getAttribute('trigger_key');

        return $triggerType === 'automation_event'
            && Str::startsWith((string) $triggerKey, 'inbound_message.');
    }

    private function role(array $definition): string
    {
        $role = trim((string) ($definition['message_role'] ?? ''));

        if (in_array($role, SendMessageAutomationDefinition::ROLES, true)) {
            return $role;
        }

        return $this->contextualTemplateKeys($definition) !== []
            ? SendMessageAutomationDefinition::ROLE_REPLY
            : SendMessageAutomationDefinition::ROLE_INITIATORY;
    }

    private function templateLabel(string $key): string
    {
        $preset = $key !== ''
            ? MessageTemplatePreset::query()->where('key', $key)->first()
            : null;

        return $preset instanceof MessageTemplatePreset
            ? $this->displayLabels->selectionLabel($preset)
            : ($key !== '' ? Str::headline($key) : 'Selected message');
    }

    private function channelLabel(string $channel): string
    {
        return $channel === 'sms' ? 'Text' : Str::headline($channel);
    }

    /**
     * @param array<string, mixed> $definition
     * @return array<string, string>
     */
    private function contextualTemplateKeys(array $definition): array
    {
        $values = $definition['message_template_keys_by_channel'] ?? [];

        if (! is_array($values) || array_is_list($values)) {
            return [];
        }

        return collect($values)
            ->filter(fn (mixed $key, mixed $channel): bool =>
                is_string($channel)
                && trim($channel) !== ''
                && is_string($key)
                && trim($key) !== '')
            ->mapWithKeys(fn (string $key, string $channel): array => [
                trim($channel) => trim($key),
            ])
            ->sortKeys()
            ->all();
    }

    /** @return array<string, mixed> */
    private function pickerField(
        string $name,
        string $label,
        string $selectedKey,
        array $availableChannels,
        string $role,
    ): array {
        $selectedId = $selectedKey !== ''
            ? MessageTemplatePreset::query()->where('key', $selectedKey)->value('id')
            : null;
        $eligiblePresets = $this->eligibility->eligiblePresets();

        $eligiblePresets->each(
            fn (MessageTemplatePreset $preset) =>
                $preset->loadMissing('catalogEntries'),
        );

        return [
            'type' => 'component',
            'component' => 'messaging.route-message-template-picker',
            'name' => $name,
            'label' => $label,
            'required' => true,
            'value' => $selectedId,
            'show_when' => [
                'field' => 'message_role',
                'equals' => $role,
            ],
            'active_when' => [
                'field' => 'message_role',
                'equals' => $role,
            ],
            'help' => $role === SendMessageAutomationDefinition::ROLE_REPLY
                ? 'Required for '.$this->channelLabel($availableChannels[0] ?? '').' replies; only the inbound channel is sent.'
                : 'Choose the one message this Route should initiate.',
            'options' => $eligiblePresets
                ->filter(fn (MessageTemplatePreset $preset): bool =>
                    in_array((string) $preset->channel, $availableChannels, true))
                ->map(fn (MessageTemplatePreset $preset): array => [
                    'value' => (int) $preset->getKey(),
                    'label' => $this->displayLabels->selectionLabel($preset),
                    'description' => $this->channelLabel((string) $preset->channel).' · '.Str::headline((string) $preset->purpose),
                ])->values()->all(),
            'create_url' => route('crm.messaging.message-templates.flow-route.store'),
            'available_fields' => $this->authoringFields->groupsForContext('flow_route_send_message'),
            'available_channels' => $availableChannels,
            'purposes' => [
                ['value' => 'marketing', 'label' => 'Marketing / follow-up'],
                ['value' => 'transactional', 'label' => 'Transactional / service update'],
            ],
        ];
    }

    private function eligiblePreset(
        int $presetId,
        string $field,
    ): MessageTemplatePreset {
        $preset = $this->eligibility->eligiblePresets()
            ->first(fn (MessageTemplatePreset $candidate): bool =>
                (int) $candidate->getKey() === $presetId);

        if (! $preset instanceof MessageTemplatePreset) {
            throw ValidationException::withMessages([
                $field => 'Choose a message template that is available for direct Route use.',
            ]);
        }

        return $preset;
    }
}