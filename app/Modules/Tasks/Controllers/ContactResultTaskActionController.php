<?php

namespace App\Modules\Tasks\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Core\Services\Contacts\ContactResultSetResolver;
use App\Modules\Tasks\Jobs\CreateTaskForContactResultChunkJob;
use App\Modules\Tasks\Models\TaskTemplate;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

final class ContactResultTaskActionController extends Controller
{
    private const CHUNK_SIZE = 250;

    public function __invoke(Request $request, ContactResultSetResolver $resultSets): RedirectResponse
    {
        $validated = $request->validate(array_merge($resultSets->validationRules(), [
            'task_template_id' => [
                'required',
                'integer',
                Rule::exists('task_templates', 'id')->where(fn ($query) => $query->where('is_active', true)),
            ],
        ]));
        $actor = $request->user();

        if (! $actor instanceof User) {
            abort(403);
        }

        $template = TaskTemplate::query()->active()->findOrFail($validated['task_template_id']);
        $payload = $resultSets->normalizeForInput($validated['contact_result']);
        $ids = $resultSets->visibleIds($payload, $actor);
        $returnQuery = $resultSets->contactIndexQuery($payload);

        if ($ids === []) {
            return redirect()->route('crm.contacts.index', $returnQuery)
                ->with('error', 'No visible Contacts matched this result set.');
        }

        $operationId = (string) Str::uuid();

        foreach (array_chunk($ids, self::CHUNK_SIZE) as $chunk) {
            CreateTaskForContactResultChunkJob::dispatch(
                contactIds: $chunk,
                taskTemplateId: (int) $template->getKey(),
                taskTemplateKey: $template->key,
                actorUserId: (int) $actor->getKey(),
                operationId: $operationId,
            );
        }

        return redirect()->route('crm.contacts.index', $returnQuery)
            ->with('success', 'Task creation queued for '.number_format(count($ids)).' Contact(s).');
    }
}