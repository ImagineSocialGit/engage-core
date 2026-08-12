<?php

namespace App\Modules\Forms\Exceptions;

use DomainException;

final class FormSubmissionReplayConflictException extends DomainException
{
    public static function forIdentity(string $provider, string $externalId): self
    {
        return new self(
            "Form submission replay identity [{$provider}:{$externalId}] is already associated with a different logical request.",
        );
    }
}