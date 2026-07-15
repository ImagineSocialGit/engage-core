<?php

namespace App\Modules\Messaging\Automation;

use App\Modules\Messaging\Models\MessageTemplatePreset;
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
    ) {}

    public function definitions(): iterable
    {
        yield new AutomationPointAuthoringDefinition(
            pointType: 'send_message',
            moduleKey: 'messaging',
            name: 'Send message',
            description: 'Send a reusable message through Messaging, subject to permissions and delivery rules.',
            tip: 'Use reusable message templates so copy and delivery behavior stay centrally managed.',
            useCases: [
                'Send a reusable confirmation or follow-up message.',
                'Send a direct message when a Route reaches a specific step.',
            ],
            typeLabel: 'Message',
            genericLabels: ['send message'],
        );
    }

    public function available(string $pointType, AutomationPointAuthoringContext $context): bool
    {
        return $this->eligibility->eligiblePresets()->isNotEmpty();
    }

    public function fields(string $pointType, array $definition, AutomationPointAuthoringContext $context): array
    {
        $selectedKey = (string) ($definition['message_template_preset_key'] ?? '');
        $selectedId = $selectedKey !== ''
            ? MessageTemplatePreset::query()->where('key', $selectedKey)->value('id')
            : null;

        return [[
            'type' => 'select',
            'name' => 'message_template_preset_id',
            'label' => 'Message template',
            'required' => true,
            'value' => $selectedId,
            'placeholder' => 'Choose a message template',
            'help' => 'Only templates explicitly approved for direct Route use appear here.',
            'options' => $this->eligibility->eligiblePresets()
                ->map(fn (MessageTemplatePreset $preset): array => [
                    'value' => (int) $preset->getKey(),
                    'label' => (string) $preset->name,
                    'description' => Str::headline((string) $preset->channel).' · '.Str::headline((string) $preset->purpose),
                ])->all(),
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
        return $this->templateLabel((string) ($definition['message_template_preset_key'] ?? ''));
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
