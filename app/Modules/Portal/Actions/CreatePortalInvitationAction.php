<?php

namespace App\Modules\Portal\Actions;

use App\Modules\Core\Models\Contact;
use App\Modules\Portal\Data\PortalInvitationToken;
use App\Modules\Portal\Models\PortalContactLink;
use App\Modules\Portal\Models\PortalInvitation;
use App\Modules\Portal\Models\PortalUser;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class CreatePortalInvitationAction
{
    public function handle(
        ?Contact $contact = null,
        ?PortalUser $portalUser = null,
        ?string $email = null,
        ?string $phone = null,
        string $channel = 'email',
        string $purpose = PortalInvitation::PURPOSE_ACCOUNT_ACCESS,
        string $relationship = PortalContactLink::RELATIONSHIP_SELF,
        ?CarbonInterface $expiresAt = null,
        string $source = 'manual',
    ): PortalInvitationToken {
        $this->assertPersisted($contact, 'Contact');
        $this->assertPersisted($portalUser, 'Portal user');

        $email = $this->email($email ?? $portalUser?->email);
        $phone = $this->phone($phone ?? $portalUser?->phone);
        $channel = $this->channel($channel);
        $purpose = $this->key($purpose, 'Portal invitation purpose');
        $relationship = $this->key($relationship, 'Portal contact relationship');
        $source = $this->key($source, 'Portal invitation source');

        if ($channel === 'email' && $email === null) {
            throw new InvalidArgumentException(
                'Email Portal invitations require an email address.',
            );
        }

        if ($channel === 'sms' && $phone === null) {
            throw new InvalidArgumentException(
                'SMS Portal invitations require a phone number.',
            );
        }

        if ($portalUser instanceof PortalUser
            && $email !== null
            && $portalUser->email !== null
            && PortalUser::canonicalEmail($portalUser->email) !== $email
        ) {
            throw new InvalidArgumentException(
                'Portal invitation email must match the selected Portal user.',
            );
        }

        $expiresAt = $expiresAt !== null
            ? CarbonImmutable::instance($expiresAt)->utc()
            : CarbonImmutable::now('UTC')->addDays(7);

        if (! $expiresAt->isFuture()) {
            throw new InvalidArgumentException(
                'Portal invitation expiration must be in the future.',
            );
        }

        $token = Str::random(64);

        $invitation = PortalInvitation::query()->create([
            'portal_user_id' => $portalUser?->getKey(),
            'contact_id' => $contact?->getKey(),
            'email' => $email,
            'phone' => $phone,
            'token_hash' => hash('sha256', $token),
            'status' => PortalInvitation::STATUS_PENDING,
            'channel' => $channel,
            'purpose' => $purpose,
            'expires_at' => $expiresAt,
            'source' => $source,
            'meta' => $contact instanceof Contact
                ? ['contact_relationship' => $relationship]
                : null,
        ]);

        return new PortalInvitationToken(
            invitation: $invitation,
            token: $token,
        );
    }

    private function assertPersisted(?object $model, string $label): void
    {
        if ($model === null) {
            return;
        }

        if (! property_exists($model, 'exists')
            || ! $model->exists
            || ! method_exists($model, 'getKey')
            || $model->getKey() === null
        ) {
            throw new InvalidArgumentException("{$label} must be persisted.");
        }
    }

    private function email(?string $email): ?string
    {
        if ($email === null || trim($email) === '') {
            return null;
        }

        return PortalUser::canonicalEmail($email);
    }

    private function phone(?string $phone): ?string
    {
        if ($phone === null || trim($phone) === '') {
            return null;
        }

        $phone = trim($phone);

        if (mb_strlen($phone) > 100) {
            throw new InvalidArgumentException(
                'Portal invitation phone is invalid.',
            );
        }

        return $phone;
    }

    private function channel(string $channel): string
    {
        $channel = strtolower(trim($channel));

        if (! in_array($channel, ['email', 'sms'], true)) {
            throw new InvalidArgumentException(
                'Portal invitation channel must be email or sms.',
            );
        }

        return $channel;
    }

    private function key(string $value, string $label): string
    {
        $value = strtolower(trim($value));

        if ($value === ''
            || mb_strlen($value) > 100
            || preg_match('/\A[a-z0-9][a-z0-9_.:-]*\z/', $value) !== 1
        ) {
            throw new InvalidArgumentException("{$label} is invalid.");
        }

        return $value;
    }
}