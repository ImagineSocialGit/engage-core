<?php

namespace App\Modules\Events\Services;

use App\Modules\Events\Contracts\EventWorkspacePanelContributor;
use App\Modules\Events\Data\EventWorkspacePanel;
use App\Modules\Events\Models\Event;
use InvalidArgumentException;

final class EventWorkspacePanelRegistry
{
    /**
     * @param iterable<int, EventWorkspacePanelContributor> $contributors
     */
    public function __construct(
        private readonly iterable $contributors = [],
    ) {}

    /**
     * @return array<int, EventWorkspacePanel>
     */
    public function panels(Event $event): array
    {
        $panels = [];

        foreach ($this->contributors as $contributor) {
            if (! $contributor instanceof EventWorkspacePanelContributor) {
                throw new InvalidArgumentException(sprintf(
                    'Event workspace panel contributors must implement [%s].',
                    EventWorkspacePanelContributor::class,
                ));
            }

            foreach ($contributor->panels($event) as $panel) {
                if (! $panel instanceof EventWorkspacePanel) {
                    throw new InvalidArgumentException(sprintf(
                        'Event workspace panel contributor [%s] returned an invalid panel.',
                        $contributor::class,
                    ));
                }

                if (isset($panels[$panel->key])) {
                    throw new InvalidArgumentException(
                        "Event workspace panel [{$panel->key}] is registered more than once.",
                    );
                }

                $panels[$panel->key] = $panel;
            }
        }

        uasort(
            $panels,
            static fn (
                EventWorkspacePanel $left,
                EventWorkspacePanel $right,
            ): int => [
                $left->sortOrder,
                strtolower($left->title),
                $left->key,
            ] <=> [
                $right->sortOrder,
                strtolower($right->title),
                $right->key,
            ],
        );

        return array_values($panels);
    }
}