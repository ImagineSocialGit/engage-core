<?php

namespace Database\Factories;

use App\Models\TeamMember;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TeamMember>
 */
class TeamMemberFactory extends Factory
{
    protected $model = TeamMember::class;

    public function definition(): array
    {
        return [
            'user_id' => null,
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'phone' => fake()->optional()->numerify('+1##########'),
            'role' => fake()->optional()->randomElement([
                'admin',
                'loan_officer',
                'processor',
                'assistant',
            ]),
            'active' => true,
        ];
    }

    public function inactive(): self
    {
        return $this->state([
            'active' => false,
        ]);
    }

    public function withoutEmail(): self
    {
        return $this->state([
            'email' => null,
        ]);
    }

    public function withoutPhone(): self
    {
        return $this->state([
            'phone' => null,
        ]);
    }

    public function forUser(?User $user = null): self
    {
        return $this->state(fn () => [
            'user_id' => $user?->id ?? User::factory(),
        ]);
    }
}