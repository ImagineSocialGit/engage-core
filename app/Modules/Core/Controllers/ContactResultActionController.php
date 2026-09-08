<?php

namespace App\Modules\Core\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Core\Jobs\AddTagToContactResultChunkJob;
use App\Modules\Core\Services\Contacts\ContactResultSetResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

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

    private function safeCsv(mixed $value): string
    {
        $value = trim((string) ($value ?? ''));

        if ($value !== '' && in_array($value[0], ['=', '+', '-', '@'], true)) {
            return "'".$value;
        }

        return $value;
    }
}