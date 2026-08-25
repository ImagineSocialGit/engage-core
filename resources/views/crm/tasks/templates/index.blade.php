<x-layouts.crm
    :title="$title"
    :heading="$heading"
    subheading="Review the reusable instructions, timing, ownership, and defaults used by automatic Tasks."
    module="tasks"
>
    <div class="space-y-6">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <a
                href="{{ route('crm.tasks.index') }}"
                class="text-sm font-semibold text-slate-600 underline underline-offset-4 hover:text-slate-900"
            >
                Back to Tasks
            </a>

            <p class="text-sm text-slate-600">
                {{ $templates->count() }} {{ \Illuminate\Support\Str::plural('template', $templates->count()) }}
            </p>
        </div>

        <section class="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm sm:p-8">
            <p class="text-sm font-semibold uppercase tracking-[0.16em] {{ module_tone('tasks', 'text') }}">
                Reusable Task setup
            </p>

            <h2 class="mt-2 text-2xl font-semibold tracking-tight text-slate-950">
                Know exactly what an automation will create
            </h2>

            <p class="mt-3 max-w-3xl text-sm leading-6 text-slate-600">
                A Task Template controls the work instructions, due timing, priority, responsibility, and assignment defaults used each time an automation creates a Task.
            </p>
        </section>

        <section class="space-y-4">
            @forelse($templates as $template)
                <article class="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6">
                    <div class="flex flex-col gap-5 lg:flex-row lg:items-start lg:justify-between">
                        <div class="min-w-0 flex-1">
                            <div class="flex flex-wrap items-center gap-2">
                                <h2 class="text-xl font-semibold tracking-tight text-slate-950">
                                    {{ $template['name'] }}
                                </h2>

                                <span class="rounded-full px-2.5 py-1 text-xs font-semibold {{ $template['is_active'] ? 'bg-emerald-50 text-emerald-800 ring-1 ring-emerald-200' : 'bg-slate-100 text-slate-700 ring-1 ring-slate-200' }}">
                                    {{ $template['is_active'] ? 'Active' : 'Inactive' }}
                                </span>

                                @if($template['is_customized'])
                                    <span class="rounded-full bg-orange-50 px-2.5 py-1 text-xs font-semibold text-orange-800 ring-1 ring-orange-200">
                                        Customized
                                    </span>
                                @endif
                            </div>

                            <p class="mt-2 text-sm font-semibold text-slate-900">
                                {{ $template['title'] }}
                            </p>

                            @if($template['task_description'] || $template['description'])
                                <p class="mt-2 max-w-3xl text-sm leading-6 text-slate-600">
                                    {{ $template['task_description'] ?: $template['description'] }}
                                </p>
                            @endif

                            <dl class="mt-5 grid gap-3 text-sm sm:grid-cols-2 xl:grid-cols-4">
                                @foreach([
                                    'Priority' => $template['priority'],
                                    'Due' => $template['due'],
                                    'Assigned to' => $template['assignment'],
                                    'Responsible party' => $template['responsible_party'],
                                ] as $label => $value)
                                    <div class="rounded-xl bg-slate-50 px-3 py-2 ring-1 ring-slate-200">
                                        <dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ $label }}</dt>
                                        <dd class="mt-1 font-medium text-slate-900">{{ $value }}</dd>
                                    </div>
                                @endforeach
                            </dl>
                        </div>

                        <a
                            href="{{ $template['edit_url'] }}"
                            class="inline-flex w-full shrink-0 items-center justify-center rounded-xl border border-slate-300 bg-white px-4 py-2.5 text-sm font-semibold text-slate-900 shadow-sm transition hover:bg-slate-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-slate-300 sm:w-auto"
                        >
                            View or Edit
                        </a>
                    </div>
                </article>
            @empty
                <div class="rounded-3xl border border-dashed border-slate-300 bg-white p-8 text-center text-sm text-slate-600">
                    No Task Templates are available.
                </div>
            @endforelse
        </section>
    </div>
</x-layouts.crm>