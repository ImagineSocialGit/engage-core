<?php

namespace Database\Factories;

use App\Modules\Scheduling\Models\BookableService;
use App\Modules\Scheduling\Models\BookableServiceHost;
use App\Modules\Scheduling\Models\SchedulingHost;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BookableServiceHost>
 */
class BookableServiceHostFactory extends Factory
{
    protected $model = BookableServiceHost::class;

    public function definition(): array
    {
        return [
            'bookable_service_id' => BookableService::factory(),
            'scheduling_host_id' => SchedulingHost::factory(),
            'is_active' => true,
            'capacity_override' => null,
            'sort_order' => 0,
            'meta' => null,
        ];
    }

    public function inactive(): self
    {
        return $this->state([
            'is_active' => false,
        ]);
    }
}