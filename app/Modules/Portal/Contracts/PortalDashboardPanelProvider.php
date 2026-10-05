<?php

namespace App\Modules\Portal\Contracts;

use App\Modules\Portal\Models\PortalUser;

interface PortalDashboardPanelProvider
{
    public function definitions(PortalUser $user): iterable;
}