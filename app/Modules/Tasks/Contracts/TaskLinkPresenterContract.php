<?php

namespace App\Modules\Tasks\Contracts;

use Illuminate\Database\Eloquent\Model;

interface TaskLinkPresenterContract
{
    public function supports(Model $linkable): bool;

    /**
     * @return array{
     *     record: Model,
     *     type: string,
     *     label: string,
     *     name: string,
     *     url: ?string,
     *     details: array<string, string>,
     *     kind?: string,
     *     message?: ?string,
     *     channel?: ?string,
     *     occurred_at?: ?string,
     *     reply_to?: ?array<string, mixed>
     * }
     */
    public function present(Model $linkable): array;
}