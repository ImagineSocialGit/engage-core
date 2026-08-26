<?php

namespace App\Modules\InboundMessaging\Controllers\CRM;

use App\Http\Controllers\Controller;
use App\Modules\InboundMessaging\Actions\EmailRoutes\SaveInboundEmailRouteAction;
use App\Modules\InboundMessaging\Models\InboundEmailRoute;
use App\Modules\InboundMessaging\Requests\SaveInboundEmailRouteRequest;
use App\Modules\InboundMessaging\Services\Email\InboundEmailRouteWorkspace;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class InboundEmailRouteController extends Controller
{
    public function index(InboundEmailRouteWorkspace $workspace): View
    {
        return view('crm.inbound-messaging.email-routes.index', [
            'workspace' => $workspace->build(),
        ]);
    }

    public function store(
        SaveInboundEmailRouteRequest $request,
        SaveInboundEmailRouteAction $save,
    ): RedirectResponse {
        $save->handle($request->definition());

        return $this->redirectTo()
            ->with('status', 'Inbound address created.');
    }

    public function update(
        SaveInboundEmailRouteRequest $request,
        InboundEmailRoute $inboundEmailRoute,
        SaveInboundEmailRouteAction $save,
    ): RedirectResponse {
        $save->handle(
            data: $request->definition(),
            route: $inboundEmailRoute,
        );

        return $this->redirectTo()
            ->with('status', 'Inbound address updated.');
    }

    public function state(
        Request $request,
        InboundEmailRoute $inboundEmailRoute,
    ): RedirectResponse {
        $validated = $request->validate([
            'is_active' => ['required', 'boolean'],
        ]);

        $inboundEmailRoute->forceFill([
            'is_active' => (bool) $validated['is_active'],
        ])->save();

        return $this->redirectTo()
            ->with(
                'status',
                $inboundEmailRoute->is_active
                    ? 'Inbound address enabled.'
                    : 'Inbound address disabled.',
            );
    }

    private function redirectTo(): RedirectResponse
    {
        return redirect()->route(
            'crm.inbound-messaging.email-routes.index',
        );
    }
}