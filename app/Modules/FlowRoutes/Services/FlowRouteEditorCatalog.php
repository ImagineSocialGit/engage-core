<?php

namespace App\Modules\FlowRoutes\Services;

use App\Modules\Campaigns\Models\Campaign;
use App\Modules\Core\Models\ContactStatus;
use App\Modules\FlowRoutes\Enums\FlowRoutePointType;
use App\Modules\FlowRoutes\Models\FlowRoute;
use App\Modules\FlowRoutes\Models\FlowRouteCapability;
use App\Modules\Tasks\Models\TaskTemplate;
use Illuminate\Support\Collection;

class FlowRouteEditorCatalog
{
    public function __construct(
        private readonly FlowRouteMessageTemplateEligibilityResolver $messageTemplateEligibility,
    ) {}

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function availableCapabilities(FlowRoute $route): Collection
    {
        $activePoints = $route->activeFlowRoutePoints;
        $hasCampaignStartPoint = $activePoints
            ->contains(fn ($point): bool => $point->type === FlowRoutePointType::EnrollCampaign->value);
        $hasActivePoints = $activePoints->isNotEmpty();
        $hasRouteEligibleMessages = $this->messageTemplateEligibility->eligiblePresets()->isNotEmpty();

        return FlowRouteCapability::query()
            ->active()
            ->whereIn('point_type', $this->supportedPointTypes())
            ->orderBy('module_key')
            ->orderBy('name')
            ->get()
            ->filter(function (FlowRouteCapability $capability) use ($hasActivePoints, $hasCampaignStartPoint, $hasRouteEligibleMessages): bool {
                if (data_get($capability->meta, 'runtime.handler_available_at_sync', true) === false) {
                    return false;
                }

                if (
                    $capability->point_type === FlowRoutePointType::Wait->value
                    && ! $hasActivePoints
                ) {
                    return false;
                }

                if (
                    $capability->point_type === FlowRoutePointType::CancelCampaign->value
                    && ! $hasCampaignStartPoint
                ) {
                    return false;
                }

                if (
                    $capability->point_type === FlowRoutePointType::SendMessage->value
                    && ! $hasRouteEligibleMessages
                ) {
                    return false;
                }

                return true;
            })
            ->map(fn (FlowRouteCapability $capability): array => [
                'id' => (int) $capability->getKey(),
                'key' => (string) $capability->key,
                'module_key' => (string) $capability->module_key,
                'point_type' => (string) $capability->point_type,
                'name' => $this->friendlyName($capability),
                'description' => $this->description($capability),
                'tip' => $this->tip((string) $capability->point_type),
                'use_cases' => $this->useCases((string) $capability->point_type),
            ])
            ->values();
    }

    /**
     * @return array<string, mixed>
     */
    public function editorOptions(): array
    {
        return [
            'contact_statuses' => ContactStatus::query()
                ->active()
                ->ordered()
                ->get(['key', 'name']),

            'task_templates' => module_enabled('tasks')
                ? TaskTemplate::query()
                    ->active()
                    ->orderBy('name')
                    ->get(['key', 'name', 'title', 'description'])
                : collect(),

            'message_templates' => $this->messageTemplateEligibility->eligiblePresets(),

            'campaigns' => module_enabled('campaigns')
                ? Campaign::query()
                    ->active()
                    ->orderBy('name')
                    ->get(['key', 'name', 'description'])
                : collect(),
        ];
    }

    /**
     * @return array<int, string>
     */
    private function supportedPointTypes(): array
    {
        return [
            FlowRoutePointType::Wait->value,
            FlowRoutePointType::ChangeStatus->value,
            FlowRoutePointType::CreateTask->value,
            FlowRoutePointType::SendMessage->value,
            FlowRoutePointType::EnrollCampaign->value,
            FlowRoutePointType::CancelCampaign->value,
        ];
    }

    private function friendlyName(FlowRouteCapability $capability): string
    {
        return match ($capability->point_type) {
            FlowRoutePointType::Wait->value => 'Wait',
            FlowRoutePointType::ChangeStatus->value => 'Change contact status',
            FlowRoutePointType::CreateTask->value => 'Create task',
            FlowRoutePointType::SendMessage->value => 'Send message',
            FlowRoutePointType::EnrollCampaign->value => 'Start Campaign',
            FlowRoutePointType::CancelCampaign->value => 'Stop Campaign',
            default => (string) $capability->name,
        };
    }

    private function description(FlowRouteCapability $capability): string
    {
        return match ($capability->point_type) {
            FlowRoutePointType::Wait->value => 'Pause this Route for a period of time or until a specific date and time. Wait can never be the final Point.',
            FlowRoutePointType::ChangeStatus->value => 'Move the contact to another workflow status. Change Status always ends the Route.',
            FlowRoutePointType::CreateTask->value => 'Create work for a team member, either from a Task Template or from an inline task definition.',
            FlowRoutePointType::SendMessage->value => 'Send a reusable message through Messaging, subject to permissions and delivery rules.',
            FlowRoutePointType::EnrollCampaign->value => 'Start a Campaign for this contact. A Campaign is a series of scheduled messages sent automatically in steps.',
            FlowRoutePointType::CancelCampaign->value => 'Stop a Campaign that this Route started and optionally skip pending messages.',
            default => (string) ($capability->description ?? ''),
        };
    }

    private function tip(string $pointType): string
    {
        return match ($pointType) {
            FlowRoutePointType::Wait->value => 'Use a Wait when the next step should happen later, not immediately. When added, it is placed before the current final Point so something always happens afterward.',
            FlowRoutePointType::ChangeStatus->value => 'Use status changes for meaningful workflow movement. Change Status stays last because it hands the contact off to what comes next.',
            FlowRoutePointType::CreateTask->value => 'Prefer a Task Template when the same work should be created consistently across Routes.',
            FlowRoutePointType::SendMessage->value => 'Use reusable message templates so copy and delivery behavior stay centrally managed.',
            FlowRoutePointType::EnrollCampaign->value => 'Use a Campaign when several scheduled messages should happen in sequence.',
            FlowRoutePointType::CancelCampaign->value => 'Use this when a later outcome means the Campaign started by this Route should stop.',
            default => '',
        };
    }

    /**
     * @return array<int, string>
     */
    private function useCases(string $pointType): array
    {
        return match ($pointType) {
            FlowRoutePointType::Wait->value => [
                'Wait 7 days before checking whether follow-up is still needed.',
                'Pause until a specific scheduled date.',
            ],
            FlowRoutePointType::ChangeStatus->value => [
                'Move a webinar attendee to Attended Webinar.',
                'Move a qualified lead into a new workflow stage.',
            ],
            FlowRoutePointType::CreateTask->value => [
                'Create an initial contact task.',
                'Create a follow-up task after a waiting period.',
            ],
            FlowRoutePointType::SendMessage->value => [
                'Send a reusable confirmation or follow-up message.',
                'Send a direct message when a Route reaches a specific step.',
            ],
            FlowRoutePointType::EnrollCampaign->value => [
                'Start a webinar nurture Campaign.',
                'Begin a reusable long-term follow-up Campaign.',
            ],
            FlowRoutePointType::CancelCampaign->value => [
                'Stop a nurture Campaign after conversion.',
                'Stop pending follow-up when the Route reaches a final outcome.',
            ],
            default => [],
        };
    }
}
