<?php

namespace Database\Factories;

use App\Modules\Core\Models\Contact;
use App\Modules\Portal\Models\PortalContactLink;
use App\Modules\Portal\Models\PortalUser;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PortalContactLink>
 */
class PortalContactLinkFactory extends Factory
{
    protected $model = PortalContactLink::class;

    public function definition(): array
    {
        return [
            'portal_user_id' => PortalUser::factory(),
            'contact_id' => Contact::factory(),
            'relationship' => PortalContactLink::RELATIONSHIP_SELF,
            'status' => PortalContactLink::STATUS_ACTIVE,
            'is_primary' => true,
            'linked_at' => now(),
            'verified_at' => now(),
            'revoked_at' => null,
            'source' => 'manual',
            'meta' => null,
        ];
    }

    public function pending(): self
    {
        return $this->state([
            'status' => PortalContactLink::STATUS_PENDING,
            'linked_at' => null,
            'verified_at' => null,
        ]);
    }

    public function revoked(): self
    {
        return $this->state([
            'status' => PortalContactLink::STATUS_REVOKED,
            'revoked_at' => now(),
        ]);
    }
}
