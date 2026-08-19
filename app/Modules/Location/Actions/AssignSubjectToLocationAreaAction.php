<?php

namespace App\Modules\Location\Actions;

use App\Modules\Core\Models\Contact;
use App\Modules\Location\Models\LocationArea;
use App\Modules\Location\Models\LocationAreaAssignment;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class AssignSubjectToLocationAreaAction
{
    /**
     * @param array<string, mixed>|null $meta
     */
    public function handle(
        LocationArea $area,
        Model $subject,
        ?Contact $contact = null,
        string $role = LocationAreaAssignment::ROLE_MEMBER,
        bool $isPrimary = false,
        string $source = 'manual',
        ?array $meta = null,
    ): LocationAreaAssignment {
        if (! $area->exists || $area->getKey() === null) {
            throw new InvalidArgumentException('Location area must be persisted before assignment.');
        }

        if ($area->status !== LocationArea::STATUS_ACTIVE) {
            throw new InvalidArgumentException('Only active Location areas may receive active assignments.');
        }

        if (! $subject->exists || $subject->getKey() === null) {
            throw new InvalidArgumentException('Location area subject must be persisted before assignment.');
        }

        if ($contact !== null && (! $contact->exists || $contact->getKey() === null)) {
            throw new InvalidArgumentException('Location area contact must be persisted before assignment.');
        }

        $role = trim($role);

        if (! in_array($role, [
            LocationAreaAssignment::ROLE_MEMBER,
            LocationAreaAssignment::ROLE_SERVICEABLE,
            LocationAreaAssignment::ROLE_EXCLUDED,
        ], true)) {
            throw new InvalidArgumentException("Unsupported Location area assignment role [{$role}].");
        }

        $source = trim($source);

        if ($source === '' || mb_strlen($source) > 255) {
            throw new InvalidArgumentException('Location area assignment source must contain 1-255 characters.');
        }

        return DB::transaction(function () use (
            $area,
            $subject,
            $contact,
            $role,
            $isPrimary,
            $source,
            $meta,
        ): LocationAreaAssignment {
            $subjectType = $subject->getMorphClass();
            $subjectId = $subject->getKey();

            $assignment = LocationAreaAssignment::withTrashed()
                ->where('location_area_id', $area->getKey())
                ->where('subject_type', $subjectType)
                ->where('subject_id', $subjectId)
                ->where('role', $role)
                ->lockForUpdate()
                ->first();

            if ($assignment === null) {
                $assignment = new LocationAreaAssignment([
                    'location_area_id' => $area->getKey(),
                    'subject_type' => $subjectType,
                    'subject_id' => $subjectId,
                    'role' => $role,
                    'starts_at' => now(),
                ]);
            } elseif ($assignment->trashed()) {
                $assignment->restore();
            }

            if ($isPrimary) {
                $primaryAssignments = LocationAreaAssignment::query()
                    ->where('subject_type', $subjectType)
                    ->where('subject_id', $subjectId)
                    ->where('role', $role)
                    ->where('status', LocationAreaAssignment::STATUS_ACTIVE)
                    ->where('is_primary', true);

                if ($assignment->exists) {
                    $primaryAssignments->whereKeyNot($assignment->getKey());
                }

                $primaryAssignments->update(['is_primary' => false]);
            }

            $assignment->location_id = null;
            $assignment->contact_id = $contact?->getKey();
            $assignment->status = LocationAreaAssignment::STATUS_ACTIVE;
            $assignment->is_primary = $isPrimary;
            $assignment->expires_at = null;
            $assignment->source = $source;

            if ($assignment->starts_at === null) {
                $assignment->starts_at = now();
            }

            if ($meta !== null) {
                $assignment->meta = $meta;
            }

            $assignment->save();

            return $assignment->refresh();
        });
    }
}