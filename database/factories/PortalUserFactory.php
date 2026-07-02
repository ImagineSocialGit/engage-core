<?php

namespace Database\Factories;

use App\Modules\Portal\Models\PortalUser;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<PortalUser>
 */
class PortalUserFactory extends Factory
{
    protected $model = PortalUser::class;

    public function definition(): array
    {
        return [
            'uuid' => (string) Str::uuid(),
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'phone' => fake()->optional()->numerify('##########'),
            'password' => null,
            'status' => PortalUser::STATUS_INVITED,
            'email_verified_at' => null,
            'phone_verified_at' => null,
            'last_login_at' => null,
            'invited_at' => now(),
            'accepted_at' => null,
            'disabled_at' => null,
            'source' => 'manual',
            'provider' => null,
            'external_id' => null,
            'meta' => null,
        ];
    }

    public function active(): self
    {
        return $this->state([
            'status' => PortalUser::STATUS_ACTIVE,
            'email_verified_at' => now(),
            'accepted_at' => now(),
        ]);
    }

    public function withPassword(string $password = 'password'): self
    {
        return $this->state([
            'password' => Hash::make($password),
        ]);
    }

    public function disabled(): self
    {
        return $this->state([
            'status' => PortalUser::STATUS_DISABLED,
            'disabled_at' => now(),
        ]);
    }
}
