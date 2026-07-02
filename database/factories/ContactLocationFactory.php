<?php

namespace Database\Factories;

use App\Modules\Core\Models\Contact;
use App\Modules\Location\Models\ContactLocation;
use App\Modules\Location\Models\Location;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;

/**
 * @extends Factory<ContactLocation>
 */
class ContactLocationFactory extends Factory
{
    protected $model = ContactLocation::class;

    public function definition(): array
    {
        return [
            'contact_id' => Contact::factory(),
            'location_id' => Location::factory(),
            'subject_type' => null,
            'subject_id' => null,
            'type' => ContactLocation::TYPE_HOME,
            'label' => 'Home',
            'status' => ContactLocation::STATUS_ACTIVE,
            'is_primary' => true,
            'verified_at' => null,
            'valid_from' => null,
            'valid_until' => null,
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
