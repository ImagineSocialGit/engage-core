<?php

namespace App\Modules\Core\Import\Treatments;

use App\Models\User;
use App\Modules\Core\Access\Actions\AssignContactOwnershipAction;
use App\Modules\Core\Access\Services\AssignmentDirectory;
use App\Modules\Core\Contracts\Contacts\ContactImportTreatmentTarget;
use App\Modules\Core\Data\Contacts\ContactImportTreatmentApplication;
use App\Modules\Core\Data\Contacts\ContactImportTreatmentDefinition;
use Illuminate\Validation\ValidationException;

final class ContactAssignedTeamImportTreatmentTarget implements ContactImportTreatmentTarget
{
    public function __construct(
        private readonly AssignmentDirectory $directory,
        private readonly AssignContactOwnershipAction $assign,
    ) {}

    public function available(): bool { return true; }

    public function definition(): ContactImportTreatmentDefinition
    {
        return new ContactImportTreatmentDefinition(
            key: 'assigned_team',
            label: 'Assigned Team',
            section: 'Assignment',
            description: 'Map readable Team values from the CSV to active CRM Teams.',
            options: $this->directory->activeTeams()->map(fn ($team): array => [
                'value' => (string) $team->getKey(),
                'label' => $team->name,
            ])->all(),
            sort: 20,
            unmappedOptionLabel: 'Leave assignment unchanged',
        );
    }

    public function normalizeValues(array $values): array
    {
        $ids = collect($values)->filter(fn ($value): bool => is_numeric($value))->map(fn ($value): string => (string) (int) $value)->unique()->values();

        if ($ids->count() !== 1 || ! $this->directory->activeTeam((int) $ids->first())) {
            throw ValidationException::withMessages(['treatments.assigned_team' => 'Choose one active CRM Team.']);
        }

        return $ids->all();
    }

    public function fieldOverrides(array $values): array { return []; }

    public function apply(ContactImportTreatmentApplication $application): void
    {
        $team = $this->directory->activeTeam((int) $application->values[0]);
        if (! $team) { return; }

        $user = $application->contact->assignedUser;
        if ($user instanceof User && ! $team->users()->whereKey($user->getKey())->exists()) { $user = null; }

        $actor = $application->actor instanceof User ? $application->actor : null;
        $this->assign->handle($application->contact, $user, $team, $actor, 'contact_import_mapping');
    }
}