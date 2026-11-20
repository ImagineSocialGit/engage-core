<?php

namespace App\Modules\Commerce\Controllers\CRM;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Commerce\Services\CommercePrivacyRequestReadService;
use App\Modules\Commerce\Services\CommerceCustomerPrivacyService;
use App\Support\Webhooks\Models\WebhookInboxReceipt;
use App\Modules\Core\Access\Services\UserAccessService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\Request;

/** Authorized Shopify privacy review and scoped customer redaction. */
final class CommercePrivacyRequestController extends Controller
{
    public function __invoke(
        Request $request,
        UserAccessService $access,
        CommercePrivacyRequestReadService $privacy,
    ): View {
        $user = $request->user();

        abort_unless($user instanceof User && $access->allows($user, 'settings.manage'), 403);

        return view('crm.commerce.privacy.index', [
            'title' => 'Commerce Privacy Requests',
            'heading' => 'Privacy Requests',
            'requests' => $privacy->pending(),
        ]);
    }

    public function redactCustomer(
        Request $request,
        WebhookInboxReceipt $receipt,
        UserAccessService $access,
        CommerceCustomerPrivacyService $privacy,
    ): RedirectResponse {
        $user = $request->user();
        abort_unless($user instanceof User && $access->allows($user, 'settings.manage'), 403);
        $request->validate(['acknowledge_partial' => ['required', 'accepted']]);

        DB::transaction(function () use ($receipt, $user, $privacy): void {
            $locked = WebhookInboxReceipt::query()
                ->whereKey($receipt->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            abort_unless(
                $locked->provider === 'shopify'
                && $locked->client_key === config('client.key')
                && $locked->event_type === 'privacy.customers/redact',
                404,
            );

            $customerId = data_get($locked->payload, 'customer_id');
            abort_unless(is_string($customerId) && preg_match('/^[1-9][0-9]*$/D', $customerId) === 1, 422);

            $outcome = $privacy->redactProviderCustomer('shopify', 'gid://shopify/Customer/'.$customerId);
            $locked->forceFill([
                'outcome' => [
                    ...$outcome,
                    'reviewed_by_user_id' => (int) $user->getKey(),
                    'commerce_scoped_reviewed_at' => now()->toIso8601String(),
                    'shared_crm_contact_review_required' => true,
                    'request_fulfilled' => false,
                ],
            ])->save();
        });

        return redirect()
            ->route('crm.commerce.privacy.index')
            ->with('success', 'Commerce customer data reviewed. The overall privacy request remains pending merchant review.');
    }

    public function complete(
        Request $request,
        WebhookInboxReceipt $receipt,
        UserAccessService $access,
        CommercePrivacyRequestReadService $privacy,
    ): RedirectResponse {
        $user = $request->user();
        abort_unless($user instanceof User && $access->allows($user, 'settings.manage'), 403);

        $validated = $request->validate([
            'case_reference' => ['required', 'string', 'max:120'],
            'disposition' => ['required', 'in:fulfilled,legal_retention,not_applicable'],
            'merchant_reviewed' => ['required', 'accepted'],
            'related_data_reviewed' => ['required', 'accepted'],
            'retention_reviewed' => ['required', 'accepted'],
        ]);

        $privacy->completeReviewed(
            receiptId: (int) $receipt->getKey(),
            actorUserId: (int) $user->getKey(),
            disposition: $validated['disposition'],
            caseReference: $validated['case_reference'],
        );

        return redirect()
            ->route('crm.commerce.privacy.index')
            ->with('success', 'Merchant privacy review recorded.');
    }
}
