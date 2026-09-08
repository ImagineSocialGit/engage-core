<?php

namespace App\Modules\Campaigns\Controllers\CRM;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Campaigns\Jobs\EnrollContactResultCampaignChunkJob;
use App\Modules\Campaigns\Models\Campaign;
use App\Modules\Core\Services\Contacts\ContactResultSetResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class ContactResultCampaignController extends Controller
{
    private const CHUNK_SIZE = 100;

    public function store(
        Request $request,
        ContactResultSetResolver $resultSets,
    ): RedirectResponse {
        $validated = $request->validate(array_merge(
            $resultSets->validationRules(),
            [
                'campaign_key' => ['required', 'string', 'max:191', 'exists:campaigns,key'],
            ],
        ));

        $user = $request->user();

        if (! $user instanceof User) {
            abort(403);
        }

        $campaign = Campaign::query()
            ->where('key', (string) $validated['campaign_key'])
            ->where('status', Campaign::STATUS_ACTIVE)
            ->first();

        if (! $campaign instanceof Campaign) {
            throw ValidationException::withMessages([
                'campaign_key' => 'Choose an active Campaign.',
            ]);
        }

        $payload = $resultSets->normalizeForInput($validated['contact_result']);
        $ids = $resultSets->visibleIds($payload, $user);
        $returnQuery = $resultSets->contactIndexQuery($payload);

        if ($ids === []) {
            return redirect()
                ->route('crm.contacts.index', $returnQuery)
                ->with('error', 'No visible Contacts matched this result set.');
        }

        $operationId = (string) Str::uuid();

        foreach (array_chunk($ids, self::CHUNK_SIZE) as $chunk) {
            EnrollContactResultCampaignChunkJob::dispatch(
                campaignKey: (string) $campaign->key,
                contactIds: $chunk,
                operationId: $operationId,
                actorUserId: (int) $user->getKey(),
            );
        }

        return redirect()
            ->route('crm.contacts.index', $returnQuery)
            ->with('success', 'Campaign enrollment queued for '.number_format(count($ids)).' Contact(s).');
    }
}