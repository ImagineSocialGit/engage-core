<?php

namespace Database\Seeders;

use App\Modules\InternalNotifications\Models\TeamMember;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->environment('production')) {
            $email = config('setup.seed_user.email');
            $name = config('setup.seed_user.name');
            $password = config('setup.seed_user.password');

            if (! $email || ! $name || ! $password) {
                throw new \RuntimeException('Production seed user email, name, and password must be configured.');
            }
        } else {
            $email = config('setup.seed_user.email') ?: 'admin@test.com';
            $name = config('setup.seed_user.name') ?: 'admin';
            $password = config('setup.seed_user.password') ?: 'password';
        }

        $user = User::updateOrCreate(
            ['email' => $email],
            [
                'name' => $name,
                'password' => Hash::make($password),
            ]
        );

        TeamMember::updateOrCreate(
            ['user_id' => $user->id],
            [
                'name' => $user->name,
                'email' => $user->email,
                'role' => 'site_admin',
                'is_active' => true,
            ]
        );
    }
}