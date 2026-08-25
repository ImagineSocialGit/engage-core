<?php

namespace App\Support\ReplyHandling\Contracts;

use App\Support\ReplyHandling\Data\ReplyProfileDependency;

interface ReplyProfileDependencyContributor
{
    /** @return iterable<int, ReplyProfileDependency> */
    public function dependencies(): iterable;
}