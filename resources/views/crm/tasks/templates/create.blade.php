<x-layouts.crm
    :title="$title"
    :heading="$heading"
    subheading="Create reusable work instructions, timing, and assignment defaults."
    module="tasks"
>
    <div class="space-y-6">
        <a href="{{ route('crm.tasks.templates.index') }}" class="text-sm font-semibold text-slate-600 underline underline-offset-4 hover:text-slate-900">
            Back to Task Templates
        </a>

        @include('crm.tasks.templates.partials.form', [
            'formAction' => route('crm.tasks.templates.store'),
            'formMethod' => 'POST',
            'submitLabel' => 'Create Task Template',
        ])
    </div>
</x-layouts.crm>