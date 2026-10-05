<?php

namespace App\Modules\Portal\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Modules\Portal\Actions\AuthenticatePortalUserAction;
use App\Modules\Portal\Contracts\PortalAccountNotificationTransport;
use App\Modules\Portal\Models\PortalUser;
use App\Modules\Portal\Services\PortalPresentationResolver;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

final class PortalSessionController extends Controller
{
    public function create(
        AuthFactory $auth,
        PortalAccountNotificationTransport $notifications,
        PortalPresentationResolver $presentation,
    ): View|RedirectResponse {
        if ($auth->guard('portal')->user() instanceof PortalUser) {
            return redirect()->route('portal.home');
        }

        return view('portal.auth.login', [
            'passwordResetAvailable' => $notifications->available(),
            'presentation' => $presentation->resolve('Sign in'),
        ]);
    }

    public function store(Request $request, AuthenticatePortalUserAction $authenticate): RedirectResponse
    {
        $this->ensureIsNotRateLimited($request);

        $credentials = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'password' => ['required', 'string', 'max:255'],
            'remember' => ['nullable', 'boolean'],
        ]);

        $user = $authenticate->handle(
            email: (string) $credentials['email'],
            password: (string) $credentials['password'],
            remember: $request->boolean('remember'),
        );

        if (! $user instanceof PortalUser) {
            RateLimiter::hit($this->throttleKey($request), $this->decaySeconds());

            throw ValidationException::withMessages([
                'email' => 'Invalid credentials.',
            ]);
        }

        RateLimiter::clear($this->throttleKey($request));
        $request->session()->regenerate();

        return redirect()->intended(route('portal.home'));
    }

    public function destroy(Request $request, AuthFactory $auth): RedirectResponse
    {
        $auth->guard('portal')->logout();
        $request->session()->regenerateToken();

        return redirect()->route('portal.login');
    }

    private function ensureIsNotRateLimited(Request $request): void
    {
        $key = $this->throttleKey($request);

        if (! RateLimiter::tooManyAttempts($key, max(1, (int) config('portal.login.max_attempts', 5)))) {
            return;
        }

        throw ValidationException::withMessages([
            'email' => 'Too many login attempts. Please try again later.',
        ]);
    }

    private function throttleKey(Request $request): string
    {
        return 'portal-login:'.Str::transliterate(Str::lower(trim((string) $request->input('email'))).'|'.$request->ip());
    }

    private function decaySeconds(): int
    {
        return max(1, (int) config('portal.login.decay_seconds', 60));
    }
}