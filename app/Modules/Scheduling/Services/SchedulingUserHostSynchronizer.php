<?php

namespace App\Modules\Scheduling\Services;

use App\Models\User;
use App\Modules\Core\Access\Models\UserAccessProfile;
use App\Modules\Scheduling\Models\SchedulingHost;
use Illuminate\Support\Facades\Schema;

final class SchedulingUserHostSynchronizer
{
    public function syncUser(User $user): void
    {
        if (! Schema::hasTable('scheduling_hosts')) {
            return;
        }

        $profile = Schema::hasTable('user_access_profiles')
            ? UserAccessProfile::query()
                ->where('user_id', $user->getKey())
                ->first()
            : null;
        $userIsActive = $profile?->is_active ?? true;

        SchedulingHost::withTrashed()
            ->where('hostable_type', $user->getMorphClass())
            ->where('hostable_id', $user->getKey())
            ->each(function (SchedulingHost $host) use ($user, $userIsActive): void {
                $host->forceFill([
                    'name' => $this->userName($user),
                    'email' => strtolower(trim((string) $user->email)),
                ]);

                if (! $userIsActive && $host->status === SchedulingHost::STATUS_ACTIVE) {
                    $host->status = SchedulingHost::STATUS_INACTIVE;
                }

                if ($host->isDirty()) {
                    $host->saveQuietly();
                }
            });
    }

    public function syncAccessProfile(UserAccessProfile $profile): void
    {
        $user = $profile->user()->first();

        if ($user instanceof User) {
            $this->syncUser($user);
        }
    }

    private function userName(User $user): string
    {
        $name = trim((string) $user->name);

        if ($name !== '') {
            return $name;
        }

        $email = trim((string) $user->email);

        return $email !== '' ? $email : 'CRM user #'.$user->getKey();
    }
}