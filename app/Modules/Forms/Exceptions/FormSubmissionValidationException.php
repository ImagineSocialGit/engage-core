<?php

namespace App\Modules\Forms\Exceptions;

use DomainException;

final class FormSubmissionValidationException extends DomainException
{
    /**
     * @param  array<string, array<int, string>>  $errors
     */
    public function __construct(
        private readonly array $errors,
    ) {
        parent::__construct('Form submission validation failed.');
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function errors(): array
    {
        return $this->errors;
    }
}