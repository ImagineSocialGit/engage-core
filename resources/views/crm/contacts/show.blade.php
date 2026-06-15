<x-layouts.crm
    :title="config('contacts.labels.singular').' Detail'"
    :heading="config('contacts.labels.singular').' Detail'"
    :subheading="config('contacts.labels.singular').' record'"
>
    <div class="space-y-6">
        <div class="grid gap-6 lg:grid-cols-3">
            <div class="lg:col-span-2">
                <x-ui.card class="space-y-4">
                    <div>
                        <p class="text-sm text-slate-500 capitalize">
                            {{ config('contacts.labels.singular') }} Name
                        </p>

                        <h2 class="text-2xl font-semibold tracking-tight">
                            {{ $contact->first_name }} {{ $contact->last_name }}
                        </h2>
                    </div>

                    <div class="grid gap-4 sm:grid-cols-2">
                        <div>
                            <p class="text-sm text-slate-500">Email</p>
                            <p class="font-medium text-slate-900">{{ $contact->email }}</p>
                        </div>

                        <div>
                            <p class="text-sm text-slate-500">Phone</p>
                            <p class="font-medium text-slate-900">
                                {{ $contact->phone ?: '—' }}
                            </p>
                        </div>
                    </div>

                    <div>
                        <p class="text-sm text-slate-500">Status</p>
                        <p class="font-medium text-slate-900">
                            {{ ucfirst($contact->status) }}
                        </p>
                    </div>
                </x-ui.card>
            </div>

            <div>
                <x-ui.card class="space-y-3">
                    <h3 class="text-lg font-semibold tracking-tight">
                        Webinar History
                    </h3>

                    @forelse ($contact->registrations as $registration)
                        @php
                            $webinar = $registration->webinar;
                            $timezone = $webinar?->timezone ?? config('app.timezone');

                            $startsAt = $webinar?->starts_at?->setTimezone($timezone);
                            $endsAt = $webinar?->ends_at?->setTimezone($timezone);
                            $registeredAt = $registration->registered_at?->setTimezone($timezone);

                            $attendanceStatus = data_get($registration->meta, 'attendance.status');

                            $isConverted = filled($registration->converted_at);
                            $isFutureWebinar = $startsAt && $startsAt->isFuture();
                            $isPastWebinar = $endsAt
                                ? $endsAt->isPast()
                                : ($startsAt ? $startsAt->isPast() : false);

                            $didAttend = filled($registration->attended_at)
                                || $attendanceStatus === 'attended';

                            $didMiss = $attendanceStatus === 'missed'
                                || ($isPastWebinar && ! $didAttend);

                            if ($isConverted) {
                                $label = 'Converted';
                                $labelClass = 'text-green-600';
                            } elseif ($didAttend) {
                                $label = 'Attended';
                                $labelClass = 'text-blue-600';
                            } elseif ($didMiss) {
                                $label = 'Missed';
                                $labelClass = 'text-red-600';
                            } else {
                                $label = 'Registered';
                                $labelClass = 'text-slate-500';
                            }
                        @endphp

                        <div class="rounded-xl border border-slate-200 p-3 space-y-2">
                            <p class="font-medium text-slate-900">
                                {{ $webinar?->title ?? $registration->webinar_slug }}@if ($startsAt) : {{ $startsAt->format('M j, Y g:i A') }}@endif
                            </p>

                            <div class="space-y-1 text-sm text-slate-500">
                                <p>
                                    Registered:
                                    {{ $registeredAt?->format('M j, Y g:i A') ?? '—' }}
                                </p>

                                @if ($registration->attended_at)
                                    <p>
                                        Attended:
                                        {{ $registration->attended_at->setTimezone($timezone)->format('M j, Y g:i A') }}
                                    </p>
                                @endif
                            </div>

                            <p class="text-sm">
                                <span class="font-medium {{ $labelClass }}">
                                    {{ $label }}
                                </span>
                            </p>

                            @if (! $isConverted)
                                <form
                                    method="POST"
                                    action="{{ route('crm.contacts.registrations.convert', [$contact, $registration]) }}"
                                >
                                    @csrf
                                    @method('PATCH')

                                    <button class="text-xs font-semibold text-indigo-600 hover:underline">
                                        Mark Converted
                                    </button>
                                </form>
                            @else
                                <p class="text-xs text-slate-400">
                                    Converted:
                                    {{ $registration->converted_at->setTimezone($timezone)->format('M j, Y') }}
                                </p>
                            @endif
                        </div>
                    @empty
                        <p class="text-sm text-slate-500">
                            No webinar registrations yet.
                        </p>
                    @endforelse
                </x-ui.card>
            </div>
        </div>

        <div class="grid gap-6 lg:grid-cols-2">
            <x-ui.card class="space-y-4">
                <h3 class="text-lg font-semibold tracking-tight">
                    Add Note
                </h3>

                <form
                    method="POST"
                    action="{{ route('crm.contacts.notes.store', $contact) }}"
                    class="space-y-4"
                >
                    @csrf

                    <div>
                        <x-ui.form.label for="content">
                            Note
                        </x-ui.form.label>

                        <x-ui.form.textarea
                            id="content"
                            name="content"
                            rows="4"
                        >{{ old('content') }}</x-ui.form.textarea>

                        @error('content')
                            <p class="mt-1 text-sm text-red-600">
                                {{ $message }}
                            </p>
                        @enderror
                    </div>

                    <x-ui.button type="submit">
                        Save Note
                    </x-ui.button>
                </form>

                <div class="space-y-3 border-t border-slate-200 pt-4">
                    @forelse ($contact->notes as $note)
                        <div
                            x-data="{ editing: false }"
                            class="rounded-xl border border-slate-200 p-3"
                        >
                            <div x-show="! editing" class="flex justify-between items-center">
                                <div class="space-y-2">
                                    <p class="text-slate-800">
                                        {{ $note->content }}
                                    </p>

                                    <p class="text-xs text-slate-500">
                                        {{ $note->created_at->format('M j, Y g:i A') }}
                                    </p>
                                </div>

                                <div class="flex space-x-2">
                                    <button
                                        type="button"
                                        x-on:click="editing = true"
                                        class="text-sm font-semibold text-indigo-600 hover:underline cursor-pointer"
                                    >
                                        Edit
                                    </button>

                                    <form
                                        method="POST"
                                        action="{{ route('crm.contacts.notes.destroy', [$contact, $note]) }}"
                                    >
                                        @csrf
                                        @method('DELETE')

                                        <button
                                            type="submit"
                                            class="block text-sm font-semibold text-red-600 hover:underline cursor-pointer"
                                        >
                                            Delete
                                        </button>
                                    </form>
                                </div>
                            </div>

                            <form
                                x-show="editing"
                                method="POST"
                                action="{{ route('crm.contacts.notes.update', [$contact, $note]) }}"
                                class="space-y-3"
                            >
                                @csrf
                                @method('PATCH')

                                <x-ui.form.textarea name="content" rows="3">{{ old('content', $note->content) }}</x-ui.form.textarea>

                                <div class="flex gap-3">
                                    <button
                                        type="submit"
                                        class="text-xs font-semibold text-indigo-600 hover:underline"
                                    >
                                        Save
                                    </button>

                                    <button
                                        type="button"
                                        x-on:click="editing = false"
                                        class="text-xs font-semibold text-slate-500 hover:underline"
                                    >
                                        Cancel
                                    </button>
                                </div>
                            </form>
                        </div>
                    @empty
                        <p class="text-sm text-slate-500">
                            No notes yet.
                        </p>
                    @endforelse
                </div>
            </x-ui.card>

            <x-ui.card class="space-y-4">
                <h3 class="text-lg font-semibold tracking-tight">
                    Add Task
                </h3>

                <form
                    method="POST"
                    action="{{ route('crm.contacts.tasks.store', $contact) }}"
                    class="space-y-4"
                >
                    @csrf

                    <div>
                        <x-ui.form.label for="title">
                            Task
                        </x-ui.form.label>

                        <x-ui.form.input
                            id="title"
                            name="title"
                            :value="old('title')"
                        />

                        @error('title')
                            <p class="mt-1 text-sm text-red-600">
                                {{ $message }}
                            </p>
                        @enderror
                    </div>

                    <div>
                        <x-ui.form.label for="due_at">
                            Due At
                        </x-ui.form.label>

                        <x-ui.form.input
                            id="due_at"
                            name="due_at"
                            type="datetime-local"
                            :value="old('due_at')"
                        />

                        @error('due_at')
                            <p class="mt-1 text-sm text-red-600">
                                {{ $message }}
                            </p>
                        @enderror
                    </div>

                    <x-ui.button type="submit">
                        Create Task
                    </x-ui.button>
                </form>

                <div class="space-y-3 border-t border-slate-200 pt-4">
                    @forelse ($contact->tasks as $task)
                        <div class="rounded-xl border border-slate-200 p-3">
                            <p class="font-medium text-slate-900">
                                {{ $task->title }}
                            </p>

                            <p class="mt-1 text-sm text-slate-500">
                                {{ ucfirst($task->status) }}
                            </p>

                            <p class="mt-1 text-xs text-slate-500">
                                Due:
                                {{ $task->due_at?->format('M j, Y g:i A') ?? '—' }}
                            </p>

                            <div class="mt-3">
                                @if ($task->status !== 'completed')
                                    <form
                                        method="POST"
                                        action="{{ route('crm.contacts.tasks.complete', [$contact, $task]) }}"
                                    >
                                        @csrf
                                        @method('PATCH')

                                        <x-ui.button
                                            type="submit"
                                            variant="secondary"
                                        >
                                            Mark Complete
                                        </x-ui.button>
                                    </form>
                                @else
                                    <form
                                        method="POST"
                                        action="{{ route('crm.contacts.tasks.reopen', [$contact, $task]) }}"
                                    >
                                        @csrf
                                        @method('PATCH')

                                        <x-ui.button
                                            type="submit"
                                            variant="ghost"
                                        >
                                            Reopen
                                        </x-ui.button>
                                    </form>
                                @endif
                            </div>
                        </div>
                    @empty
                        <p class="text-sm text-slate-500">
                            No tasks yet.
                        </p>
                    @endforelse
                </div>
            </x-ui.card>
        </div>
    </div>
</x-layouts.crm>