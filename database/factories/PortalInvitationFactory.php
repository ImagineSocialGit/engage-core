<?php

namespace Database\Factories;

use App\Modules\Core\Models\Contact;
use App\Modules\Portal\Models\PortalInvitation;
use App\Modules\Portal\Models\PortalUser;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<PortalInvitation>
 */
class PortalInvitationFactory extends Factory
{
    protected $model = PortalInvitation::class;

    public function definition(): array
    {
        $email = fake()->unique()->safeEmail();

        return [
            'portal_user_id' => PortalUser::factory(),
            'contact_id' => Contact::factory(),
            'email' => $email,
            'phone' => null,
            'token_hash' => Hash::make(Str::random(40)),
            'status' => PortalInvitation::STATUS_PENDING,
            'channel' => 'email',
            'purpose' => PortalInvitation::PURPOSE_ACCOUNT_ACCESS,
            'expires_at' => now()->addDays(7),
            'sent_at' => null,
            'accepted_at' => null,
            'revoked_at' => null,
            'accepted_ip' => null,
            'accepted_user_agent' => null,
            'source' => 'manual',
            'provider' => null,
            'external_id' => null,
            'meta' => null,
        ];
    }

    public function sent(): self
    {
        return $this->state([
            'status' => PortalInvitation::STATUS_SENT,
            'sent_at' => now(),
        ]);
    }

    public function accepted(): self
    {
        return $this->state([
            'status' => PortalInvitation::STATUS_ACCEPTED,
            'sent_at' => now()->subDay(),
            'accepted_at' => now(),
            'accepted_ip' => '127.0.0.1',
            'accepted_user_agent' => 'Feature test',
        ]);
    }

    public function expired(): self
    {
        return $this->state([
            'status' => PortalInvitation::STATUS_EXPIRED,
            'expires_at' => now()->subDay(),
        ]);
    }
}
