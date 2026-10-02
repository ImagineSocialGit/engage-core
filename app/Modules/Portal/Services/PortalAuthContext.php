<?php

namespace App\Modules\Portal\Services;

use App\Modules\Portal\Models\PortalUser;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Contracts\Auth\Factory as AuthFactory;

final class PortalAuthContext
{
    public function __construct(
        private readonly AuthFactory $auth,
    ) {}

    public function user(): ?PortalUser
    {
        $user = $this->auth->guard('portal')->user();

        return $user instanceof PortalUser
            ? $user
            : null;
    }

    public function requireUser(): PortalUser
    {
        $user = $this->user();

        if (! $user instanceof PortalUser) {
            throw new AuthenticationException(
                'Portal authentication is required.',
                ['portal'],
            );
        }

        return $user;
    }

    /**
     * @return array<int, int>
     */
    public function activeContactIds(): array
    {
        $user = $this->requireUser();

        return $user->contactLinks()
            ->where('status', 'active')
            ->orderByDesc('is_primary')
            ->orderBy('id')
            ->pluck('contact_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->values()
            ->all();
    }
}