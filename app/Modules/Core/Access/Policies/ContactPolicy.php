<?php

namespace App\Modules\Core\Access\Policies;

use App\Models\User;
use App\Modules\Core\Access\Services\ContactVisibility;
use App\Modules\Core\Access\Services\UserAccessService;
use App\Modules\Core\Models\Contact;

final class ContactPolicy
{
    public function __construct(
        private readonly UserAccessService $access,
        private readonly ContactVisibility $visibility,
    ) {}

    public function viewAny(User $user): bool
    {
        return $this->access->isActive($user);
    }

    public function view(User $user, Contact $contact): bool
    {
        return $this->visibility->canView($user, $contact);
    }

    public function create(User $user): bool
    {
        return $this->access->allows($user, 'contacts.manage');
    }

    public function update(User $user, Contact $contact): bool
    {
        return $this->access->allows($user, 'contacts.manage')
            && $this->visibility->canView($user, $contact);
    }

    public function assign(User $user, Contact $contact): bool
    {
        return $this->access->allows($user, 'contacts.assign')
            && $this->visibility->canView($user, $contact);
    }
}