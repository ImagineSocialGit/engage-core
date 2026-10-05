<?php

namespace App\Support\ModuleIntegrations\Documents\Portal;

use App\Modules\Portal\Contracts\PortalNavigationProvider;
use App\Modules\Portal\Data\PortalNavigationItem;
use App\Modules\Portal\Models\PortalUser;

final class DocumentsPortalNavigationProvider implements PortalNavigationProvider
{
    public function __construct(
        private readonly PortalDocumentSubjectRegistry $subjects,
    ) {}

    public function definitions(PortalUser $user): iterable
    {
        if ($this->subjects->forUser($user) === []) {
            return;
        }

        yield new PortalNavigationItem(
            key: 'documents.documents',
            label: 'Documents',
            routeName: 'portal.documents.index',
            sort: 200,
            requiresVerifiedEmail: true,
        );
    }
}