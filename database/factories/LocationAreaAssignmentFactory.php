<?php

namespace Database\Factories;

use App\Modules\Core\Models\Contact;
use App\Modules\Location\Models\Location;
use App\Modules\Location\Models\LocationArea;
use App\Modules\Location\Models\LocationAreaAssignment;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;

/**
 * @extends Factory<LocationAreaAssignment>
 */
class LocationAreaAssignmentFactory extends Factory
{
    protected $model = LocationAreaAssignment::class;

    public function definition(): array
    {
        return [
            'location_area_id' => LocationArea::factory(),
            'location_id' => Location::factory(),
            'contact_id' => Contact::factory(),
            'subject_type' => null,
            'subject_id' => null,
            'role' => LocationAreaAssignment::ROLE_MEMBER,
            'status' => LocationAreaAssignment::STATUS_ACTIVE,
            'starts_at' => null,
            'expires_at' => null,
            'source' => 'manual',
            'meta' => null,
        ];
    }

    public function forSubject(Model $subject): self
    {
        return $this->state([
            'subject_type' => $subject->getMorphClass(),
            'subject_id' => $subject->getKey(),
        ]);
    }
}
