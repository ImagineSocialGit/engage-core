<?php

namespace App\Modules\Core\Access\Services;

use App\Models\User;
use App\Modules\Core\Access\Models\UserAccessProfile;
use App\Modules\Core\Access\Support\AccessCapabilityRegistry;
use Illuminate\Support\Facades\Schema;

final class UserAccessService
{
    /** @var array<int, UserAccessProfile|null> */
    private array $profiles = [];

    private ?bool $profileTableAvailable = null;

    public function __construct(
        private readonly AccessCapabilityRegistry $capabilities,
    ) {}

    public function isActive(User $user): bool
    {
        return $this->profile($user)?->is_active ?? true;
    }

    public function roleKey(User $user): string
    {
        $role = trim((string) ($this->profile($user)?->role_key ?? ''));

        if ($role !== '' && array_key_exists($role, $this->roleDefinitions())) {
            return $role;
        }

        $legacy = trim((string) config('access.legacy_role', 'owner'));

        return array_key_exists($legacy, $this->roleDefinitions())
            ? $legacy
            : 'owner';
    }

    public function roleLabel(User $user): string
    {
        $role = $this->roleKey($user);

        return (string) ($this->roleDefinitions()[$role]['label'] ?? str($role)->headline());
    }

    public function allows(User $user, string $capability): bool
    {
        $capability = trim($capability);

        if ($capability === '' || ! $this->capabilities->has($capability) || ! $this->isActive($user)) {
            return false;
        }

        $profile = $this->profile($user);
        $overrides = is_array($profile?->capability_overrides)
            ? $profile->capability_overrides
            : [];

        if (array_key_exists($capability, $overrides)) {
            return (bool) $overrides[$capability];
        }

        $role = $this->roleDefinitions()[$this->roleKey($user)] ?? [];
        $grants = is_array($role['capabilities'] ?? null)
            ? $role['capabilities']
            : [];

        return in_array('*', $grants, true)
            || in_array($capability, $grants, true);
    }

    /** @return array<string, array<string, mixed>> */
    public function roleDefinitions(): array
    {
        $roles = config('access.roles', []);

        return is_array($roles) ? $roles : [];
    }

    /** @return array<int, string> */
    public function roleKeys(): array
    {
        return array_keys($this->roleDefinitions());
    }

    public function roleAllows(string $roleKey, string $capability): bool
    {
        if (! $this->capabilities->has($capability)) {
            return false;
        }

        $role = $this->roleDefinitions()[$roleKey] ?? null;

        if (! is_array($role)) {
            return false;
        }

        $grants = is_array($role['capabilities'] ?? null)
            ? $role['capabilities']
            : [];

        return in_array('*', $grants, true)
            || in_array($capability, $grants, true);
    }

    public function profile(User $user): ?UserAccessProfile
    {
        $userId = (int) $user->getKey();

        if ($userId <= 0 || ! $this->profileTableAvailable()) {
            return null;
        }

        if (! array_key_exists($userId, $this->profiles)) {
            $this->profiles[$userId] = UserAccessProfile::query()
                ->where('user_id', $userId)
                ->first();
        }

        return $this->profiles[$userId];
    }

    public function forget(User|int $user): void
    {
        $userId = $user instanceof User
            ? (int) $user->getKey()
            : (int) $user;

        unset($this->profiles[$userId]);
    }

    private function profileTableAvailable(): bool
    {
        return $this->profileTableAvailable ??= Schema::hasTable('user_access_profiles');
    }
}