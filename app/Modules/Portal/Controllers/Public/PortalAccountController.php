<?php

namespace App\Modules\Portal\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Modules\Portal\Services\PortalAuthContext;
use App\Modules\Portal\Services\PortalNavigationRegistry;
use App\Modules\Portal\Services\PortalPresentationResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

final class PortalAccountController extends Controller
{
    public function show(
        PortalAuthContext $context,
        PortalNavigationRegistry $navigation,
        PortalPresentationResolver $presentation,
    ): View {
        $user = $context->requireUser();

        return view('portal.account.show', [
            'portalUser' => $user,
            'navigation' => $navigation->forUser($user),
            'presentation' => $presentation->resolve('Account settings'),
        ]);
    }

    public function update(Request $request, PortalAuthContext $context): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:100'],
        ]);

        $context->requireUser()->forceFill([
            'name' => trim((string) $data['name']),
            'phone' => filled($data['phone'] ?? null) ? trim((string) $data['phone']) : null,
        ])->save();

        return redirect()->route('portal.account.show');
    }
}