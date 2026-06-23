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

                            $isConverted = filled($contact->converted_at);
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
                                    {{ $contact->converted_at->setTimezone($timezone)->format('M j, Y') }}
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

                @php
                    $initialAssignedToId = (string) old('assigned_to_id', '');
                    $currentTeamMemberId = $currentTeamMember ? (string) $currentTeamMember->id : null;

                    $initialNotifyAssignee = old('notify_assignee');

                    $shouldInitiallyNotify = $initialNotifyAssignee !== null
                        ? (bool) $initialNotifyAssignee
                        : ($initialAssignedToId !== '' && $initialAssignedToId !== $currentTeamMemberId);
                @endphp

                <form
                    method="POST"
                    action="{{ route('crm.contacts.tasks.store', $contact) }}"
                    class="space-y-4"
                    x-data="{
                        assignedToId: @js($initialAssignedToId),
                        currentTeamMemberId: @js($currentTeamMemberId),
                        notifyAssignee: @js($shouldInitiallyNotify),
                        updateNotifyAssigneeDefault() {
                            this.notifyAssignee = this.assignedToId !== ''
                                && this.assignedToId !== this.currentTeamMemberId;
                        },
                    }"
                >
                    @csrf

                    <div>
                        <x-ui.form.label for="assigned_to_id">
                            Assigned To
                        </x-ui.form.label>

                        <x-ui.form.select
                            id="assigned_to_id"
                            name="assigned_to_id"
                            x-model="assignedToId"
                            x-on:change="updateNotifyAssigneeDefault"
                        >
                            <option value="">Select team member...</option>

                            @foreach ($teamMembers as $teamMember)
                                <option
                                    value="{{ $teamMember->id }}"
                                    @selected((string) old('assigned_to_id') === (string) $teamMember->id)
                                >
                                    {{ $teamMember->name }}
                                    @if ($teamMember->email)
                                        — {{ $teamMember->email }}
                                    @endif
                                </option>
                            @endforeach
                        </x-ui.form.select>

                        @error('assigned_to_id')
                            <p class="mt-1 text-sm text-red-600">
                                {{ $message }}
                            </p>
                        @enderror
                    </div>

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
                        <x-ui.form.label for="description">
                            Description
                        </x-ui.form.label>

                        <x-ui.form.textarea
                            id="description"
                            name="description"
                            rows="3"
                        >{{ old('description') }}</x-ui.form.textarea>

                        @error('description')
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

                    <input
                        type="hidden"
                        name="notify_assignee"
                        value="0"
                    >

                    <label class="flex items-start gap-3 rounded-xl border border-slate-200 p-3">
                        <input
                            type="checkbox"
                            name="notify_assignee"
                            value="1"
                            x-model="notifyAssignee"
                            class="mt-1 rounded border-slate-300 text-indigo-600 shadow-sm focus:ring-indigo-500"
                        >

                        <span>
                            <span class="block text-sm font-semibold text-slate-900">
                                Notify assignee
                            </span>

                            <span class="block text-sm text-slate-500">
                                Send an internal task assignment notification based on the assignee’s notification preferences.
                            </span>
                        </span>
                    </label>

                    <x-ui.button type="submit">
                        Create Task
                    </x-ui.button>
                </form>

                <div class="space-y-3 border-t border-slate-200 pt-4">
                    @forelse ($contact->tasks as $task)
                        <div class="rounded-xl border border-slate-200 p-3">
                            <div class="flex items-start justify-between gap-4">
                                <div>
                                    <p class="font-medium text-slate-900">
                                        {{ $task->title }}
                                    </p>

                                    <p class="mt-1 text-sm text-slate-500">
                                        Assigned to:
                                        <span class="font-medium text-slate-700">
                                            {{ $task->assignedTo?->name ?? '—' }}
                                        </span>
                                    </p>
                                </div>

                                <span class="rounded-full px-2.5 py-1 text-xs font-semibold {{ $task->status === 'completed' ? 'bg-green-50 text-green-700' : 'bg-blue-50 text-blue-700' }}">
                                    {{ str($task->status)->replace('_', ' ')->title() }}
                                </span>
                            </div>

                            @if ($task->description)
                                <p class="mt-3 text-sm text-slate-700">
                                    {{ $task->description }}
                                </p>
                            @endif

                            <p class="mt-3 text-xs text-slate-500">
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

        <div
            x-data="{ tab: new URLSearchParams(window.location.search).get('messages_tab') || 'messages' }"
        >
            <x-ui.card class="space-y-4">
                <div>
                    <h3 class="text-lg font-semibold tracking-tight">
                        Messages
                    </h3>

                    <p class="text-sm text-slate-500">
                        Sent messages and consent settings for this {{ config('contacts.labels.singular') }}.
                    </p>
                </div>

                <div class="border-b border-slate-200">
                    <nav class="-mb-px flex gap-6" aria-label="Tabs">
                        <button
                            type="button"
                            x-on:click="tab = 'messages'"
                            class="border-b-2 px-1 pb-3 text-sm font-semibold"
                            x-bind:class="tab === 'messages'
                                ? 'border-indigo-600 text-indigo-600'
                                : 'border-transparent text-slate-500 hover:border-slate-300 hover:text-slate-700'"
                        >
                            Messages
                        </button>

                        <button
                            type="button"
                            x-on:click="tab = 'consents'"
                            class="border-b-2 px-1 pb-3 text-sm font-semibold"
                            x-bind:class="tab === 'consents'
                                ? 'border-indigo-600 text-indigo-600'
                                : 'border-transparent text-slate-500 hover:border-slate-300 hover:text-slate-700'"
                        >
                            Consent Settings
                        </button>
                    </nav>
                </div>

                <div x-show="tab === 'messages'" class="space-y-3">
                    @forelse ($scheduledMessages as $message)
                        <div class="rounded-xl border border-slate-200 p-3">
                            <div class="flex items-start justify-between gap-4">
                                <div>
                                    <p class="font-medium text-slate-900">
                                        {{ str($message->message_type)->replace('_', ' ')->title() }}
                                    </p>

                                    <p class="mt-1 text-sm text-slate-500">
                                        {{ str($message->channel)->replace('_', ' ')->title() }}
                                        <span class="text-slate-400">/</span>
                                        {{ str($message->purpose)->replace('_', ' ')->title() }}
                                        <span class="text-slate-400">/</span>
                                        {{ str($message->scope)->replace('_', ' ')->title() }}
                                    </p>
                                </div>

                                <span class="rounded-full bg-blue-50 px-2.5 py-1 text-xs font-semibold text-blue-700">
                                    {{ str($message->status)->replace('_', ' ')->title() }}
                                </span>
                            </div>

                            <div class="mt-3 grid gap-2 text-xs text-slate-500 sm:grid-cols-3">
                                <p>
                                    Sent:
                                    <span class="font-medium text-slate-700">
                                        {{ $message->send_at?->format('M j, Y g:i A') ?? '—' }}
                                    </span>
                                </p>

                                <p>
                                    Queue:
                                    <span class="font-medium text-slate-700">
                                        {{ $message->queue ? str($message->queue)->replace('_', ' ')->title() : '—' }}
                                    </span>
                                </p>
                            </div>
                        </div>
                    @empty
                        <p class="text-sm text-slate-500">
                            No sent messages yet.
                        </p>
                    @endforelse

                    @if ($scheduledMessages->hasPages())
                        <div class="pt-2">
                            {{ $scheduledMessages->appends(['messages_tab' => 'messages'])->links() }}
                        </div>
                    @endif
                </div>

                <div x-show="tab === 'consents'" class="space-y-4">
                    <div class="space-y-3">
                        @forelse ($contact->messageConsents as $consent)
                            <div class="rounded-xl border border-slate-200 p-3">
                                <div class="flex items-start justify-between gap-4">
                                    <div>
                                        <p class="font-medium text-slate-900">
                                            {{ str($consent->channel->value)->replace('_', ' ')->title() }}
                                            <span class="text-slate-400">/</span>
                                            {{ str($consent->purpose->value)->replace('_', ' ')->title() }}
                                        </p>

                                        <p class="mt-1 text-sm text-slate-500">
                                            Scope:
                                            <span class="font-medium text-slate-700">
                                                {{ str($consent->scope)->replace('_', ' ')->title() }}
                                            </span>
                                        </p>
                                    </div>

                                    <span class="rounded-full bg-green-50 px-2.5 py-1 text-xs font-semibold text-green-700">
                                        Consented
                                    </span>
                                </div>

                                <div class="mt-3 grid gap-2 text-xs text-slate-500 sm:grid-cols-2">
                                    <p>
                                        Consented:
                                        <span class="font-medium text-slate-700">
                                            {{ $consent->consented_at?->format('M j, Y g:i A') ?? '—' }}
                                        </span>
                                    </p>

                                    <p>
                                        Source:
                                        <span class="font-medium text-slate-700">
                                            {{ $consent->source ? str($consent->source)->replace('_', ' ')->title() : '—' }}
                                        </span>
                                    </p>
                                </div>
                            </div>
                        @empty
                            <p class="text-sm text-slate-500">
                                No active message consents.
                            </p>
                        @endforelse
                    </div>

                    <div class="border-t border-slate-200 pt-4">
                        <h4 class="text-sm font-semibold text-slate-900">
                            Revocation History
                        </h4>

                        <div class="mt-3 space-y-3">
                            @forelse ($contact->consentRevocations as $revocation)
                                <div class="rounded-xl border border-slate-200 p-3">
                                    <div class="flex items-start justify-between gap-4">
                                        <div>
                                            <p class="font-medium text-slate-900">
                                                {{ str($revocation->channel->value)->replace('_', ' ')->title() }}
                                                <span class="text-slate-400">/</span>
                                                {{ str($revocation->purpose->value)->replace('_', ' ')->title() }}
                                            </p>

                                            <p class="mt-1 text-sm text-slate-500">
                                                Scope:
                                                <span class="font-medium text-slate-700">
                                                    {{ str($revocation->scope)->replace('_', ' ')->title() }}
                                                </span>
                                            </p>
                                        </div>

                                        <span class="rounded-full bg-red-50 px-2.5 py-1 text-xs font-semibold text-red-700">
                                            Revoked
                                        </span>
                                    </div>

                                    <div class="mt-3 grid gap-2 text-xs text-slate-500 sm:grid-cols-3">
                                        <p>
                                            Revoked:
                                            <span class="font-medium text-slate-700">
                                                {{ $revocation->revoked_at?->format('M j, Y g:i A') ?? '—' }}
                                            </span>
                                        </p>

                                        <p>
                                            Reason:
                                            <span class="font-medium text-slate-700">
                                                {{ $revocation->reason ? str($revocation->reason)->replace('_', ' ')->title() : '—' }}
                                            </span>
                                        </p>

                                        <p>
                                            Source:
                                            <span class="font-medium text-slate-700">
                                                {{ $revocation->source ? str($revocation->source)->replace('_', ' ')->title() : '—' }}
                                            </span>
                                        </p>
                                    </div>
                                </div>
                            @empty
                                <p class="text-sm text-slate-500">
                                    No consent revocations.
                                </p>
                            @endforelse
                        </div>
                    </div>
                </div>
            </x-ui.card>
        </div>
    </div>
</x-layouts.crm>