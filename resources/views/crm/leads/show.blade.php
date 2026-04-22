<x-layouts.crm title="Lead Detail" heading="Lead Detail" subheading="Lead record">
    <div class="space-y-6">
        <div class="grid gap-6 lg:grid-cols-3">
            <div class="lg:col-span-2">
                <x-ui.card class="space-y-4">
                    <div>
                        <p class="text-sm text-slate-500">Lead Name</p>
                        <h2 class="text-2xl font-semibold tracking-tight">{{ $lead->name }}</h2>
                    </div>

                    <div class="grid gap-4 sm:grid-cols-2">
                        <div>
                            <p class="text-sm text-slate-500">Email</p>
                            <p class="font-medium text-slate-900">{{ $lead->email }}</p>
                        </div>

                        <div>
                            <p class="text-sm text-slate-500">Phone</p>
                            <p class="font-medium text-slate-900">{{ $lead->phone ?: '—' }}</p>
                        </div>
                    </div>

                    <div>
                        <p class="text-sm text-slate-500">Status</p>
                        <p class="font-medium text-slate-900">{{ ucfirst($lead->status) }}</p>
                    </div>

                    <div>
                        <p class="text-sm text-slate-500">Notes</p>
                        <p class="mt-1 text-slate-700">{{ $lead->notes ?: '—' }}</p>
                    </div>
                </x-ui.card>
            </div>

            <div>
                <x-ui.card class="space-y-3">
                    <h3 class="text-lg font-semibold tracking-tight">Webinar History</h3>

                    @forelse ($lead->registrations as $registration)
                        <div class="rounded-xl border border-slate-200 p-3 space-y-2">
                            <p class="font-medium text-slate-900">
                                {{ $registration->webinar?->title ?? $registration->webinar_slug }}
                            </p>

                            <p class="text-sm text-slate-500">
                                {{ $registration->registered_at?->setTimezone($registration->webinar->timezone)->format('M j, Y g:i A') }}
                            </p>

                            <p class="text-sm">
                                @if($registration->converted_at)
                                    <span class="text-green-600 font-medium">Converted</span>
                                @elseif($registration->attended_at)
                                    <span class="text-blue-600 font-medium">Attended</span>
                                @else
                                    <span class="text-slate-500">Registered</span>
                                @endif
                            </p>

                            @if(! $registration->converted_at)
                                <form method="POST" action="/leads/{{ $lead->id }}/registrations/{{ $registration->id }}/convert">
                                    @csrf
                                    @method('PATCH')

                                    <button class="text-xs font-semibold text-indigo-600 hover:underline">
                                        Mark Converted
                                    </button>
                                </form>
                            @else
                                <p class="text-xs text-slate-400">
                                    {{ $registration->converted_at->format('M j, Y') }}
                                </p>
                            @endif
                        </div>
                    @empty
                        <p class="text-sm text-slate-500">No webinar registrations yet.</p>
                    @endforelse
                </x-ui.card>
            </div>
        </div>

        <div class="grid gap-6 lg:grid-cols-2">
            <x-ui.card class="space-y-4">
                <h3 class="text-lg font-semibold tracking-tight">Add Note</h3>

                <form method="POST" action="/leads/{{ $lead->id }}/notes" class="space-y-4">
                    @csrf

                    <div>
                        <x-ui.form.label for="body">Note</x-ui.form.label>
                        <x-ui.form.textarea id="body" name="body" rows="4">{{ old('body') }}</x-ui.form.textarea>
                        @error('body') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                    </div>

                    <x-ui.button type="submit">Save Note</x-ui.button>
                </form>

                <div class="space-y-3 border-t border-slate-200 pt-4">
                    @forelse ($lead->notes as $note)
                        <div class="rounded-xl border border-slate-200 p-3">
                            <p class="text-slate-800">{{ $note->body }}</p>
                            <p class="mt-2 text-xs text-slate-500">{{ $note->created_at->format('M j, Y g:i A') }}</p>
                        </div>
                    @empty
                        <p class="text-sm text-slate-500">No notes yet.</p>
                    @endforelse
                </div>
            </x-ui.card>

            <x-ui.card class="space-y-4">
                <h3 class="text-lg font-semibold tracking-tight">Add Task</h3>

                <form method="POST" action="/leads/{{ $lead->id }}/tasks" class="space-y-4">
                    @csrf

                    <div>
                        <x-ui.form.label for="title">Task</x-ui.form.label>
                        <x-ui.form.input id="title" name="title" :value="old('title')" />
                        @error('title') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <x-ui.form.label for="due_at">Due At</x-ui.form.label>
                        <x-ui.form.input id="due_at" name="due_at" type="datetime-local" :value="old('due_at')" />
                        @error('due_at') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                    </div>

                    <x-ui.button type="submit">Create Task</x-ui.button>
                </form>

                <div class="space-y-3 border-t border-slate-200 pt-4">
                    @forelse ($lead->tasks as $task)
                        <div class="rounded-xl border border-slate-200 p-3">
                            <p class="font-medium text-slate-900">{{ $task->title }}</p>
                            <p class="mt-1 text-sm text-slate-500">{{ ucfirst($task->status) }}</p>
                            <p class="mt-1 text-xs text-slate-500">
                                Due: {{ $task->due_at ? $task->due_at->format('M j, Y g:i A') : '—' }}
                            </p>

                            <div class="mt-3">
                                @if ($task->status !== 'completed')
                                    <form method="POST" action="/leads/{{ $lead->id }}/tasks/{{ $task->id }}/complete">
                                        @csrf
                                        @method('PATCH')
                                        <x-ui.button type="submit" variant="secondary">Mark Complete</x-ui.button>
                                    </form>
                                @else
                                    <form method="POST" action="/leads/{{ $lead->id }}/tasks/{{ $task->id }}/reopen">
                                        @csrf
                                        @method('PATCH')
                                        <x-ui.button type="submit" variant="ghost">Reopen</x-ui.button>
                                    </form>
                                @endif
                            </div>
                        </div>
                    @empty
                        <p class="text-sm text-slate-500">No tasks yet.</p>
                    @endforelse
                </div>
            </x-ui.card>
        </div>
    </div>
</x-layouts.crm>