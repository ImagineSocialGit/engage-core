<?php

namespace App\Modules\Portal\Contracts;

use App\Modules\Portal\Models\PortalUser;

interface PortalNavigationProvider
{
    public function definitions(PortalUser $user): iterable;
}