<?php

namespace App\Modules\Portal\Actions;

use App\Modules\Core\Models\Contact;
use App\Modules\Portal\Models\PortalContactLink;
use App\Modules\Portal\Models\PortalUser;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

final class LinkPortalUserToContactAction
{
    public function handle(
        PortalUser $portalUser,
        Contact $contact,
        string $relationship = PortalContactLink::RELATIONSHIP_SELF,
        bool $isPrimary = true,
        bool $verified = true,
        string $source = 'portal',
    ): PortalContactLink {
        $portalUserId = $this->persistedId($portalUser, 'Portal user');
        $contactId = $this->persistedId($contact, 'Contact');
        $relationship = $this->requiredKey($relationship, 'Portal contact relationship');
        $source = $this->requiredKey($source, 'Portal contact link source');

        return DB::transaction(function () use (
            $portalUserId,
            $contactId,
            $relationship,
            $isPrimary,
            $verified,
            $source,
        ): PortalContactLink {
            $lockedUser = PortalUser::query()
                ->lockForUpdate()
                ->find($portalUserId);

            if (! $lockedUser instanceof PortalUser) {
                throw new RuntimeException('Portal user is no longer available.');
            }

            $lockedContact = Contact::query()
                ->lockForUpdate()
                ->find($contactId);

            if (! $lockedContact instanceof Contact) {
                throw new RuntimeException('Contact is no longer available.');
            }

            if ($isPrimary) {
                PortalContactLink::query()
                    ->where('portal_user_id', $portalUserId)
                    ->where('status', PortalContactLink::STATUS_ACTIVE)
                    ->where('is_primary', true)
                    ->update(['is_primary' => false]);
            }

            $link = PortalContactLink::withTrashed()
                ->where('portal_user_id', $portalUserId)
                ->where('contact_id', $contactId)
                ->lockForUpdate()
                ->first();

            $now = now();

            if (! $link instanceof PortalContactLink) {
                $link = new PortalContactLink([
                    'portal_user_id' => $portalUserId,
                    'contact_id' => $contactId,
                ]);
            } elseif ($link->trashed()) {
                $link->restore();
            }

            $link->forceFill([
                'relationship' => $relationship,
                'status' => PortalContactLink::STATUS_ACTIVE,
                'is_primary' => $isPrimary,
                'linked_at' => $link->linked_at ?? $now,
                'verified_at' => $verified
                    ? ($link->verified_at ?? $now)
                    : $link->verified_at,
                'revoked_at' => null,
                'source' => $source,
            ])->save();

            return $link->refresh();
        });
    }

    private function persistedId(object $model, string $label): int
    {
        if (! property_exists($model, 'exists')
            || ! $model->exists
            || ! method_exists($model, 'getKey')
            || ! is_numeric($model->getKey())
            || (int) $model->getKey() < 1
        ) {
            throw new InvalidArgumentException("{$label} must be persisted.");
        }

        return (int) $model->getKey();
    }

    private function requiredKey(string $value, string $label): string
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