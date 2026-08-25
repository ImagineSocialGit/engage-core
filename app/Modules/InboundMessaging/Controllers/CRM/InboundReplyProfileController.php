<?php

namespace App\Modules\InboundMessaging\Controllers\CRM;

use App\Http\Controllers\Controller;
use App\Modules\InboundMessaging\Actions\ReplyProfiles\DeleteInboundReplyProfileAction;
use App\Modules\InboundMessaging\Actions\ReplyProfiles\SaveInboundReplyProfileAction;
use App\Modules\InboundMessaging\Actions\ReplyProfiles\SetInboundReplyProfileStateAction;
use App\Modules\InboundMessaging\Models\InboundReplyProfile;
use App\Modules\InboundMessaging\Requests\SaveInboundReplyProfileRequest;
use App\Modules\InboundMessaging\Services\ReplyProfiles\ReplyProfileWorkspace;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class InboundReplyProfileController extends Controller
{
    public function index(Request $request, ReplyProfileWorkspace $workspace): View
    {
        return view('crm.inbound-messaging.reply-profiles.index', [
            'workspace' => $workspace->build(
                is_string($request->query('profile'))
                    ? $request->query('profile')
                    : null,
            ),
        ]);
    }

    public function store(
        SaveInboundReplyProfileRequest $request,
        SaveInboundReplyProfileAction $save,
    ): RedirectResponse {
        $profile = $save->handle($request->definition());

        return $this->redirectTo($profile)
            ->with('status', 'Reply profile created.');
    }

    public function update(
        SaveInboundReplyProfileRequest $request,
        InboundReplyProfile $inboundReplyProfile,
        SaveInboundReplyProfileAction $save,
    ): RedirectResponse {
        $profile = $save->handle(
            definition: $request->definition(),
            profile: $inboundReplyProfile,
        );

        return $this->redirectTo($profile)
            ->with('status', 'Reply handling rules updated. Future replies will use the new rules.');
    }

    public function state(
        Request $request,
        InboundReplyProfile $inboundReplyProfile,
        SetInboundReplyProfileStateAction $setState,
    ): RedirectResponse {
        $validated = $request->validate([
            'is_active' => ['required', 'boolean'],
        ]);
        $profile = $setState->handle(
            $inboundReplyProfile,
            (bool) $validated['is_active'],
        );

        return $this->redirectTo($profile)
            ->with('status', $profile->is_active
                ? 'Reply profile enabled.'
                : 'Reply profile disabled.');
    }

    public function destroy(
        InboundReplyProfile $inboundReplyProfile,
        DeleteInboundReplyProfileAction $delete,
    ): RedirectResponse {
        $delete->handle($inboundReplyProfile);

        return redirect()
            ->route('crm.inbound-messaging.reply-profiles.index')
            ->with('status', 'Reply profile removed.');
    }

    private function redirectTo(InboundReplyProfile $profile): RedirectResponse
    {
        return redirect()->route('crm.inbound-messaging.reply-profiles.index', [
            'profile' => $profile->key,
        ]);
    }
}