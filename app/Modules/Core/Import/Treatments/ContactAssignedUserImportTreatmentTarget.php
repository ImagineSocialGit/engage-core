<?php

namespace App\Modules\Core\Import\Treatments;

use App\Modules\Core\Access\Actions\AssignContactOwnershipAction;
use App\Modules\Core\Access\Services\AssignmentDirectory;
use App\Modules\Core\Contracts\Contacts\ContactImportTreatmentTarget;
use App\Modules\Core\Data\Contacts\ContactImportTreatmentApplication;
use App\Modules\Core\Data\Contacts\ContactImportTreatmentDefinition;
use Illuminate\Validation\ValidationException;

final class ContactAssignedUserImportTreatmentTarget implements ContactImportTreatmentTarget
{
    public function __construct(
        private readonly AssignmentDirectory $directory,
        private readonly AssignContactOwnershipAction $assign,
    ) {}

    public function available(): bool { return true; }

    public function definition(): ContactImportTreatmentDefinition
    {
        return new ContactImportTreatmentDefinition(
            key: 'assigned_user',
            label: 'Assigned person',
            section: 'Assignment',
            description: 'Map readable owner values from the CSV to active CRM users. Local IDs never need to appear in the file.',
            options: $this->directory->activeUsers()->map(fn ($user): array => [
                'value' => (string) $user->getKey(),
                'label' => $user->name ?: $user->email,
            ])->all(),
            sort: 10,
            unmappedOptionLabel: 'Leave assignment unchanged',
        );
    }

    public function normalizeValues(array $values): array
    {
        $ids = collect($values)->filter(fn ($value): bool => is_numeric($value))->map(fn ($value): string => (string) (int) $value)->unique()->values();

        if ($ids->count() !== 1 || ! $this->directory->activeUser((int) $ids->first())) {
            throw ValidationException::withMessages(['treatments.assigned_user' => 'Choose one active CRM user.']);
        }

        return $ids->all();
    }

    public function fieldOverrides(array $values): array { return []; }

    public function apply(ContactImportTreatmentApplication $application): void
    {
        $user = $this->directory->activeUser((int) $application->values[0]);

        if (! $user) { return; }

        $team = $application->contact->assignedTeam;
        if ($team && ! $team->users()->whereKey($user->getKey())->exists()) { $team = null; }

        $actor = $application->actor instanceof \App\Models\User ? $application->actor : null;
        $this->assign->handle($application->contact, $user, $team, $actor, 'contact_import_mapping');
    }
}