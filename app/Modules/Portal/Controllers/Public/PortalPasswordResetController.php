<?php

namespace App\Modules\Portal\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Modules\Portal\Contracts\PortalAccountNotificationTransport;
use App\Modules\Portal\Models\PortalUser;
use App\Modules\Portal\Providers\PortalModuleServiceProvider;
use App\Modules\Portal\Services\PortalPresentationResolver;
use App\Modules\Portal\Services\PortalSecretLinkCodec;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use InvalidArgumentException;

final class PortalPasswordResetController extends Controller
{
    public function requestForm(
        PortalAccountNotificationTransport $transport,
        PortalPresentationResolver $presentation,
    ): View {
        abort_unless($transport->available(), 404);

        return view('portal.auth.forgot-password', [
            'presentation' => $presentation->resolve('Reset password'),
        ]);
    }

    public function request(
        Request $request,
        PortalAccountNotificationTransport $transport,
    ): RedirectResponse {
        abort_unless($transport->available(), 404);

        $validated = $request->validate([
            'email' => ['required', 'email', 'max:255'],
        ]);

        try {
            $email = PortalUser::canonicalEmail((string) $validated['email']);
        } catch (InvalidArgumentException) {
            $email = '';
        }

        $user = $email !== ''
            ? PortalUser::query()
                ->where('email', $email)
                ->where('status', PortalUser::STATUS_ACTIVE)
                ->first()
            : null;

        if ($user instanceof PortalUser && $user->isAuthenticatable()) {
            $token = Password::broker(
                PortalModuleServiceProvider::PASSWORD_BROKER,
            )->createToken($user);

            $transport->deliverPasswordReset($user, $token);
        }

        return back()->with(
            'status',
            'If that email belongs to an active account, a password reset link will be sent.',
        );
    }

    public function resetForm(
        Request $request,
        string $token,
        PortalSecretLinkCodec $codec,
        PortalPresentationResolver $presentation,
    ): View {
        try {
            $codec->decode($token, PortalSecretLinkCodec::PURPOSE_PASSWORD_RESET);
        } catch (InvalidArgumentException) {
            abort(404);
        }

        return view('portal.auth.reset-password', [
            'presentation' => $presentation->resolve('Choose a new password'),
            'token' => $token,
            'email' => is_string($request->query('email'))
                ? trim((string) $request->query('email'))
                : '',
        ]);
    }

    public function reset(
        Request $request,
        string $token,
        PortalSecretLinkCodec $codec,
    ): RedirectResponse {
        $validated = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'password' => ['required', 'confirmed', 'string', 'min:8', 'max:255'],
        ]);

        try {
            $rawToken = $codec->decode(
                $token,
                PortalSecretLinkCodec::PURPOSE_PASSWORD_RESET,
            );
            $email = PortalUser::canonicalEmail((string) $validated['email']);
        } catch (InvalidArgumentException) {
            throw ValidationException::withMessages([
                'email' => 'This password reset link is invalid or expired.',
            ]);
        }

        $status = Password::broker(
            PortalModuleServiceProvider::PASSWORD_BROKER,
        )->reset([
            'email' => $email,
            'password' => (string) $validated['password'],
            'token' => $rawToken,
        ], function (PortalUser $user, string $password): void {
            $user->forceFill([
                'password' => $password,
                'remember_token' => Str::random(60),
            ])->save();
        });

        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages([
                'email' => 'This password reset link is invalid or expired.',
            ]);
        }

        return redirect()
            ->route('portal.login')
            ->with('status', 'Your password has been updated.');
    }
}