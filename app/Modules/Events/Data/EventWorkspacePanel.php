<?php

namespace App\Modules\Events\Data;

use InvalidArgumentException;

final readonly class EventWorkspacePanel
{
    public string $key;

    public string $title;

    public ?string $description;

    /** @var array<int, EventWorkspaceAction> */
    public array $actions;

    /**
     * @param array<int, EventWorkspaceAction> $actions
     */
    public function __construct(
        string $key,
        string $title,
        ?string $description = null,
        array $actions = [],
        public int $sortOrder = 100,
    ) {
        $key = strtolower(trim($key));
        $title = trim($title);
        $description = $description !== null ? trim($description) : null;

        if (preg_match('/^[a-z][a-z0-9_.-]{0,79}$/D', $key) !== 1) {
            throw new InvalidArgumentException(
                "Event workspace panel key [{$key}] is invalid.",
            );
        }

        if ($title === '') {
            throw new InvalidArgumentException(
                'Event workspace panel title must be non-empty.',
            );
        }

        if ($description === '') {
            $description = null;
        }

        if ($sortOrder < 0) {
            throw new InvalidArgumentException(
                'Event workspace panel sort order cannot be negative.',
            );
        }

        foreach ($actions as $action) {
            if (! $action instanceof EventWorkspaceAction) {
                throw new InvalidArgumentException(
                    'Event workspace panel actions must be EventWorkspaceAction instances.',
                );
            }
        }

        $this->key = $key;
        $this->title = $title;
        $this->description = $description;
        $this->actions = array_values($actions);
    }
}