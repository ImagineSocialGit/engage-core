<form method="POST" action="{{ route('crm.tasks.contact-results.store') }}" class="space-y-3">
    @csrf

    <input type="hidden" name="contact_result[search]" value="{{ $contactResultPayload['search'] ?? '' }}">
    @foreach(($contactResultPayload['criteria'] ?? []) as $criterion => $values)
        @foreach($values as $value)
            <input type="hidden" name="contact_result[criteria][{{ $criterion }}][]" value="{{ $value }}">
        @endforeach
    @endforeach

    <div>
        <p class="text-sm font-semibold text-slate-900">{{ $contactResultAction->label }}</p>
        <p class="mt-1 text-xs leading-5 text-slate-500">{{ $contactResultAction->description }}</p>
    </div>

    @if($contactResultAction->data['templates']->isEmpty())
        <div class="rounded-xl border border-dashed border-slate-300 bg-slate-50 p-3 text-xs leading-5 text-slate-600">
            Create and activate a Task Template before adding Tasks to a result set.
            <a href="{{ route('crm.tasks.templates.create') }}" class="font-semibold underline underline-offset-4">Create template</a>
        </div>
    @else
        <div>
            <label for="result-task-template" class="text-xs font-semibold text-slate-700">Task Template</label>
            <select id="result-task-template" name="task_template_id" required class="mt-1 block w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm">
                <option value="">Choose a template</option>
                @foreach($contactResultAction->data['templates'] as $template)
                    <option value="{{ $template['id'] }}">{{ $template['name'] }} — {{ $template['due'] }} — {{ $template['assignment'] }}</option>
                @endforeach
            </select>
            <x-ui.form.error name="task_template_id" />
        </div>

        <x-ui.button type="submit">Create {{ number_format($contactResultCount) }} Task(s)</x-ui.button>
    @endif
</form>