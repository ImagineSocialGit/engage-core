<?php

namespace App\Modules\InboundMessaging\Controllers\CRM;

use App\Http\Controllers\Controller;
use App\Modules\InboundMessaging\Actions\Sms\ReconcileInboundSmsHistoryAction;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

final class InboundSmsMaintenanceController extends Controller
{
    public function index(
        ReconcileInboundSmsHistoryAction $reconcile,
    ): View {
        return view('crm.inbound-messaging.sms-maintenance', [
            'preview' => $reconcile->inspect(),
        ]);
    }

    public function reconcile(
        ReconcileInboundSmsHistoryAction $reconcile,
    ): RedirectResponse {
        $result = $reconcile->handle();

        return redirect()
            ->route('crm.inbound-messaging.sms-maintenance.index')
            ->with('status', sprintf(
                'Inbound message repair finished. %d messages linked, %d replies correlated, %d STOP messages reconciled, and %d messages remain unresolved.',
                $result['linked'],
                $result['replies_correlated'],
                $result['stop_messages_processed'],
                $result['unresolved'] + $result['manual_link_conflicts'],
            ));
    }
}