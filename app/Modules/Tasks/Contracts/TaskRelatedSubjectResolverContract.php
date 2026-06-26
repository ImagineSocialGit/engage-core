<?php

namespace App\Modules\Tasks\Contracts;

use Illuminate\Database\Eloquent\Model;

interface TaskRelatedSubjectResolverContract
{
    public function supports(Model $related): bool;

    /**
     * @return array{
     *     subject: object|null,
     *     type: ?string,
     *     label: string,
     *     name: string,
     *     url: ?string,
     *     details: array<string, string>
     * }
     */
    public function resolve(Model $related): array;
}