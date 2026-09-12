<?php

namespace App\Modules\Core\Import\PostProcessors;

use App\Modules\Core\Access\Actions\AssignContactOwnershipAction;
use App\Modules\Core\Access\Services\AssignmentDirectory;
use App\Modules\Core\Access\Services\TeamRoundRobinAssigneeResolver;
use App\Modules\Core\Contracts\Contacts\ContactImportPostProcessor;
use App\Modules\Core\Contracts\Contacts\ContactImportPostProcessorOperatorConfigProvider;
use App\Modules\Core\Data\Contacts\ContactImportContext;
use App\Modules\Core\Data\Contacts\ContactImportPostProcessResult;
use Illuminate\Validation\ValidationException;

final class ContactImportAssignmentPostProcessor implements ContactImportPostProcessor, ContactImportPostProcessorOperatorConfigProvider
{
    public function __construct(
        private readonly AssignmentDirectory $directory,
        private readonly TeamRoundRobinAssigneeResolver $roundRobin,
        private readonly AssignContactOwnershipAction $assign,
    ) {}

    public function key(): string { return 'contact_assignment'; }
    public function label(): string { return 'Assignment'; }
    public function sort(): int { return 30; }

    public function operatorConfig(?array $configured): array
    {
        return array_replace([
            'mode' => 'none',
            'user_id' => null,
            'team_id' => null,
            'only_unassigned' => true,
        ], $configured ?? []);
    }

    public function normalizeConfig(array $config): array
    {
        $mode = is_string($config['mode'] ?? null) ? trim($config['mode']) : 'none';

        if (! in_array($mode, ['none', 'user', 'team', 'team_round_robin'], true)) {
            throw new \InvalidArgumentException('Contact import assignment mode is invalid.');
        }

        return [
            'mode' => $mode,
            'user_id' => is_numeric($config['user_id'] ?? null) ? (int) $config['user_id'] : null,
            'team_id' => is_numeric($config['team_id'] ?? null) ? (int) $config['team_id'] : null,
            'only_unassigned' => filter_var($config['only_unassigned'] ?? true, FILTER_VALIDATE_BOOL),
        ];
    }

    public function inputDefinitions(array $config): array
    {
        return [
            [
                'key' => 'mode',
                'label' => 'Assign imported Contacts',
                'type' => 'select',
                'options' => [
                    ['value' => 'none', 'label' => 'Leave assignments unchanged'],
                    ['value' => 'user', 'label' => 'Assign to one person'],
                    ['value' => 'team', 'label' => 'Assign to a Team queue'],
                    ['value' => 'team_round_robin', 'label' => 'Round-robin across a Team'],
                ],
            ],
            [
                'key' => 'user_id',
                'label' => 'Person',
                'type' => 'select',
                'options' => $this->directory->activeUsers()->map(fn ($user): array => [
                    'value' => (string) $user->getKey(),
                    'label' => $user->name ?: $user->email,
                ])->all(),
                'show_when' => ['field' => 'mode', 'equals' => 'user'],
            ],
            [
                'key' => 'team_id',
                'label' => 'Team',
                'type' => 'select',
                'options' => $this->directory->activeTeams()->map(fn ($team): array => [
                    'value' => (string) $team->getKey(),
                    'label' => $team->name,
                ])->all(),
            ],
            [
                'key' => 'only_unassigned',
                'label' => 'Only assign Contacts that are currently unassigned',
                'type' => 'checkbox',
                'description' => 'Recommended for repeat imports so existing ownership is not reshuffled.',
            ],
        ];
    }

    public function withSubmittedInputs(array $config, array $submitted): array
    {
        $resolved = $this->normalizeConfig(array_replace($config, $submitted));

        if ($resolved['mode'] === 'user' && ! $this->directory->activeUser((int) $resolved['user_id'])) {
            throw ValidationException::withMessages(['post_import_inputs.contact_assignment.user_id' => 'Choose an active person.']);
        }

        if (in_array($resolved['mode'], ['team', 'team_round_robin'], true)
            && ! $this->directory->activeTeam((int) $resolved['team_id'])
        ) {
            throw ValidationException::withMessages(['post_import_inputs.contact_assignment.team_id' => 'Choose an active Team.']);
        }

        return $resolved;
    }

    public function shouldProcess(array $config): bool
    {
        return ($config['mode'] ?? 'none') !== 'none';
    }

    public function summary(array $config): string
    {
        return match ($config['mode'] ?? 'none') {
            'user' => 'Assign imported Contacts to one person.',
            'team' => 'Assign imported Contacts to a Team queue.',
            'team_round_robin' => 'Round-robin imported Contacts across a Team.',
            default => 'Leave Contact assignments unchanged.',
        };
    }

    public function handle(ContactImportContext $context, array $config): ContactImportPostProcessResult
    {
        if (($config['only_unassigned'] ?? true)
            && ($context->contact->assigned_user_id || $context->contact->assigned_team_id)
        ) {
            return ContactImportPostProcessResult::skipped('already_assigned', 'Existing Contact assignment preserved.');
        }

        $mode = $config['mode'];
        $team = in_array($mode, ['team', 'team_round_robin'], true)
            ? $this->directory->activeTeam((int) $config['team_id'])
            : null;
        $user = $mode === 'user'
            ? $this->directory->activeUser((int) $config['user_id'])
            : null;

        if ($mode === 'team_round_robin' && $team) {
            $user = $this->roundRobin->next($team, 'contacts');
        }

        if (($mode === 'user' && ! $user) || (in_array($mode, ['team', 'team_round_robin'], true) && ! $team)) {
            return ContactImportPostProcessResult::blocked('assignment_target_unavailable', 'The selected assignment target is no longer active.');
        }

        $this->assign->handle($context->contact, $user, $team, source: 'contact_import_strategy');

        return ContactImportPostProcessResult::applied([
            'assigned_user_id' => $user?->getKey(),
            'assigned_team_id' => $team?->getKey(),
            'mode' => $mode,
        ]);
    }
}