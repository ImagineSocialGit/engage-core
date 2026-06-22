<?php

namespace Database\Seeders;

use App\Models\TeamMember;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    public function run(): void
    {
        if (config('app.env') == 'production') {
            $user = User::updateOrCreate(
                ['email' => config('setup.seed_user.email')],
                [
                    'name' => config('setup.seed_user.name'),
                    'password' => Hash::make(config('setup.seed_user.password')),
                ]
            );
        } else {
            $user = User::updateOrCreate(
                ['email' => 'admin@test.com'],
                [
                    'name' => 'admin',
                    'password' => Hash::make('password'),
                ]
            );
        }

        TeamMember::updateOrCreate(
            ['user_id' => $user->id],
            [
                'name' => $user->name,
                'email' => $user->email,
                'role' => 'site_admin',
                'active' => true,
            ]
        );
    }
}