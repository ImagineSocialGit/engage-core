<?php

namespace App\Support\ModuleIntegrations\Documents\Contracts;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;

interface DocumentEvidenceSource
{
    public function satisfies(
        Model $subject,
        string $requirementKey,
        ?CarbonInterface $validThrough = null,
        bool $requireExpiration = false,
    ): bool;
}