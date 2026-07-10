<?php

namespace App\Modules\FlowRoutes\Services\ContactShow;

use App\Modules\Core\Contracts\Contacts\ContactShowDataProvider;
use App\Modules\Core\Models\Contact;
use App\Modules\FlowRoutes\Models\ContactFlowRouteProgress;
use Illuminate\Support\Str;

class ContactRoutesVisibilityDataProvider implements ContactShowDataProvider
{
    /**
     * @return array<string, mixed>
     */
    public function dataFor(Contact $contact): array
    {
        $progresses = ContactFlowRouteProgress::query()
            ->with([
                'flowRoute',
                'contactStatus',
                'currentFlowRoutePoint',
            ])
            ->forContact((int) $contact->id)
            ->orderByRaw("FIELD(status, 'active', 'waiting', 'failed', 'completed', 'cancelled', 'superseded')")
            ->latest('started_at')
            ->limit(6)
            ->get();

        return [
            'contactVisibilitySections' => [
                'routes' => [
                    'title' => 'Automatic follow-ups',
                    'module' => 'flow_routes',
                    'description' => 'Active and recent automatic follow-up progress.',
                    'empty' => 'No automatic follow-up progress has been recorded.',
                    'items' => $progresses->map(fn (ContactFlowRouteProgress $progress): array => [
                        'title' => $this->routeName($progress),
                        'subtitle' => $this->currentPointLabel($progress),
                        'status' => Str::of($progress->status)->replace('_', ' ')->title()->toString(),
                        'meta' => [
                            'Contact Status' => $progress->contactStatus?->name,
                            'Started' => $this->date($progress->started_at),
                            'Waiting For' => $progress->waitingExpectedEvent(),
                            'Resume At' => $this->date($progress->waitingResumeAt()),
                            'Failure' => $progress->failure_reason,
                            'Cancellation' => $progress->cancellation_reason,
                        ],
                    ])->all(),
                ],
            ],
        ];
    }

    private function routeName(ContactFlowRouteProgress $progress): string
    {
        $route = $progress->flowRoute;

        foreach (['name', 'title', 'key'] as $attribute) {
            $value = $route->{$attribute} ?? null;

            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return 'Route #'.$progress->flow_route_id;
    }

    private function currentPointLabel(ContactFlowRouteProgress $progress): ?string
    {
        $point = $progress->currentFlowRoutePoint;

        if (! $point) {
            return null;
        }

        $label = $point->name
            ?? $point->title
            ?? $point->key
            ?? $point->type
            ?? null;

        return is_string($label) && trim($label) !== ''
            ? 'Current point: '.trim($label)
            : 'Current point #'.$point->getKey();
    }

    private function date(mixed $date): ?string
    {
        return $date?->timezone(config('client.timezone', config('app.timezone', 'UTC')))->format('M j, Y g:i A');
    }
}
