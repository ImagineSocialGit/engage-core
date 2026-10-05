<?php

namespace App\Support\ModuleIntegrations\Documents\Portal\Contracts;

use App\Modules\Portal\Models\PortalUser;

interface PortalDocumentSubjectProvider
{
    public function definitions(PortalUser $user): iterable;
}