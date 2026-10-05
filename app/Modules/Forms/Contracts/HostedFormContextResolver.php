<?php

namespace App\Modules\Forms\Contracts;

use App\Modules\Forms\Data\FormSubmissionContext;
use App\Modules\Forms\Data\HostedFormContextReference;

interface HostedFormContextResolver
{
    public const TAG = 'forms.hosted_form_context_resolvers';

    public function key(): string;

    public function resolve(
        HostedFormContextReference $reference,
    ): ?FormSubmissionContext;
}