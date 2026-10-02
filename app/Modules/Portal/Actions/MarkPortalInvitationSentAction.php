<?php

namespace App\Modules\Portal\Actions;

use App\Modules\Portal\Models\PortalInvitation;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use DomainException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

final class MarkPortalInvitationSentAction
{
    public function handle(
        PortalInvitation $invitation,
        ?CarbonInterface $sentAt = null,
    ): PortalInvitation {
        if (! $invitation->exists || $invitation->getKey() === null) {
            throw new InvalidArgumentException(
                'Portal invitation must be persisted.',
            );
        }

        $sentAt = $sentAt !== null
            ? CarbonImmutable::instance($sentAt)->utc()
            : CarbonImmutable::now('UTC');

        return DB::transaction(function () use ($invitation, $sentAt): PortalInvitation {
            $locked = PortalInvitation::query()
                ->lockForUpdate()
                ->find($invitation->getKey());

            if (! $locked instanceof PortalInvitation) {
                throw new RuntimeException(
                    'Portal invitation is no longer available.',
                );
            }

            if ($locked->status === PortalInvitation::STATUS_SENT) {
                return $locked;
            }

            if ($locked->status !== PortalInvitation::STATUS_PENDING) {
                throw new DomainException(
                    'Only pending Portal invitations may be marked sent.',
                );
            }

            if ($locked->expires_at !== null && ! $locked->expires_at->isAfter($sentAt)) {
                throw new DomainException(
                    'Expired Portal invitations cannot be marked sent.',
                );
            }

            $locked->forceFill([
                'status' => PortalInvitation::STATUS_SENT,
                'sent_at' => $locked->sent_at ?? $sentAt,
            ])->save();

            return $locked->refresh();
        });
    }
}