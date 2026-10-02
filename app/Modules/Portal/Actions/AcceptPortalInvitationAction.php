<?php

namespace App\Modules\Portal\Actions;

use App\Modules\Portal\Models\PortalContactLink;
use App\Modules\Portal\Models\PortalInvitation;
use App\Modules\Portal\Models\PortalUser;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use InvalidArgumentException;
use RuntimeException;

final class AcceptPortalInvitationAction
{
    public function __construct(
        private readonly LinkPortalUserToContactAction $links,
    ) {}

    public function handle(
        PortalInvitation $invitation,
        string $token,
        string $name,
        string $password,
        ?string $email = null,
        ?string $phone = null,
        ?string $acceptedIp = null,
        ?string $acceptedUserAgent = null,
    ): PortalUser {
        if (! $invitation->exists || $invitation->getKey() === null) {
            throw new InvalidArgumentException(
                'Portal invitation must be persisted.',
            );
        }

        $name = $this->name($name);
        $password = $this->password($password);
        $phone = $this->phone($phone);
        $acceptedIp = $this->nullableString($acceptedIp, 255, 'Accepted IP');
        $acceptedUserAgent = $this->nullableString(
            $acceptedUserAgent,
            2000,
            'Accepted user agent',
        );

        return DB::transaction(function () use (
            $invitation,
            $token,
            $name,
            $password,
            $email,
            $phone,
            $acceptedIp,
            $acceptedUserAgent,
        ): PortalUser {
            $locked = PortalInvitation::query()
                ->lockForUpdate()
                ->find($invitation->getKey());

            if (! $locked instanceof PortalInvitation) {
                throw new RuntimeException(
                    'Portal invitation is no longer available.',
                );
            }

            if ($locked->status !== PortalInvitation::STATUS_SENT) {
                throw new DomainException(
                    'Portal invitation is not available for acceptance.',
                );
            }

            if ($locked->expires_at !== null && ! $locked->expires_at->isFuture()) {
                throw new DomainException(
                    'Portal invitation has expired.',
                );
            }

            if (! $this->tokenMatches($token, (string) $locked->token_hash)) {
                throw new DomainException(
                    'Portal invitation token is invalid.',
                );
            }

            $resolvedEmail = $email;

            if ($resolvedEmail === null || trim($resolvedEmail) === '') {
                $resolvedEmail = $locked->email;
            }

            if (($resolvedEmail === null || trim($resolvedEmail) === '')
                && $locked->portal_user_id !== null
            ) {
                $resolvedEmail = PortalUser::query()
                    ->whereKey($locked->portal_user_id)
                    ->value('email');
            }

            if (! is_string($resolvedEmail) || trim($resolvedEmail) === '') {
                throw new InvalidArgumentException(
                    'Portal invitation acceptance requires an email login identity.',
                );
            }

            $resolvedEmail = PortalUser::canonicalEmail($resolvedEmail);
            $user = $this->portalUserForAcceptance($locked, $resolvedEmail);
            $resolvedPhone = $phone
                ?? $locked->phone
                ?? $user?->phone;
            $resolvedPhone = $this->phone($resolvedPhone);

            $now = now();
            $emailVerifiedAt = $user?->email_verified_at;
            $phoneVerifiedAt = $user?->phone_verified_at;

            if ($this->invitationVerifiesEmail($locked, $resolvedEmail)) {
                $emailVerifiedAt ??= $now;
            }

            if ($this->invitationVerifiesPhone($locked, $resolvedPhone)) {
                $phoneVerifiedAt ??= $now;
            }

            if (! $user instanceof PortalUser) {
                $user = new PortalUser();
            }

            $user->forceFill([
                'name' => $name,
                'email' => $resolvedEmail,
                'phone' => $resolvedPhone,
                'password' => $password,
                'status' => PortalUser::STATUS_ACTIVE,
                'email_verified_at' => $emailVerifiedAt,
                'phone_verified_at' => $phoneVerifiedAt,
                'invited_at' => $user->invited_at
                    ?? $locked->sent_at
                    ?? $locked->created_at
                    ?? $now,
                'accepted_at' => $now,
                'disabled_at' => null,
                'source' => $user->exists
                    ? $user->source
                    : 'portal_invitation',
            ])->save();

            $locked->forceFill([
                'portal_user_id' => $user->getKey(),
                'status' => PortalInvitation::STATUS_ACCEPTED,
                'accepted_at' => $now,
                'accepted_ip' => $acceptedIp,
                'accepted_user_agent' => $acceptedUserAgent,
            ])->save();

            if ($locked->contact_id !== null) {
                $contact = $locked->contact()->first();

                if ($contact === null) {
                    throw new RuntimeException(
                        'Portal invitation contact is no longer available.',
                    );
                }

                $relationship = data_get(
                    $locked->meta,
                    'contact_relationship',
                    PortalContactLink::RELATIONSHIP_SELF,
                );

                $this->links->handle(
                    portalUser: $user,
                    contact: $contact,
                    relationship: is_string($relationship)
                        ? $relationship
                        : PortalContactLink::RELATIONSHIP_SELF,
                    isPrimary: true,
                    verified: true,
                    source: 'portal_invitation',
                );
            }

            return $user->refresh();
        });
    }

