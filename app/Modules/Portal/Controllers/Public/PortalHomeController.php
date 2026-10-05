<?php

namespace App\Modules\Portal\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Modules\Portal\Contracts\PortalAccountNotificationTransport;
use App\Modules\Portal\Services\PortalAuthContext;
use App\Modules\Portal\Services\PortalDashboardPanelRegistry;
use App\Modules\Portal\Services\PortalNavigationRegistry;
use App\Modules\Portal\Services\PortalPresentationResolver;
use Illuminate\View\View;

final class PortalHomeController extends Controller
{
    public function __invoke(
        PortalAuthContext $context,
        PortalDashboardPanelRegistry $panels,
        PortalNavigationRegistry $navigation,
        PortalAccountNotificationTransport $notifications,
        PortalPresentationResolver $presentation,
    ): View {
        $user = $context->requireUser();

        return view('portal.home', [
            'portalUser' => $user,
            'panels' => $panels->forUser($user),
            'navigation' => $navigation->forUser($user),
            'emailVerificationAvailable' => $notifications->available(),
            'presentation' => $presentation->resolve('Account'),
        ]);
    }
}