<?php

namespace App\Modules\InboundMessaging\Automation;

use App\Modules\InboundMessaging\Actions\RecordInboundMessageAction;
use App\Modules\InboundMessaging\Models\InboundEmailRoute;
use App\Modules\InboundMessaging\Services\Email\InboundEmailRouteResolver;
use App\Support\AutomationTriggers\Contracts\AutomationTriggerAuthoringContributor;
use App\Support\AutomationTriggers\Data\AutomationTriggerAuthoringDefinition;
use App\Support\AutomationTriggers\Data\AutomationTriggerSelection;
use Illuminate\Validation\Rule;

final class InboundEmailRouteAutomationTriggerAuthoringContributor implements AutomationTriggerAuthoringContributor
{
    public const KEY = 'inbound_messaging.inbound_email_route_received';

    public const EVENT_KEY = RecordInboundMessageAction::ROUTED_EMAIL_AUTOMATION_EVENT_KEY;

    public const ROUTE_KEY_EVENT_PATH =
        'automation_event.payload.inbound_message.inbound_email_route_key';

    public function __construct(
        private readonly InboundEmailRouteResolver $resolver,
    ) {}

    public function definitions(): iterable
    {
        yield new AutomationTriggerAuthoringDefinition(
            key: self::KEY,
            moduleKey: 'inbound_messaging',
            name: 'Email arrives at an inbound address',
            description: 'Run when email is received through one selected inbound address.',
            sortOrder: 55,
        );
    }

    public function available(string $authoringKey): bool
    {
        return $authoringKey === self::KEY
            && InboundEmailRoute::query()->active()->exists();
    }

    public function fields(string $authoringKey): array
    {
        if ($authoringKey !== self::KEY) {
            return [];
        }

        $domain = $this->resolver->configuredDomain();

        return [[
            'type' => 'select',
            'name' => 'inbound_email_route_key',
            'label' => 'Inbound address',
            'required' => true,
            'placeholder' => 'Choose an inbound address',
            'options' => InboundEmailRoute::query()
                ->active()
                ->orderBy('label')
                ->orderBy('key')
                ->get(['key', 'local_part', 'label'])
                ->map(fn (InboundEmailRoute $route): array => [
                    'value' => (string) $route->key,
                    'label' => $this->optionLabel($route, $domain),
                ])
                ->values()
                ->all(),
            'help' => 'The Route starts only for email received through the selected named address.',
        ]];
    }

    public function rules(string $authoringKey): array
    {
        if ($authoringKey !== self::KEY) {
            return [];
        }

        return [
            'inbound_email_route_key' => [
                'required',
                'string',
                Rule::exists('inbound_email_routes', 'key')->where(
                    fn ($query) => $query->where('is_active', true),
                ),
            ],
        ];
    }

    public function selection(
        string $authoringKey,
        array $input,
    ): AutomationTriggerSelection {
        return new AutomationTriggerSelection(
            triggerType: 'automation_event',
            triggerKey: self::EVENT_KEY,
            entryConditions: [[
                'source' => 'execution_meta',
                'path' => self::ROUTE_KEY_EVENT_PATH,
                'operator' => 'equals',
                'value' => trim((string) $input['inbound_email_route_key']),
            ]],
        );
    }

    private function optionLabel(
        InboundEmailRoute $route,
        ?string $domain,
    ): string {
        $address = $domain !== null
            ? $route->local_part.'@'.$domain
            : $route->local_part;

        return trim((string) $route->label).' — '.$address;
    }
}