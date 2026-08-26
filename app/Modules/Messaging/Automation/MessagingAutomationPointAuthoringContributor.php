<?php

namespace App\Modules\Messaging\Automation;

use App\Modules\Messaging\Models\MessageTemplatePreset;
use App\Modules\Messaging\Services\DirectMessageTemplateResolver;
use App\Modules\Messaging\Services\MessageChannelAvailability;
use App\Modules\Messaging\Services\MessageTemplateAuthoringFieldPresenter;
use App\Modules\Messaging\Services\RouteAuthoringMessageTemplateEligibilityResolver;
use App\Support\AutomationCapabilities\Contracts\AutomationPointAuthoringContributor;
use App\Support\AutomationCapabilities\Data\AutomationPointAuthoringContext;
use App\Support\AutomationCapabilities\Data\AutomationPointAuthoringDefinition;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class MessagingAutomationPointAuthoringContributor implements AutomationPointAuthoringContributor
{
    public function __construct(
        private readonly RouteAuthoringMessageTemplateEligibilityResolver $eligibility,
        private readonly DirectMessageTemplateResolver $directTemplates,
        private readonly MessageChannelAvailability $channelAvailability,
        private readonly MessageTemplateAuthoringFieldPresenter $authoringFields,
    ) {}

    public function definitions(): iterable
    {
        yield new AutomationPointAuthoringDefinition(
            pointType: 'send_message',
            moduleKey: 'messaging',
            name: 'Send message',
            description: 'Send a reusable message through Messaging, subject to permissions and delivery rules.',
            tip: 'Choose an existing reusable message or create one here. Messaging keeps the copy and immutable versions centrally managed.',
            useCases: [
                'Send a reusable confirmation or follow-up message.',
                'Create a new reusable email or SMS message without leaving the Route editor.',
            ],
            typeLabel: 'Message',
            genericLabels: ['send message'],
        );
    }

    public function available(string $pointType, AutomationPointAuthoringContext $context): bool
    {
        return $this->availableChannels() !== [];
    }

    public function fields(string $pointType, array $definition, AutomationPointAuthoringContext $context): array
    {
        $selectedKey = trim((string) (
            $definition['message_template_key']
            ?? $definition['message_template_preset_key']
            ?? ''
        ));
        $selectedId = $selectedKey !== ''
            ? MessageTemplatePreset::query()->where('key', $selectedKey)->value('id')
            : null;

        return [[
            'type' => 'component',
            'component' => 'messaging.route-message-template-picker',
            'name' => 'message_template_preset_id',
            'label' => 'Message template',
            'required' => true,
            'value' => $selectedId,
            'help' => 'Choose a reusable direct-Route message. Lifecycle-owned Campaign, Webinar, permission-invitation, and internal-notification templates stay out of this picker.',
            'options' => $this->eligibility->eligiblePresets()
                ->map(fn (MessageTemplatePreset $preset): array => [
                    'value' => (int) $preset->getKey(),
                    'label' => (string) $preset->name,
                    'description' => Str::headline((string) $preset->channel).' · '.Str::headline((string) $preset->purpose),
                ])->all(),
            'create_url' => route('crm.messaging.message-templates.flow-route.store'),
            'available_fields' => $this->authoringFields->groupsForContext('flow_route_send_message'),
            'available_channels' => $this->availableChannels(),
            'purposes' => [
                ['value' => 'marketing', 'label' => 'Marketing / follow-up'],
                ['value' => 'transactional', 'label' => 'Transactional / service update'],
            ],
        ]];
    }

    public function rules(string $pointType, AutomationPointAuthoringContext $context): array
    {
        return [
            'message_template_preset_id' => ['required', 'integer', 'exists:message_template_presets,id'],
        ];
    }

    public function buildDefinition(string $pointType, array $input, AutomationPointAuthoringContext $context): array
    {
        $presetId = isset($input['message_template_preset_id'])
            ? (int) $input['message_template_preset_id']
            : 0;

        $preset = $this->eligibility->eligiblePresets()
            ->first(fn (MessageTemplatePreset $candidate): bool => (int) $candidate->getKey() === $presetId);

        if (! $preset instanceof MessageTemplatePreset) {
            throw ValidationException::withMessages([
                'message_template_preset_id' => 'Choose a message template that is available for direct Route use.',
            ]);
        }

        if ($this->directTemplates->definition((string) $preset->key) !== null) {
            return [
                'message_template_key' => (string) $preset->key,
                'on_no_messages' => 'skipped',
            ];
        }

        // Compatibility for older explicitly route-eligible presets that do not
        // yet have a canonical immutable MessageTemplate/current version.
        return [
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
        return trim((string) ($input['name'] ?? '')) ?: 'Send message';
    }

    public function summary(string $pointType, array $definition, AutomationPointAuthoringContext $context): string
    {
        return 'Send a message.';
    }

    public function editorSummary(string $pointType, array $definition, AutomationPointAuthoringContext $context): string
    {
        return $this->templateLabel(trim((string) (
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

    private function templateLabel(string $key): string
    {
        $name = $key !== ''
            ? MessageTemplatePreset::query()->where('key', $key)->value('name')
            : null;

        return is_string($name) && trim($name) !== ''
            ? $name
            : ($key !== '' ? Str::headline($key) : 'Selected message');
    }
}