<?php

namespace Database\Factories;

use App\Modules\Core\Models\Contact;
use App\Modules\Portal\Models\PortalAccessGrant;
use App\Modules\Portal\Models\PortalUser;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;

/**
 * @extends Factory<PortalAccessGrant>
 */
class PortalAccessGrantFactory extends Factory
{
    protected $model = PortalAccessGrant::class;

    public function definition(): array
    {
        return [
            'portal_user_id' => PortalUser::factory(),
            'contact_id' => Contact::factory(),
            'grantable_type' => null,
            'grantable_id' => null,
            'capability' => PortalAccessGrant::CAPABILITY_VIEW,
            'status' => PortalAccessGrant::STATUS_ACTIVE,
            'starts_at' => now(),
            'expires_at' => null,
            'granted_by_type' => null,
            'granted_by_id' => null,
            'revoked_at' => null,
            'source' => 'manual',
            'meta' => null,
        ];
    }

    public function forGrantable(Model $grantable, string $capability = PortalAccessGrant::CAPABILITY_VIEW): self
    {
        return $this->state([
            'grantable_type' => $grantable->getMorphClass(),
            'grantable_id' => $grantable->getKey(),
            'capability' => $capability,
        ]);
    }

    public function grantedBy(Model $granter): self
    {
        return $this->state([
            'granted_by_type' => $granter->getMorphClass(),
            'granted_by_id' => $granter->getKey(),
        ]);
    }

    public function revoked(): self
    {
        return $this->state([
            'status' => PortalAccessGrant::STATUS_REVOKED,
            'revoked_at' => now(),
        ]);
    }
}
