<?php

namespace App\Modules\Portal\Actions;

use App\Modules\Portal\Models\PortalUser;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Contracts\Auth\StatefulGuard;
use InvalidArgumentException;
use LogicException;

final class AuthenticatePortalUserAction
{
    public function __construct(
        private readonly AuthFactory $auth,
    ) {}

    public function handle(
        string $email,
        string $password,
        bool $remember = false,
    ): ?PortalUser {
        try {
            $email = PortalUser::canonicalEmail($email);
        } catch (InvalidArgumentException) {
            return null;
        }

        $guard = $this->auth->guard('portal');

        if (! $guard instanceof StatefulGuard) {
            throw new LogicException(
                'Portal authentication guard must be stateful.',
            );
        }

        if (! $guard->attempt([
            'email' => $email,
            'password' => $password,
        ], $remember)) {
            return null;
        }

        $user = $guard->user();

        if (! $user instanceof PortalUser) {
            $guard->logout();

            return null;
        }

        $user->forceFill([
            'last_login_at' => now(),
        ])->saveQuietly();

        return $user->refresh();
    }
}