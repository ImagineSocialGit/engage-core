<?php

namespace App\Modules\Campaigns\Automation;

use App\Modules\Core\Models\Contact;
use App\Support\ModuleFacts\Enums\ModuleFactCapability;
use App\Support\ModuleFacts\Enums\ModuleFactType;
use App\Support\ModuleFacts\ModuleFactRegistry;
use App\Support\AutomationTriggers\Contracts\AutomationTriggerAuthoringContributor;
use App\Support\AutomationTriggers\Data\AutomationTriggerAuthoringDefinition;
use App\Support\AutomationTriggers\Data\AutomationTriggerSelection;
use Illuminate\Validation\Rule;

final class CampaignAnnualTouchAutomationTriggerAuthoringContributor implements AutomationTriggerAuthoringContributor
{
    public const KEY = 'campaigns.annual_touch_date';

    public function __construct(
        private readonly ModuleFactRegistry $moduleFacts,
    ) {}

    public function definitions(): iterable
    {
        yield new AutomationTriggerAuthoringDefinition(
            key: self::KEY,
            moduleKey: 'campaigns',
            name: 'Important contact date arrives',
            description: 'Run each year when a selected date available to Annual Touches arrives.',
            sortOrder: 50,
        );
    }

    public function available(string $authoringKey): bool
    {
        return $authoringKey === self::KEY
            && $this->annualDateFacts() !== [];
    }

    public function fields(string $authoringKey): array
    {
        if ($authoringKey !== self::KEY) {
            return [];
        }

        return [[
            'type' => 'select',
            'name' => 'annual_date_source_key',
            'label' => 'Important date',
            'required' => true,
            'placeholder' => 'Choose an annual date',
            'options' => collect($this->annualDateFacts())
                ->map(fn ($fact): array => [
                    'value' => $fact->key,
                    'label' => $fact->label,
                ])
                ->values()
                ->all(),
            'help' => 'The route runs once per contact each year on this date.',
        ]];
    }

    public function rules(string $authoringKey): array
    {
        return $authoringKey === self::KEY
            ? [
                'annual_date_source_key' => [
                    'required',
                    'string',
                    Rule::in(array_keys($this->moduleFacts->acceptedKeys(
                        Contact::class,
                        ModuleFactType::Date,
                        ModuleFactCapability::Annualizable,
                    ))),
                ],
            ]
            : [];
    }

    public function selection(string $authoringKey, array $input): AutomationTriggerSelection
    {
        if ($authoringKey !== self::KEY) {
            throw new \InvalidArgumentException(
                "Unsupported Campaign annual-touch trigger [{$authoringKey}].",
            );
        }

        return new AutomationTriggerSelection(
            triggerType: 'automation_event',
            triggerKey: 'campaign_touch.annual_date_due',
            entryConditions: [[
                'source' => 'execution_meta',
                'path' => 'automation_event.payload.annual_date.source_key',
                'operator' => 'equals',
                'value' => $this->moduleFacts->canonicalKey(
                    trim((string) $input['annual_date_source_key']),
                ),
            ]],
        );
    }

    private function annualDateFacts(): array
    {
        return $this->moduleFacts->matching(
            Contact::class,
            ModuleFactType::Date,
            ModuleFactCapability::Annualizable,
        );
    }
}