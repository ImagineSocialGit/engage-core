<?php

namespace App\Modules\Messaging\Controllers\Public;

use App\Modules\Messaging\Actions\RevokeMessageConsentAction;
use App\Modules\Messaging\Enums\MessageChannel;
use App\Modules\Messaging\Enums\MessagePurpose;
use App\Http\Controllers\Controller;
use App\Modules\Messaging\Models\ConsentRevocation;
use App\Modules\Core\Models\Contact;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ConsentRevocationController extends Controller
{
    public function emailMarketingUnsubscribe(
        Request $request,
        Contact $contact,
        RevokeMessageConsentAction $revokeMessageConsentAction
    ): View {
        if (! $request->hasValidSignature()) {
            return view('messaging.unsubscribe-invalid');
        }

        $result = $this->revokeEmailConsent(
            request: $request,
            contact: $contact,
            purpose: MessagePurpose::Marketing,
            reason: ConsentRevocation::REASON_UNSUBSCRIBE,
            scope: null,
            revokeMessageConsentAction: $revokeMessageConsentAction,
        );

        return view($result['created']
            ? 'messaging.unsubscribe-confirmed'
            : 'messaging.unsubscribe-already-confirmed'
        );
    }

    public function emailTransactionalOptOut(
        Request $request,
        Contact $contact,
        RevokeMessageConsentAction $revokeMessageConsentAction
    ): View {
        if (! $request->hasValidSignature()) {
            return view('messaging.transactional-opt-out-invalid');
        }

        $scope = trim((string) $request->query('scope', ''));

        if ($scope === '') {
            return view('messaging.transactional-opt-out-invalid');
        }

        $result = $this->revokeEmailConsent(
            request: $request,
            contact: $contact,
            purpose: MessagePurpose::Transactional,
            reason: ConsentRevocation::REASON_OPT_OUT,
            scope: $scope,
            revokeMessageConsentAction: $revokeMessageConsentAction,
        );

        return view($result['created']
            ? 'messaging.transactional-opt-out-confirmed'
            : 'messaging.transactional-opt-out-already-confirmed'
        );
    }

    private function revokeEmailConsent(
        Request $request,
        Contact $contact,
        MessagePurpose $purpose,
        string $reason,
        ?string $scope,
        RevokeMessageConsentAction $revokeMessageConsentAction
    ): array {
        return $revokeMessageConsentAction->handle($contact, [
            'channel' => MessageChannel::Email->value,
            'purpose' => $purpose->value,
            'scope' => $scope,
            'reason' => $reason,
            'source' => 'public_email_unsubscribe',
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'meta' => [
                'signed_url' => true,
            ],
        ]);
    }
}