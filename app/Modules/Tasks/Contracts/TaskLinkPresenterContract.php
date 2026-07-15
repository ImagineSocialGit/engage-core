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
     *     details: array<string, string>
     * }
     */
    public function present(Model $linkable): array;
}
