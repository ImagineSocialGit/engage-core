<?php

namespace App\Modules\Webinars\Controllers\CRM;

use App\Http\Controllers\Controller;
use App\Modules\Webinars\Actions\ResolveWebinarRegistrationReconciliationAction;
use App\Modules\Webinars\Actions\RetryWebinarRegistrationFinalizationAction;
use App\Modules\Webinars\Data\WebinarRegistrationFinalizationResult;
use App\Modules\Webinars\Models\WebinarRegistration;
use App\Modules\Webinars\Requests\ResolveWebinarRegistrationReconciliationRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class WebinarRegistrationFinalizationController extends Controller
{
    public function retry(
        Request $request,
        WebinarRegistration $registration,
        RetryWebinarRegistrationFinalizationAction $retryFinalization,
    ): RedirectResponse {
        $result = $retryFinalization->handle(
            registration: $registration,
            operatorId: $request->user()?->getKey(),
        );

        if ($result->requiresReconciliation()) {
            return back()->with(
                'error',
                'Verify the registration with the webinar provider before choosing a reconciliation outcome.',
            );
        }

        if ($result->status === WebinarRegistrationFinalizationResult::STATUS_ALREADY_COMPLETED) {
            return back()->with(
                'success',
                'This webinar registration is already finalized.',
            );
        }

        if (
            $result->status === WebinarRegistrationFinalizationResult::STATUS_IN_PROGRESS
            && $result->reason === 'finalization_not_failed'
        ) {
            return back()->with(
                'error',
                'This webinar registration does not have a terminal finalization failure to retry.',
            );
        }

        if ($result->status === WebinarRegistrationFinalizationResult::STATUS_FAILED
            || ($result->status === WebinarRegistrationFinalizationResult::STATUS_PENDING
                && $result->reason === 'queue_dispatch_failed')
        ) {
            return back()->with(
                'error',
                'The registration finalization retry could not be queued.',
            );
        }

        return back()->with(
            'success',
            'The registration finalization retry has been queued.',
        );
    }

    public function reconcile(
        ResolveWebinarRegistrationReconciliationRequest $request,
        WebinarRegistration $registration,
        ResolveWebinarRegistrationReconciliationAction $resolveReconciliation,
    ): RedirectResponse {
        $result = $resolveReconciliation->handle(
            registration: $registration,
            data: $request->validated(),
            operatorId: $request->user()?->getKey(),
        );

        if ($result->status === WebinarRegistrationFinalizationResult::STATUS_ALREADY_COMPLETED) {
            return back()->with(
                'success',
                'This webinar registration is already finalized.',
            );
        }

        if (
            $result->status === WebinarRegistrationFinalizationResult::STATUS_IN_PROGRESS
            && $result->reason === 'provider_reconciliation_not_required'
        ) {
            return back()->with(
                'error',
                'This webinar registration no longer requires provider reconciliation.',
            );
        }

        if ($result->status === WebinarRegistrationFinalizationResult::STATUS_RECONCILIATION_REQUIRED) {
            return back()->with(
                'error',
                'The provider reconciliation decision could not be applied.',
            );
        }

        if ($result->status === WebinarRegistrationFinalizationResult::STATUS_FAILED
            || ($result->status === WebinarRegistrationFinalizationResult::STATUS_PENDING
                && $result->reason === 'queue_dispatch_failed')
        ) {
            return back()->with(
                'error',
                'The reconciled registration could not be queued for finalization.',
            );
        }

        return back()->with(
            'success',
            $request->validated('decision') === ResolveWebinarRegistrationReconciliationAction::DECISION_PROVIDER_EXISTS
                ? 'The provider registration was confirmed and finalization has been queued.'
                : 'The provider registration was confirmed absent and one safe resubmission has been queued.',
        );
    }
}