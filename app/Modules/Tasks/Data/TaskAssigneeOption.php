<?php

namespace App\Modules\Tasks\Data;

use Illuminate\Database\Eloquent\Model;

class TaskAssigneeOption
{
    public function __construct(
        public readonly Model $assignee,
        public readonly string $label,
        public readonly ?string $description = null,
        public readonly bool $isCurrent = false,
    ) {}

    public function key(): string
    {
        return implode(':', [
            $this->assignee->getMorphClass(),
            $this->assignee->getKey(),
        ]);
    }

    /**
     * @return array{
     *     key: string,
     *     type: string,
     *     id: int|string,
     *     label: string,
     *     description: ?string,
     *     is_current: bool
     * }
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key(),
            'type' => $this->assignee->getMorphClass(),
            'id' => $this->assignee->getKey(),
            'label' => $this->label,
            'description' => $this->description,
            'is_current' => $this->isCurrent,
        ];
    }
}
