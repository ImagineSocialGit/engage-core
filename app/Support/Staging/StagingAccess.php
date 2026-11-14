<?php

namespace App\Support\Staging;

use Illuminate\Http\Request;

/**
 * Browser-only staging access, separate from application user authentication.
 * Runtime-owned credentials are resolved through Laravel's cached configuration.
 */
final class StagingAccess
{
    private const SESSION_PROOF = 'staging.access_proof';

    private const SESSION_INTENDED = 'staging.intended_path';

    public function configured(): bool
    {
        $user = config('staging.user');
        $hash = config('staging.password_hash');

        return is_string($user)
            && trim($user) !== ''
            && is_string(config('app.key'))
            && config('app.key') !== ''
            && is_string($hash)
            && password_get_info($hash)['algoName'] !== 'unknown'
            && config('session.secure') === true
            && config('session.http_only') === true
            && in_array(config('session.same_site'), ['lax', 'strict'], true);
    }

    public function credentialsMatch(mixed $user, mixed $password): bool
    {
        if (! $this->configured() || ! is_string($user) || ! is_string($password)) {
            return false;
        }

        // Always verify the password even if the username is incorrect.
        $passwordMatches = password_verify($password, config('staging.password_hash'));
        $userMatches = hash_equals(config('staging.user'), $user);

        return $passwordMatches && $userMatches;
    }

    public function authenticated(Request $request): bool
    {
        if (! $this->configured()) {
            return false;
        }

        $proof = $request->session()->get(self::SESSION_PROOF);

        return is_string($proof) && hash_equals($this->proof(), $proof);
    }

    public function logIn(Request $request): void
    {
        $request->session()->regenerate(true);
        $request->session()->put(self::SESSION_PROOF, $this->proof());
    }

    public function logOut(Request $request): void
    {
        // Revokes the staging gate and any other authentication in this session.
        $request->session()->invalidate();
        $request->session()->regenerateToken();
    }

    public function rememberIntended(Request $request): void
    {
        $path = $request->getPathInfo();
        $query = $request->getQueryString();
        $target = $path.($query === null ? '' : '?'.$query);

        $request->session()->put(
            self::SESSION_INTENDED,
            $this->safeLocalPath($target),
        );
    }

    public function takeIntended(Request $request): string
    {
        $value = $request->session()->pull(self::SESSION_INTENDED, '/');

        return $this->safeLocalPath($value);
    }

    private function proof(): string
    {
        return hash_hmac(
            'sha256',
            config('staging.user')."\0".config('staging.password_hash'),
            (string) config('app.key'),
        );
    }

    private function safeLocalPath(mixed $value): string
    {
        if (! is_string($value)
            || ! str_starts_with($value, '/')
            || str_starts_with($value, '//')
            || str_contains($value, '\\')
            || preg_match('/[\x00-\x1F\x7F]/', $value) === 1
        ) {
            return '/';
        }

        return $value;
    }
}