<div class="mx-auto max-w-4xl space-y-5">
    <a href="{{ $backUrl }}" class="text-sm font-semibold text-slate-700 underline">&larr; Back to outbound messages</a>
    <header>
        <h1 class="text-xl font-semibold text-slate-950">Bulk edit messages for this source</h1>
        <p class="mt-2 text-sm text-slate-600">Choose one pinned message template. The change applies to matching existing pending messages for this source, including messages beyond the first page. Messages with separately appended components are excluded. New messages created later keep their normal template.</p>
    </header>
    @if(session('success'))
        <div class="rounded-xl border border-emerald-200 bg-emerald-50 p-3 text-sm text-emerald-900">{{ session('success') }}</div>
    @endif
    @if($errors->any())
        <div class="rounded-xl border border-red-200 bg-red-50 p-3 text-sm text-red-900">{{ $errors->first() }}</div>
    @endif
    <form method="GET" action="{{ route('crm.messaging.outbound.bulk.index') }}" class="rounded-2xl border border-slate-200 bg-white p-5">
        <input type="hidden" name="scope" value="{{ $scope }}">
        <input type="hidden" name="scope_id" value="{{ $sourceId }}">
        @if($embedded)<input type="hidden" name="embedded" value="1">@endif
        <label class="block text-sm font-medium text-slate-700">Message template and channel
            <select name="template_id" class="mt-1 block w-full rounded-lg border-slate-300" onchange="this.form.submit()">
                @foreach($options as $option)
                    <option value="{{ $option->message_template_version_id }}" @selected((int) $option->message_template_version_id === $templateId)>
                        {{ strtoupper($option->channel) }} · Template #{{ $option->message_template_version_id }} · {{ number_format($option->matching_count) }} pending
                    </option>
                @endforeach
            </select>
        </label>
    </form>
    @if($selected)
        <p class="text-sm text-slate-700">{{ number_format($selected->matching_count) }} matching pending messages. Individual message edits keep priority. Messages already claimed for sending cannot be changed.</p>
        <form method="POST" action="{{ route('crm.messaging.outbound.bulk.save') }}" class="space-y-4 rounded-2xl border border-slate-200 bg-white p-5" onsubmit="return confirm('Apply this content to all matching pending messages for this source and template?')">
            @csrf
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="scope" value="{{ $scope }}">
            <input type="hidden" name="scope_id" value="{{ $sourceId }}">
            <input type="hidden" name="template_id" value="{{ $templateId }}">
            @if($embedded)<input type="hidden" name="embedded" value="1">@endif
            @if($selected->channel === 'email')
                <label class="block text-sm font-medium text-slate-700">Subject
                    <input name="subject" value="{{ $fields['subject'] ?? '' }}" maxlength="998" required class="mt-1 block w-full rounded-lg border-slate-300">
                </label>
                <label class="block text-sm font-medium text-slate-700">Body
                    <textarea name="body" rows="10" maxlength="32768" required class="mt-1 block w-full rounded-lg border-slate-300">{{ $fields['body'] ?? '' }}</textarea>
                </label>
            @else
                <label class="block text-sm font-medium text-slate-700">Text
                    <textarea name="message" rows="6" maxlength="4096" required class="mt-1 block w-full rounded-lg border-slate-300">{{ $fields['message'] ?? '' }}</textarea>
                </label>
            @endif
            <button class="rounded-lg bg-slate-900 px-4 py-2 text-sm font-semibold text-white">Apply bulk edit</button>
        </form>
        @if($latest && $latest->override_payload !== [])
            <form method="POST" action="{{ route('crm.messaging.outbound.bulk.save') }}" class="rounded-2xl border border-slate-200 bg-white p-5" onsubmit="return confirm('Clear the bulk edit for still-pending messages?')">
                @csrf
                <input type="hidden" name="action" value="clear">
                <input type="hidden" name="scope" value="{{ $scope }}">
                <input type="hidden" name="scope_id" value="{{ $sourceId }}">
                <input type="hidden" name="template_id" value="{{ $templateId }}">
                @if($embedded)<input type="hidden" name="embedded" value="1">@endif
                <button class="text-sm font-semibold text-slate-700 underline">Clear bulk edit</button>
            </form>
        @endif
    @else
        <p class="rounded-xl border border-slate-200 bg-white p-5 text-sm text-slate-600">There are no eligible pending, pinned-template messages for this source.</p>
    @endif
</div>