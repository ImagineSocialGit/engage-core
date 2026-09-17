<?php

namespace App\Modules\Messaging\Controllers\CRM;

use App\Http\Controllers\Controller;
use App\Modules\Core\Access\Services\UserAccessService;
use App\Modules\Messaging\Actions\EditScheduledMessageBulkContentAction;
use App\Modules\Messaging\Models\MessageTemplateVersion;
use App\Modules\Messaging\Models\ScheduledMessage;
use App\Modules\Messaging\Models\ScheduledMessageBulkEdit;
use App\Modules\Messaging\Payloads\EmailPayload;
use App\Modules\Messaging\Payloads\SmsPayload;
use App\Modules\Messaging\Services\OutboundMessageIndex;
use App\Modules\Messaging\Services\ScheduledMessageBulkContentRules;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class BulkOutboundMessageController extends Controller
{
    public function index(
        Request $request,
        OutboundMessageIndex $index,
        UserAccessService $access,
    ): View {
        $input = $request->validate([
            'scope' => ['required', 'string', Rule::in(['webinar', 'webinar_series', 'campaign'])],
            'scope_id' => ['required', 'integer', 'min:1'],
            'template_id' => ['nullable', 'integer', 'min:1'],
            'embedded' => ['nullable', 'boolean'],
        ]);
        abort_unless($access->allows($request->user(), 'contacts.view_all')
            && $access->allows($request->user(), 'contacts.manage')
            && module_enabled($input['scope'] === 'campaign' ? 'campaigns' : 'webinars'), 403);

        $scope = $input['scope'];
        $sourceId = (int) $input['scope_id'];
        $pending = $index->query($request->user(), [
            'scope' => $scope, 'scope_id' => $sourceId, 'period' => 'all',
        ])->where('status', ScheduledMessage::STATUS_PENDING)
            ->whereIn('operational_state', [
                ScheduledMessage::OPERATIONAL_ACTIVE,
                ScheduledMessage::OPERATIONAL_HELD,
            ])->whereDoesntHave('components')
            ->where('scope', '!=', 'permission_invitation')
            ->where('message_type', '!=', 'imported_contact_permission_invitation')
            ->where(function (\Illuminate\Database\Eloquent\Builder $supported): void {
                $supported->where(fn (\Illuminate\Database\Eloquent\Builder $email) => $email
                    ->where('channel', 'email')
                    ->where('payload_class', EmailPayload::class))
                    ->orWhere(fn (\Illuminate\Database\Eloquent\Builder $sms) => $sms
                        ->where('channel', 'sms')
                        ->where('payload_class', SmsPayload::class));
            })
            ->whereNotNull('message_template_version_id');

        $options = (clone $pending)
            ->select('message_template_version_id', 'channel')
            ->selectRaw('COUNT(*) as matching_count')
            ->groupBy('message_template_version_id', 'channel')
            ->orderByDesc('matching_count')
            ->limit(100)
            ->get();
        $versionId = (int) ($input['template_id'] ?? $options->first()?->message_template_version_id);
        $selected = $options->first(
            fn ($row): bool => (int) $row->message_template_version_id === $versionId,
        );
        $template = $selected ? MessageTemplateVersion::query()->findOrFail($versionId) : null;
        $latest = $selected ? ScheduledMessageBulkEdit::query()
            ->where('source_scope', $scope)
            ->where('source_id', $sourceId)
            ->where('message_template_version_id', $versionId)
            ->latest('id')->first() : null;
        $base = $template?->payload() ?? [];
        $fields = array_replace($base, is_array($latest?->override_payload)
            ? $latest->override_payload
            : []);

        return view('crm.messaging.outbound.bulk', [
            'scope' => $scope,
            'sourceId' => $sourceId,
            'options' => $options,
            'selected' => $selected,
            'templateId' => $versionId,
            'fields' => $fields,
            'latest' => $latest,
            'embedded' => $request->boolean('embedded'),
            'backUrl' => route('crm.messaging.outbound.index', [
                'scope' => $scope,
                'scope_id' => $sourceId,
                'period' => 'upcoming',
                'embedded' => $request->boolean('embedded') ? 1 : null,
            ]),
        ]);
    }

    public function save(
        Request $request,
        EditScheduledMessageBulkContentAction $edits,
    ): RedirectResponse {
        $input = $request->validate([
            'scope' => ['required', 'string', Rule::in(['webinar', 'webinar_series', 'campaign'])],
            'scope_id' => ['required', 'integer', 'min:1'],
            'template_id' => ['required', 'integer', 'min:1'],
            'action' => ['required', 'string', Rule::in(['save', 'clear'])],
            'subject' => ['nullable', 'string', 'max:998'],
            'body' => ['nullable', 'string', 'max:32768'],
            'message' => ['nullable', 'string', 'max:4096'],
            'reason' => ['nullable', 'string', 'max:1000'],
            'embedded' => ['nullable', 'boolean'],
        ]);

        if ($input['action'] === 'clear') {
            $edits->clear(
                $request->user(), $input['scope'], (int) $input['scope_id'],
                (int) $input['template_id'], $input['reason'] ?? null,
            );
        } else {
            $edits->save(
                $request->user(), $input['scope'], (int) $input['scope_id'],
                (int) $input['template_id'], $input, $input['reason'] ?? null,
            );
        }

        return redirect()->route('crm.messaging.outbound.bulk.index', [
            'scope' => $input['scope'],
            'scope_id' => $input['scope_id'],
            'template_id' => $input['template_id'],
            'embedded' => $request->boolean('embedded') ? 1 : null,
        ])->with('success', 'Bulk content rule saved.');
    }
}