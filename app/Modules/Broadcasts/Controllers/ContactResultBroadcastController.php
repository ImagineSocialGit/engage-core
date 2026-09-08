<?php

namespace App\Modules\Broadcasts\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Core\Services\Contacts\ContactResultSetResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class ContactResultBroadcastController extends Controller
{
    public function __invoke(
        Request $request,
        ContactResultSetResolver $resultSets,
    ): RedirectResponse {
        $validated = $request->validate($resultSets->validationRules());
        $user = $request->user();

        if (! $user instanceof User) {
            abort(403);
        }

        $payload = $resultSets->normalizeForInput($validated['contact_result']);
        $count = $resultSets->visibleCount($payload, $user);

        if ($count === 0) {
            return redirect()
                ->route('crm.contacts.index', $resultSets->contactIndexQuery($payload))
                ->with('error', 'No visible Contacts matched this result set.');
        }

        $filter = $resultSets->deferredFilter($payload, $user);
        $oldInput = [
            'recipient_filter_type' => $filter['type'],
        ];

        if ($filter['type'] === 'criteria') {
            $oldInput['recipient_criteria'] = $filter['criteria'];
        }

        if ($filter['type'] === 'contact_ids') {
            $oldInput['contact_ids'] = $filter['contact_ids'];
        }

        return redirect()
            ->route('crm.broadcasts.index')
            ->withInput($oldInput)
            ->with('success', number_format($count).' Contact(s) loaded into the Broadcast audience builder.');
    }
}