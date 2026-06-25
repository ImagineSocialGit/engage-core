<x-layouts.crm
    :title="config('contacts.labels.singular').' Detail'"
    :heading="config('contacts.labels.singular').' Detail'"
    :subheading="config('contacts.labels.singular').' record'"
>
    <div class="space-y-6">
        <div class="grid gap-6 {{ $contactPanels->isNotEmpty() ? 'lg:grid-cols-3' : '' }}">
            <div class="{{ $contactPanels->isNotEmpty() ? 'lg:col-span-2' : '' }}">
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
                            {{ module_enabled('workflow') ? ($contact->workflowProfile?->contactStatus?->name ?? '—') : '—' }}
                        </p>
                    </div>
                </x-ui.card>
            </div>

            @if($contactPanels->isNotEmpty())
                <div class="space-y-6">
                    @foreach($contactPanels as $contactPanel)
                        @include($contactPanel->view, $contactPanel->data + [
                            'contact' => $contact,
                            'contactPanel' => $contactPanel,
                        ])
                    @endforeach
                </div>
            @endif
        </div>

        <div
            class="grid gap-6"
            x-data="{
                tab: 'notes',
                taskModalOpen: @js($errors->has('assigned_to_id') || $errors->has('title') || $errors->has('description') || $errors->has('due_at')),
            }"
        >
            <x-ui.card class="space-y-4">
                <div class="flex items-center justify-between gap-4">
                    <h3 class="text-lg font-semibold tracking-tight">
                        Activity
                    </h3>

                    <div class="flex rounded-xl bg-slate-100 p-1 text-sm font-semibold">
                        <button
                            type="button"
                            x-on:click="tab = 'notes'"
                            class="rounded-lg px-3 py-1.5"
                            x-bind:class="tab === 'notes' ? 'bg-white text-slate-900 shadow-sm' : 'text-slate-500 hover:text-slate-700'"
                        >
                            Notes
                        </button>

                        @if(module_enabled('tasks'))
                            <button
                                type="button"
                                x-on:click="tab = 'tasks'"
                                class="rounded-lg px-3 py-1.5"
                                x-bind:class="tab === 'tasks' ? 'bg-white text-slate-900 shadow-sm' : 'text-slate-500 hover:text-slate-700'"
                            >
                                Tasks
                            </button>
                        @endif
                    </div>
                </div>

                <div x-show="tab === 'notes'" class="space-y-4">
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
                            <x-ui.form.label for="body">
                                Note
                            </x-ui.form.label>

                            <x-ui.form.textarea
                                id="body"
                                name="body"
                                rows="4"
                            >{{ old('body') }}</x-ui.form.textarea>

                            @error('body')
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
                                            {{ $note->body }}
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

                                    <x-ui.form.textarea name="body" rows="3">{{ old('body', $note->body) }}</x-ui.form.textarea>

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
                </div>

                @if(module_enabled('tasks'))
                    <div x-show="tab === 'tasks'" class="space-y-4">
                        <div class="flex items-center justify-between gap-4">
                            <h3 class="text-lg font-semibold tracking-tight">
                                Tasks
                            </h3>

                            <x-ui.button
                                type="button"
                                x-on:click="taskModalOpen = true"
                            >
                                Add Task
                            </x-ui.button>
                        </div>

                        <x-crm.contacts.task-list
                            :tasks="$tasks"
                            :archived-tasks="$archivedTasks"
                            :task-view="$taskView"
                        />
                    </div>
                @endif
            </x-ui.card>

            @if(module_enabled('tasks'))
                <x-crm.contacts.create-task-modal
                    :contact="$contact"
                    :team-members="$teamMembers"
                    :current-team-member="$currentTeamMember"
                />
            @endif
        </div>

        @if(module_enabled('messaging'))
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
                                        {{ data_get($message->meta, 'queue') ? str(data_get($message->meta, 'queue'))->replace('_', ' ')->title() : '—' }}
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
        @endif
    </div>
</x-layouts.crm>