<?php

namespace Database\Factories;

use App\Modules\Scheduling\Models\SchedulingHost;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * @extends Factory<SchedulingHost>
 */
class SchedulingHostFactory extends Factory
{
    protected $model = SchedulingHost::class;

    public function definition(): array
    {
        $name = fake()->unique()->name();

        return [
            'key' => Str::slug($name),
            'name' => $name,
            'status' => SchedulingHost::STATUS_ACTIVE,
            'hostable_type' => null,
            'hostable_id' => null,
            'timezone' => config('client.timezone', 'UTC'),
            'capacity' => 1,
            'email' => fake()->optional()->safeEmail(),
            'phone' => fake()->optional()->numerify('##########'),
            'sort_order' => 0,
            'source' => SchedulingHost::SOURCE_MANUAL,
            'meta' => null,
        ];
    }

    public function forHostable(Model $hostable): self
    {
        return $this->state([
            'hostable_type' => $hostable->getMorphClass(),
            'hostable_id' => $hostable->getKey(),
        ]);
    }

    public function inactive(): self
    {
        return $this->state([
            'status' => SchedulingHost::STATUS_INACTIVE,
        ]);
    }
}