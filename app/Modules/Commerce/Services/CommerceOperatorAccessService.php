<?php

namespace App\Modules\Commerce\Services;

use App\Models\User;
use App\Modules\Core\Access\Services\UserAccessService;
use Illuminate\Auth\Access\AuthorizationException;

final class CommerceOperatorAccessService
{
    public function __construct(
        private readonly UserAccessService $access,
    ) {}

    public function canOperate(?User $user): bool
    {
        return $user instanceof User
            && $this->access->isActive($user)
            && $this->access->allows($user, 'contacts.view_all')
            && $this->access->allows($user, 'contacts.manage');
    }

    /**
     * @throws AuthorizationException
     */
    public function authorize(User $user): void
    {
        if (! $this->canOperate($user)) {
            throw new AuthorizationException();
        }
    }
}