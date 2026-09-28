<?php

namespace App\Modules\Campaigns\Controllers\CRM;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Campaigns\Actions\QueueCampaignContactResultOperationAction;
use App\Modules\Campaigns\Data\CampaignContactResultOperation;
use App\Modules\Campaigns\Models\Campaign;
use App\Modules\Core\Services\Contacts\ContactResultSetResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

final class ContactResultCampaignController extends Controller
{
    public function store(
        Request $request,
        ContactResultSetResolver $resultSets,
        QueueCampaignContactResultOperationAction $queueOperation,
    ): RedirectResponse {
        $validated = $request->validate(array_merge(
            $resultSets->validationRules(),
            [
                'campaign_key' => ['required', 'string', 'max:191', 'exists:campaigns,key'],
                'operation' => ['nullable', 'string', Rule::in(CampaignContactResultOperation::VALUES)],
                'message_step_key' => ['nullable', 'string', 'max:191'],
                'reason' => ['nullable', 'string', 'max:255'],
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

        $operation = (string) ($validated['operation'] ?? CampaignContactResultOperation::ENROLL);
        $messageStepKey = CampaignContactResultOperation::requiresMessageStep($operation)
            ? ($validated['message_step_key'] ?? null)
            : null;

        try {
            $queued = $queueOperation->handle(
                campaign: $campaign,
                contactIds: $ids,
                actor: $user,
                operation: $operation,
                messageStepKey: $messageStepKey,
                reason: $validated['reason'] ?? null,
            );
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages([
                'campaign_operation' => $exception->getMessage(),
            ]);
        }

        return redirect()
            ->route('crm.contacts.index', $returnQuery)
            ->with('success', $this->operationLabel($operation).' queued for '
                .number_format($queued['contact_count']).' leads.');
    }

    private function operationLabel(string $operation): string
    {
        return match ($operation) {
            CampaignContactResultOperation::ENROLL => 'Campaign enrollment',
            CampaignContactResultOperation::REENROLL_FROM_MESSAGE => 'Campaign re-enrollment',
            CampaignContactResultOperation::EXCLUDE_ALLOCATION_MESSAGE => 'Message exclusion',
            CampaignContactResultOperation::REMOVE_ALLOCATION_MESSAGE_EXCLUSION => 'Message exclusion removal',
            CampaignContactResultOperation::ENROLL_WITH_ALLOCATION_MESSAGE_EXCLUSION =>
                'Campaign enrollment with message exclusion',
        };
    }
}