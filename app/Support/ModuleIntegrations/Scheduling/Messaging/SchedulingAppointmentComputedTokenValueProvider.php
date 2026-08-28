<?php

namespace App\Support\ModuleIntegrations\Scheduling\Messaging;

use App\Support\TokenContracts\Contracts\ComputedTokenValueProvider;

final class SchedulingAppointmentComputedTokenValueProvider implements ComputedTokenValueProvider
{
    public function value(string $sourcePath, array $context): mixed
    {
        return data_get($context, $sourcePath);
    }
}