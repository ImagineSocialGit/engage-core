<?php

namespace App\Modules\InboundMessaging\Automation;

use App\Modules\InboundMessaging\Models\InboundReplyProfile;
use App\Support\AutomationTriggers\Contracts\AutomationTriggerAuthoringContributor;
use App\Support\AutomationTriggers\Data\AutomationTriggerAuthoringDefinition;
use App\Support\AutomationTriggers\Data\AutomationTriggerSelection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class InboundReplyAutomationTriggerAuthoringContributor implements AutomationTriggerAuthoringContributor
{
    public const KEY = 'inbound_messaging.reply_outcome';

    public function definitions(): iterable
    {
        yield new AutomationTriggerAuthoringDefinition(
            key: self::KEY,
            moduleKey: 'inbound_messaging',
            name: 'Someone replies to a message',
            description: 'Run when an inbound reply matches one selected reply outcome.',
            sortOrder: 50,
        );
    }

    public function available(string $authoringKey): bool
    {
        return $authoringKey === self::KEY
            && InboundReplyProfile::query()
                ->active()
                ->whereHas('activeIntents')
                ->exists();
    }

    public function fields(string $authoringKey): array
    {
        return [[
            'type' => 'select',
            'name' => 'reply_outcome',
            'label' => 'Reply outcome',
            'required' => true,
            'placeholder' => 'Choose a reply outcome',
            'options' => $this->outcomes(),
            'help' => 'The Route runs only when both the reply vocabulary and recognized outcome match.',
        ]];
    }

    public function rules(string $authoringKey): array
    {
        return [
            'reply_outcome' => ['required', 'string', Rule::in(array_column($this->outcomes(), 'value'))],
        ];
    }

    public function selection(string $authoringKey, array $input): AutomationTriggerSelection
    {
        $parts = explode('|', (string) ($input['reply_outcome'] ?? ''), 2);

        if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
            throw ValidationException::withMessages([
                'reply_outcome' => 'Choose an available reply outcome.',
            ]);
        }

        return new AutomationTriggerSelection(
            triggerType: 'automation_event',
            triggerKey: 'inbound_message.normal_reply',
            entryConditions: [
                $this->condition(
                    'automation_event.payload.inbound_message.reply_profile_key',
                    $parts[0],
                ),
                $this->condition(
                    'automation_event.payload.inbound_message.reply_intent_key',
                    $parts[1],
                ),
            ],
        );
    }

    /** @return array<int, array{value: string, label: string}> */
    private function outcomes(): array
    {
        return InboundReplyProfile::query()
            ->active()
            ->with('activeIntents')
            ->orderBy('label')
            ->orderBy('id')
            ->get()
            ->flatMap(fn (InboundReplyProfile $profile) => $profile->activeIntents->map(
                fn ($intent): array => [
                    'value' => (string) $profile->key.'|'.(string) $intent->key,
                    'label' => (string) $profile->label.' — '.(string) $intent->label,
                ],
            ))
            ->values()
            ->all();
    }

    /** @return array<string, mixed> */
    private function condition(string $path, string $value): array
    {
        return [
            'source' => 'execution_meta',
            'path' => $path,
            'operator' => 'equals',
            'value' => $value,
        ];
    }
}