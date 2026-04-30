<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    public function run(): void
    {
        User::updateOrCreate(
            ['email' => config('setup.seed_user.email')],
            [
                'name' => config('setup.seed_user.name'),
                'password' => Hash::make(config('setup.seed_user.password')),
            ]
        );
    }
}