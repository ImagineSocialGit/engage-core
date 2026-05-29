<?php

namespace App\Http\Controllers\Public;

use App\Actions\Messaging\RevokeMessageConsentAction;
use App\Enums\MessageChannel;
use App\Enums\MessagePurpose;
use App\Http\Controllers\Controller;
use App\Models\ConsentRevocation;
use App\Models\Lead;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ConsentRevocationController extends Controller
{
    public function emailMarketingUnsubscribe(
        Request $request,
        Lead $lead,
        RevokeMessageConsentAction $revokeMessageConsentAction
    ): View {

        if (! $request->hasValidSignature()) {
            return view('messaging.unsubscribe-invalid');
        }

        $result = $this->revokeEmailConsent(
            request: $request,
            lead: $lead,
            reason: ConsentRevocation::REASON_UNSUBSCRIBE,
            purpose: MessagePurpose::Marketing,
            revokeMessageConsentAction: $revokeMessageConsentAction,
        );

        return view($result['created']
            ? 'messaging.unsubscribe-confirmed'
            : 'messaging.unsubscribe-already-confirmed'
        );
    }

    public function emailTransactionalOptOut(
        Request $request,
        Lead $lead,
        RevokeMessageConsentAction $revokeMessageConsentAction
    ): View {

        if (! $request->hasValidSignature()) {
            return view('messaging.transactional-opt-out-invalid');
        }

        $result = $this->revokeEmailConsent(
            request: $request,
            lead: $lead,
            purpose: MessagePurpose::Transactional,
            reason: ConsentRevocation::REASON_OPT_OUT,
            revokeMessageConsentAction: $revokeMessageConsentAction,
        );

        return view($result['created']
            ? 'messaging.transactional-opt-out-confirmed'
            : 'messaging.transactional-opt-out-already-confirmed'
        );
    }

    private function revokeEmailConsent(
        Request $request,
        Lead $lead,
        MessagePurpose $purpose,
        string $reason,
        RevokeMessageConsentAction $revokeMessageConsentAction
    ): array {
        return $revokeMessageConsentAction->handle($lead, [
            'channel' => MessageChannel::Email->value,
            'purpose' => $purpose->value,
            'reason' => $reason,
            'source' => 'public_email_unsubscribe',
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'meta' => [
                'signed_url' => true,
                'scope' => $purpose->value,
            ],
        ]);
    }
}