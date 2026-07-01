<?php

namespace App\Modules\Messaging\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Modules\Messaging\Requests\StoreContactPermissionInvitationConsentRequest;
use App\Modules\Messaging\Services\ContactPermissionInvitationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class ContactPermissionInvitationController extends Controller
{
    public function __construct(
        private readonly ContactPermissionInvitationService $permissionInvitationService,
    ) {}

    public function show(string $token): View
    {
        $invitation = $this->permissionInvitationService->findPublicInvitation($token);

        abort_unless($invitation, 404);

        if ($invitation->hasBeenAccepted()) {
            return view('messaging.permission-invitations.accepted', [
                'title' => config('messaging.permission_invitations.content.accepted_title', 'Preferences confirmed'),
                'invitation' => $invitation,
                'content' => config('messaging.permission_invitations.content', []),
                'style' => config('messaging.permission_invitations.style', []),
            ]);
        }

        return view('messaging.permission-invitations.show', [
            'title' => config('messaging.permission_invitations.content.title', 'Confirm your preferences'),
            'invitation' => $invitation,
            'contact' => $invitation->contact,
            'content' => config('messaging.permission_invitations.content', []),
            'style' => config('messaging.permission_invitations.style', []),
        ]);
    }

    public function store(
        StoreContactPermissionInvitationConsentRequest $request,
        string $token,
    ): RedirectResponse {
        $invitation = $this->permissionInvitationService->findPublicInvitation($token);

        abort_unless($invitation, 404);

        if ($invitation->hasBeenAccepted()) {
            return redirect()->route('messaging.permission-invitations.show', [
                'token' => $token,
            ]);
        }

        $contact = $invitation->contact;

        if ($contact && in_array('sms', $request->acceptedChannels(), true) && $request->phone()) {
            $contact->forceFill([
                'phone' => $request->phone(),
            ])->save();
        }

        $this->permissionInvitationService->accept(
            invitation: $invitation,
            channels: $request->acceptedChannels(),
            request: $request,
        );

        return redirect()->route('messaging.permission-invitations.show', [
            'token' => $token,
        ])->with('success', 'Your communication preferences have been confirmed.');
    }
}