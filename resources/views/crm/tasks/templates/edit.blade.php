<x-layouts.crm
    :title="$title"
    :heading="$heading"
    subheading="Change the reusable Task defaults used by automations."
    module="tasks"
>
    <div class="space-y-6">
        @if(session('success'))
            <x-ui.feedback.alert type="success">{{ session('success') }}</x-ui.feedback.alert>
        @endif

        <div class="flex items-center justify-between gap-3">
            <a href="{{ route('crm.tasks.templates.index') }}" class="text-sm font-semibold text-slate-600 underline underline-offset-4 hover:text-slate-900">
                Back to Task Templates
            </a>
            <span class="text-xs font-medium text-slate-500">{{ $taskTemplate->key }}</span>
        </div>

        @include('crm.tasks.templates.partials.form', [
            'formAction' => route('crm.tasks.templates.update', $taskTemplate),
            'formMethod' => 'PATCH',
            'submitLabel' => 'Save Task Template',
        ])
    </div>
</x-layouts.crm>