    private function portalUserForAcceptance(
        PortalInvitation $invitation,
        string $email,
    ): ?PortalUser {
        if ($invitation->portal_user_id !== null) {
            $user = PortalUser::query()
                ->lockForUpdate()
                ->find($invitation->portal_user_id);

            if (! $user instanceof PortalUser) {
                throw new RuntimeException(
                    'Portal invitation account is no longer available.',
                );
            }

            if ($user->status !== PortalUser::STATUS_INVITED) {
                throw new DomainException(
                    'Portal invitation account is not awaiting activation.',
                );
            }

            if ($user->email !== null
                && PortalUser::canonicalEmail($user->email) !== $email
            ) {
                throw new DomainException(
                    'Portal invitation email does not match its account.',
                );
            }

            $duplicate = PortalUser::withTrashed()
                ->where('email', $email)
                ->where($user->getKeyName(), '!=', $user->getKey())
                ->lockForUpdate()
                ->exists();

            if ($duplicate) {
                throw new DomainException(
                    'A different Portal account already uses this email address.',
                );
            }

            return $user;
        }

        if (PortalUser::withTrashed()
            ->where('email', $email)
            ->lockForUpdate()
            ->exists()
        ) {
            throw new DomainException(
                'A Portal account already exists for this email address.',
            );
        }

        return null;
    }

    private function tokenMatches(string $token, string $storedHash): bool
    {
        if ($token === '' || $storedHash === '') {
            return false;
        }

        if (strlen($storedHash) === 64 && ctype_xdigit($storedHash)) {
            return hash_equals(
                strtolower($storedHash),
                hash('sha256', $token),
            );
        }

        return Hash::check($token, $storedHash);
    }

    private function invitationVerifiesEmail(
        PortalInvitation $invitation,
        string $email,
    ): bool {
        if ($invitation->channel !== 'email'
            || ! is_string($invitation->email)
            || trim($invitation->email) === ''
        ) {
            return false;
        }

        return PortalUser::canonicalEmail($invitation->email) === $email;
    }

    private function invitationVerifiesPhone(
        PortalInvitation $invitation,
        ?string $phone,
    ): bool {
        return $invitation->channel === 'sms'
            && $phone !== null
            && is_string($invitation->phone)
            && trim($invitation->phone) !== ''
            && trim($invitation->phone) === $phone;
    }

    private function name(string $name): string
    {
        $name = trim($name);

        if ($name === '' || mb_strlen($name) > 255) {
            throw new InvalidArgumentException(
                'Portal account name is invalid.',
            );
        }

        return $name;
    }

    private function password(string $password): string
    {
        if (mb_strlen($password) < 8 || mb_strlen($password) > 255) {
            throw new InvalidArgumentException(
                'Portal account password must be between 8 and 255 characters.',
            );
        }

        return $password;
    }

    private function phone(?string $phone): ?string
    {
        if ($phone === null || trim($phone) === '') {
            return null;
        }

        $phone = trim($phone);

        if (mb_strlen($phone) > 100) {
            throw new InvalidArgumentException(
                'Portal account phone is invalid.',
            );
        }

        return $phone;
    }

    private function nullableString(
        ?string $value,
        int $maximumLength,
        string $label,
    ): ?string {
        if ($value === null || trim($value) === '') {
            return null;
        }

        $value = trim($value);

        if (mb_strlen($value) > $maximumLength) {
            throw new InvalidArgumentException("{$label} is too long.");
        }

        return $value;
    }
}