<?php

namespace App\Modules\Core\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Core\Jobs\AddTagToContactResultChunkJob;
use App\Modules\Core\Jobs\AssignContactResultChunkJob;
use App\Modules\Core\Access\Services\AssignmentDirectory;
use App\Modules\Core\Services\Contacts\ContactResultSetResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Illuminate\Support\Str;

final class ContactResultActionController extends Controller
{
    private const CHUNK_SIZE = 250;

    public function tag(
        Request $request,
        ContactResultSetResolver $resultSets,
    ): RedirectResponse {
        $validated = $request->validate(array_merge(
            $resultSets->validationRules(),
            [
                'tag' => ['required', 'string', 'max:255'],
            ],
        ));

        $user = $request->user();

        if (! $user instanceof User) {
            abort(403);
        }

        $payload = $resultSets->normalizeForInput($validated['contact_result']);
        $ids = $resultSets->visibleIds($payload, $user);
        $returnQuery = $resultSets->contactIndexQuery($payload);
        $tag = trim((string) $validated['tag']);

        if ($ids === []) {
            return redirect()
                ->route('crm.contacts.index', $returnQuery)
                ->with('error', 'No visible Contacts matched this result set.');
        }

        foreach (array_chunk($ids, self::CHUNK_SIZE) as $chunk) {
            AddTagToContactResultChunkJob::dispatch(
                contactIds: $chunk,
                tag: $tag,
                actorUserId: (int) $user->getKey(),
            );
        }

        return redirect()
            ->route('crm.contacts.index', $returnQuery)
            ->with('success', 'Tag update queued for '.number_format(count($ids)).' Contact(s).');
    }

    public function export(
        Request $request,
        ContactResultSetResolver $resultSets,
    ): StreamedResponse {
        $validated = $request->validate($resultSets->validationRules());
        $user = $request->user();

        if (! $user instanceof User) {
            abort(403);
        }

        $payload = $resultSets->normalizeForInput($validated['contact_result']);
        $query = $resultSets->visibleQuery($payload, $user)
            ->reorder()
            ->orderBy('contacts.id');

        return response()->streamDownload(
            function () use ($query): void {
                $handle = fopen('php://output', 'wb');

                if ($handle === false) {
                    return;
                }

                fputcsv($handle, [
                    'id',
                    'first_name',
                    'last_name',
                    'name',
                    'email',
                    'phone',
                    'birthday',
                    'source',
                    'subsource',
                    'created_at',
                    'updated_at',
                ]);

                foreach ($query->cursor() as $contact) {
                    fputcsv($handle, [
                        $contact->id,
                        $this->safeCsv($contact->first_name),
                        $this->safeCsv($contact->last_name),
                        $this->safeCsv($contact->name),
                        $this->safeCsv($contact->email),
                        $this->safeCsv($contact->phone),
                        $contact->birthday?->format('Y-m-d'),
                        $this->safeCsv($contact->source),
                        $this->safeCsv($contact->subsource),
                        $contact->created_at?->toIso8601String(),
                        $contact->updated_at?->toIso8601String(),
                    ]);
                }

                fclose($handle);
            },
            'contacts-'.now()->format('Ymd-His').'.csv',
            [
                'Content-Type' => 'text/csv; charset=UTF-8',
                'Cache-Control' => 'no-store, private',
            ],
        );
    }

    public function assignment(
        Request $request,
        ContactResultSetResolver $resultSets,
        AssignmentDirectory $directory,
    ): RedirectResponse {
        $validated = $request->validate(array_merge($resultSets->validationRules(), [
            'assignment_mode' => ['required', 'in:user,team,team_round_robin,unassign'],
            'assigned_user_id' => ['nullable', 'integer'],
            'assigned_team_id' => ['nullable', 'integer'],
            'only_unassigned' => ['sometimes', 'boolean'],
        ]));
        $user = $request->user();

        if (! $user instanceof User) { abort(403); }

        $mode = $validated['assignment_mode'];
        $assignedUser = $mode === 'user' ? $directory->activeUser((int) ($validated['assigned_user_id'] ?? 0)) : null;
        $assignedTeam = in_array($mode, ['team', 'team_round_robin'], true)
            ? $directory->activeTeam((int) ($validated['assigned_team_id'] ?? 0))
            : null;

        if ($mode === 'user' && ! $assignedUser) {
            return back()->withErrors(['assigned_user_id' => 'Choose an active person.']);
        }

        if (in_array($mode, ['team', 'team_round_robin'], true) && ! $assignedTeam) {
            return back()->withErrors(['assigned_team_id' => 'Choose an active Team.']);
        }

        if ($mode === 'team_round_robin' && ! $assignedTeam->users()->exists()) {
            return back()->withErrors(['assigned_team_id' => 'Round-robin requires a Team with at least one member.']);
        }

        $payload = $resultSets->normalizeForInput($validated['contact_result']);
        $ids = $resultSets->visibleIds($payload, $user);
        $returnQuery = $resultSets->contactIndexQuery($payload);

        if ($ids === []) {
            return redirect()->route('crm.contacts.index', $returnQuery)->with('error', 'No visible Contacts matched this result set.');
        }

        $operationId = (string) Str::uuid();

        foreach (array_chunk($ids, self::CHUNK_SIZE) as $chunk) {
            AssignContactResultChunkJob::dispatch(
                contactIds: $chunk,
                mode: $mode,
                assignedUserId: $assignedUser?->getKey(),
                assignedTeamId: $assignedTeam?->getKey(),
                onlyUnassigned: $request->boolean('only_unassigned'),
                actorUserId: (int) $user->getKey(),
                operationId: $operationId,
            );
        }

        return redirect()->route('crm.contacts.index', $returnQuery)
            ->with('success', 'Assignment update queued for '.number_format(count($ids)).' Contact(s).');
    }

    private function safeCsv(mixed $value): string
    {
        $value = trim((string) ($value ?? ''));

        if ($value !== '' && in_array($value[0], ['=', '+', '-', '@'], true)) {
            return "'".$value;
        }

        return $value;
    }
}