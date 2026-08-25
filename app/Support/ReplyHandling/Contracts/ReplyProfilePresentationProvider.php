<?php

namespace App\Support\ReplyHandling\Contracts;

use App\Support\ReplyHandling\Data\ReplyProfilePresentation;

interface ReplyProfilePresentationProvider
{
    /** @return iterable<int, ReplyProfilePresentation> */
    public function profiles(): iterable;

    public function indexUrl(): ?string;
}