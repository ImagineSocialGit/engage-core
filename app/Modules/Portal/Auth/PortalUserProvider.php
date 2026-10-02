<?php

namespace App\Modules\Portal\Auth;

use App\Modules\Portal\Models\PortalUser;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\UserProvider;
use Illuminate\Contracts\Hashing\Hasher;
use InvalidArgumentException;

final class PortalUserProvider implements UserProvider
{
    public function __construct(
        private readonly Hasher $hasher,
    ) {}

    public function retrieveById($identifier)
    {
        return PortalUser::query()
            ->whereKey($identifier)
            ->where('status', PortalUser::STATUS_ACTIVE)
            ->first();
    }

    public function retrieveByToken($identifier, $token)
    {
        $user = $this->retrieveById($identifier);

        if (! $user instanceof PortalUser) {
            return null;
        }

        $rememberToken = $user->getRememberToken();

        if (! is_string($rememberToken)
            || $rememberToken === ''
            || ! is_string($token)
            || $token === ''
            || ! hash_equals($rememberToken, $token)
        ) {
            return null;
        }

        return $user;
    }

    public function updateRememberToken(Authenticatable $user, $token)
    {
        if (! $user instanceof PortalUser) {
            throw new InvalidArgumentException(
                'Portal authentication can update remember tokens only for PortalUser records.',
            );
        }

        $user->setRememberToken($token);
        $user->saveQuietly();
    }

    public function retrieveByCredentials(array $credentials)
    {
        $email = $credentials['email'] ?? null;

        if (! is_string($email)) {
            return null;
        }

        try {
            $email = PortalUser::canonicalEmail($email);
        } catch (InvalidArgumentException) {
            return null;
        }

        return PortalUser::query()
            ->where('status', PortalUser::STATUS_ACTIVE)
            ->where('email', $email)
            ->first();
    }

    public function validateCredentials(Authenticatable $user, array $credentials)
    {
        if (! $user instanceof PortalUser || ! $user->isAuthenticatable()) {
            return false;
        }

        $password = $credentials['password'] ?? null;

        if (! is_string($password) || $password === '') {
            return false;
        }

        return $this->hasher->check(
            $password,
            (string) $user->getAuthPassword(),
        );
    }

    public function rehashPasswordIfRequired(
        Authenticatable $user,
        array $credentials,
        bool $force = false,
    ) {
        if (! $user instanceof PortalUser) {
            return;
        }

        $password = $credentials['password'] ?? null;

        if (! is_string($password) || $password === '') {
            return;
        }

        $currentHash = (string) $user->getAuthPassword();

        if (! $force && ! $this->hasher->needsRehash($currentHash)) {
            return;
        }

        $user->forceFill([
            'password' => $password,
        ])->saveQuietly();
    }
}