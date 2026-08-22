@php
    $contactSingular = config('contacts.labels.singular');
    $contactName = $contact->name ?: trim($contact->first_name.' '.$contact->last_name) ?: $contact->email ?: str($contactSingular)->title().' #'.$contact->id;
    $currentStatus = module_enabled('workflow') ? $contact->workflowProfile?->contactStatus : null;

    $businessContext = is_array($contactBusinessContext ?? null)
        ? $contactBusinessContext
        : ['primary' => null, 'relationships' => []];
    $primaryBusinessContext = is_array($businessContext['primary'] ?? null)
        ? $businessContext['primary']
        : null;
    $businessRelationships = collect($businessContext['relationships'] ?? [])
        ->filter(fn ($relationship) => is_array($relationship))
        ->values();
    $businessLabel = filled($primaryBusinessContext['label'] ?? null)
        ? $primaryBusinessContext['label']
        : str($contactSingular)->title()->toString();
    $usesRelationshipStage = ($primaryBusinessContext['progression_mode'] ?? null) === 'relationship_stage';
    $showContactStatusProgression = module_enabled('workflow') && ! $usesRelationshipStage;
    $hasProgression = $usesRelationshipStage || module_enabled('workflow');
    $progressionType = $usesRelationshipStage ? 'Stage' : 'Status';
    $progressionLabel = $usesRelationshipStage
        ? ($primaryBusinessContext['stage_label'] ?? 'No stage')
        : ($currentStatus?->name ?? 'No status');
    $businessSource = filled($primaryBusinessContext['source'] ?? null)
        ? $primaryBusinessContext['source']
        : $contact->source;
    $businessSubsource = filled($primaryBusinessContext['subsource'] ?? null)
        ? $primaryBusinessContext['subsource']
        : $contact->subsource;

    $conversationItems = collect($conversationItems ?? [])
        ->filter(fn ($item) => is_array($item))
        ->values();
    $latestInboundReply = is_array($latestInboundReply ?? null)
        ? $latestInboundReply
        : null;
    $conversationTimeline = $latestInboundReply
        ? $conversationItems->reject(fn (array $item) => ($item['id'] ?? null) === ($latestInboundReply['id'] ?? null))->take(7)->values()
        : $conversationItems->take(8)->values();
    $clientTimezone = config('client.timezone', config('app.timezone', 'UTC'));

    $defaultContactTaskDueAt = now($clientTimezone)->addDay()->setTime(9, 0)->format('Y-m-d\TH:i');
    $openTasks = collect($tasks ?? [])
        ->filter(fn ($task) => $task->status === 'open' && ! $task->archived_at)
        ->sortBy(fn ($task) => sprintf(
            '%d-%012d-%012d-%012d',
            $task->due_at ? 0 : 1,
            $task->due_at?->timestamp ?? 999999999999,
            $task->created_at?->timestamp ?? 0,
            $task->id ?? 0,
        ))
        ->values();
    $nextTask = $openTasks->first();
    $upNextTask = $openTasks->skip(1)->first();
    $defaultActivityTab = $openTasks->isNotEmpty() && module_enabled('tasks') ? 'tasks' : 'notes';
    $contactPanelClass = 'space-y-6 '.module_tone('core', 'panel');
    $nextStepTone = module_enabled('tasks') ? module_tone('tasks', 'item') : 'bg-slate-50 border-slate-200';
    $activityPanelClass = 'space-y-4 '.(module_enabled('tasks') ? module_tone('tasks', 'panel') : module_tone('core', 'panel'));
    $messagesPanelClass = 'space-y-4 '.module_tone('messaging', 'panel');
@endphp

<x-layouts.crm
    :title="$contactName"
    :heading="$contactName"
    subheading="Who they are, what they said, and what needs to happen next"
>
    <div
        class="space-y-6"
        x-data="{
            activityTab: new URLSearchParams(window.location.search).get('activity_tab') || @js($defaultActivityTab),
            messageTab: new URLSearchParams(window.location.search).get('messages_tab') || 'messages',
            taskModalOpen: @js($errors->has('assigned_to_id') || $errors->has('assigned_to_type') || $errors->has('links') || $errors->has('title') || $errors->has('description') || $errors->has('due_at')),
        }"
    >
        @if(session('success'))
            <x-ui.feedback.alert type="success">
                {{ session('success') }}
            </x-ui.feedback.alert>
        @endif

        @if(session('error'))
            <div class="rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm font-semibold text-red-700">
                {{ session('error') }}
            </div>
        @endif

        <div class="grid gap-6 lg:grid-cols-[minmax(0,2fr)_minmax(20rem,1fr)]">
            <x-ui.card
                class="{{ $contactPanelClass }}"
                data-module-panel="core"
                data-contact-business-context="{{ $primaryBusinessContext['key'] ?? 'contact' }}"
                data-contact-progression="{{ $usesRelationshipStage ? 'relationship_stage' : ($showContactStatusProgression ? 'contact_status' : 'none') }}"
            >
                <div class="flex flex-col gap-4 sm:flex-row sm:flex-wrap sm:items-start sm:justify-between">
                    <div>
                        <p class="text-sm font-medium text-slate-500">
                            {{ $businessLabel }}
                        </p>

                        <h2 class="mt-1 break-words text-2xl font-semibold tracking-tight text-slate-950 sm:text-3xl">
                            {{ $contactName }}
                        </h2>
                    </div>

                    @if($hasProgression)
                        <div class="rounded-2xl bg-slate-100 px-3 py-2 text-right text-slate-700">
                            <p class="text-[10px] font-bold uppercase tracking-[0.14em] text-slate-500">
                                {{ $progressionType }}
                            </p>
                            <p class="mt-0.5 text-sm font-semibold">
                                {{ $progressionLabel }}
                            </p>
                        </div>
                    @endif
                </div>

                @if($businessRelationships->count() > 1)
                    <div class="flex flex-wrap gap-2" aria-label="Business relationships">
                        @foreach($businessRelationships as $relationship)
                            <span class="rounded-full border border-slate-200 bg-white px-3 py-1 text-xs font-semibold text-slate-700">
                                {{ $relationship['label'] }}
                                @if(filled($relationship['stage_label'] ?? null))
                                    · {{ $relationship['stage_label'] }}
                                @endif
                            </span>
                        @endforeach
                    </div>
                @endif

                <div class="rounded-2xl border p-4 {{ $nextStepTone }}" data-module-panel="{{ module_enabled('tasks') ? 'tasks' : 'core' }}">
                    @if($nextTask)
                        <div class="flex flex-col gap-4 sm:flex-row sm:flex-wrap sm:items-start sm:justify-between">
                            <div>
                                <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">
                                    Next step
                                </p>

                                <h3 class="mt-1 text-lg font-semibold text-slate-950">
                                    {{ $nextTask->title }}
                                </h3>

                                @if($nextTask->description)
                                    <p class="mt-1 max-w-3xl text-sm text-slate-600">
                                        {{ $nextTask->description }}
                                    </p>
                                @endif

                                <div class="mt-3 flex flex-wrap gap-x-6 gap-y-2 text-sm text-slate-600">
                                    <p>
                                        Owner:
                                        <span class="font-medium text-slate-900">
                                            {{ $nextTask->assignedTo?->name ?? 'Unassigned' }}
                                        </span>
                                    </p>

                                    <p>
                                        Due:
                                        <span class="font-medium text-slate-900">
                                            {{ $nextTask->due_at?->format('M j, Y g:i A') ?? 'No due date' }}
                                        </span>
                                    </p>
                                </div>
                            </div>

                            <div class="grid gap-2 sm:flex sm:flex-wrap">
                                <form method="POST" action="{{ route('crm.tasks.complete', $nextTask) }}">
                                    @csrf
                                    @method('PATCH')

                                    <x-ui.button type="submit" variant="secondary" class="w-full sm:w-auto">
                                        Mark Complete
                                    </x-ui.button>
                                </form>

                                <x-ui.button
                                    type="button"
                                    variant="outline"
                                    class="w-full sm:w-auto"
                                    x-on:click="activityTab = 'notes'; $nextTick(() => document.getElementById('contact-activity')?.scrollIntoView({ behavior: 'smooth', block: 'start' }))"
                                >
                                    Add Note
                                </x-ui.button>
                            </div>
                        </div>

                        @if($upNextTask)
                            <div class="mt-4 rounded-xl border border-slate-200 bg-white px-4 py-3">
                                <div class="flex flex-wrap items-start justify-between gap-3">
                                    <div>
                                        <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">
                                            Up next
                                        </p>

                                        <p class="mt-1 text-sm font-semibold text-slate-900">
                                            {{ $upNextTask->title }}
                                        </p>

                                        <div class="mt-1 flex flex-wrap gap-x-4 gap-y-1 text-xs text-slate-500">
                                            <span>
                                                Owner:
                                                <span class="font-medium text-slate-700">
                                                    {{ $upNextTask->assignedTo?->name ?? 'Unassigned' }}
                                                </span>
                                            </span>

                                            <span>
                                                Due:
                                                <span class="font-medium text-slate-700">
                                                    {{ $upNextTask->due_at?->format('M j, Y g:i A') ?? 'No due date' }}
                                                </span>
                                            </span>
                                        </div>
                                    </div>

                                    <button
                                        type="button"
                                        class="text-xs font-semibold text-slate-600 underline underline-offset-4 hover:text-slate-900"
                                        x-on:click="activityTab = 'tasks'; $nextTick(() => document.getElementById('contact-activity')?.scrollIntoView({ behavior: 'smooth', block: 'start' }))"
                                    >
                                        View tasks
                                    </button>
                                </div>
                            </div>
                        @endif
                    @else
                        <div class="flex flex-col gap-4 sm:flex-row sm:flex-wrap sm:items-center sm:justify-between">
                            <div>
                                <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">
                                    Next step
                                </p>

                                <h3 class="mt-1 text-lg font-semibold text-slate-950">
                                    No action needed right now.
                                </h3>

                                <p class="mt-1 text-sm text-slate-600">
                                    This {{ str($businessLabel)->lower() }} has no open tasks. Add a task if follow-up is needed.
                                </p>
                            </div>

                            @if(module_enabled('tasks'))
                                <x-ui.button
                                    type="button"
                                    variant="secondary"
                                    class="w-full sm:w-auto"
                                    x-on:click="taskModalOpen = true"
                                >
                                    Add Task
                                </x-ui.button>
                            @endif
                        </div>
                    @endif
                </div>

                <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <div>
                        <p class="text-sm text-slate-500">Email</p>
                        <p class="break-words font-medium text-slate-900">{{ $contact->email ?: '—' }}</p>
                    </div>

                    <div>
                        <p class="text-sm text-slate-500">Phone</p>
                        <p class="font-medium text-slate-900">{{ $contact->phone ?: '—' }}</p>
                    </div>

                    <div>
                        <p class="text-sm text-slate-500">Source</p>
                        <p class="font-medium text-slate-900">{{ $businessSource ?: '—' }}</p>
                    </div>

                    <div>
                        <p class="text-sm text-slate-500">Subsource</p>
                        <p class="font-medium text-slate-900">{{ $businessSubsource ?: '—' }}</p>
                    </div>
                </div>

                <div class="flex flex-wrap gap-2">
                    @if(module_enabled('tasks'))
                        <x-ui.button type="button" variant="secondary" x-on:click="taskModalOpen = true">
                            Add Task
                        </x-ui.button>
                    @endif

                    <x-ui.button
                        type="button"
                        variant="outline"
                        x-on:click="activityTab = 'notes'; $nextTick(() => document.getElementById('contact-activity')?.scrollIntoView({ behavior: 'smooth', block: 'start' }))"
                    >
                        Add Note
                    </x-ui.button>
                </div>

                @if($showContactStatusProgression)
                    <form
                        method="POST"
                        action="{{ route('crm.contacts.status.update', $contact) }}"
                        class="rounded-2xl border border-slate-200 bg-white p-4"
                        data-contact-status-form
                    >
                        @csrf
                        @method('PATCH')

                        <div class="grid gap-3 sm:grid-cols-[minmax(0,1fr)_auto] sm:items-end">
                            <div>
                                <x-ui.form.label for="contact_status_id">
                                    Change status
                                </x-ui.form.label>

                                <x-ui.form.select id="contact_status_id" name="contact_status_id">
                                    @foreach($contactStatuses as $contactStatus)
                                        <option
                                            value="{{ $contactStatus->id }}"
                                            @selected((int) old('contact_status_id', $contact->workflowProfile?->contact_status_id) === (int) $contactStatus->id)
                                        >
                                            {{ $contactStatus->name }}
                                        </option>
                                    @endforeach
                                </x-ui.form.select>

                                <x-ui.form.error name="contact_status_id" />

                                @if(module_enabled('flow_routes'))
                                    <p class="mt-2 text-xs text-slate-500">
                                        Some status changes can start automatic follow-ups. Review the next step after saving.
                                    </p>
                                @endif
                            </div>

                            <x-ui.button type="submit" class="w-full sm:w-auto">
                                Update Status
                            </x-ui.button>
                        </div>
                    </form>
                @endif

                @if(module_enabled('relationships') && $usesRelationshipStage && is_array($primaryBusinessContext) && ! empty($primaryBusinessContext['stage_options'] ?? []))
                    <form
                        method="POST"
                        action="{{ route('crm.contacts.relationships.stage.update', [$contact, $primaryBusinessContext['id']]) }}"
                        class="rounded-2xl border border-slate-200 bg-white p-4"
                        data-module-panel="relationships"
                        data-relationship-stage-form
                    >
                        @csrf
                        @method('PATCH')

                        <div class="grid gap-3 sm:grid-cols-[minmax(0,1fr)_auto] sm:items-end">
                            <div>
                                <x-ui.form.label for="stage_key">
                                    Change {{ str($businessLabel)->lower() }} stage
                                </x-ui.form.label>

                                <x-ui.form.select id="stage_key" name="stage_key">
                                    @foreach($primaryBusinessContext['stage_options'] as $stage)
                                        <option value="{{ $stage['key'] }}" @selected(old('stage_key', $primaryBusinessContext['stage_key']) === $stage['key'])>
                                            {{ $stage['label'] }}
                                        </option>
                                    @endforeach
                                </x-ui.form.select>

                                <x-ui.form.error name="stage_key" />
                            </div>

                            <x-ui.button type="submit" class="w-full sm:w-auto">
                                Update Stage
                            </x-ui.button>
                        </div>
                    </form>
                @endif

                @if($showContactStatusProgression)
                    @php
                        $hasGenericImportTreatments = is_array(data_get($contact->meta, 'import.treatments'));
                        $importStatusTreatmentState = $hasGenericImportTreatments
                            ? data_get($contact->meta, 'import.treatments.contact_status.state')
                            : data_get($contact->meta, 'import.status_mapping.state');
                    @endphp

                    @if ($importStatusTreatmentState === 'unmapped')
                        <div class="rounded-2xl border border-amber-200 bg-amber-50 p-4" data-contact-status-import-evidence>
                            <p class="text-sm font-semibold text-amber-900">Imported status needs review</p>
                            <p class="mt-1 text-sm text-amber-800">
                                {{ data_get($contact->meta, 'import.original_status') ?: data_get($contact->meta, 'import.treatments.contact_status.source_value') ?: 'Unmapped imported status' }}
                            </p>
                        </div>
                    @elseif (data_get($contact->meta, 'import.original_status'))
                        <div class="rounded-2xl border border-slate-200 bg-slate-50 p-4" data-contact-status-import-evidence>
                            <p class="text-sm text-slate-500">Original imported status</p>
                            <p class="font-medium text-slate-900">
                                {{ data_get($contact->meta, 'import.original_status') }}
                            </p>
                        </div>
                    @endif
                @endif
            </x-ui.card>

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

        @if(module_enabled('inbound_messaging'))
            <x-ui.card class="space-y-5" data-module-panel="inbound_messaging">
                <div class="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
                    <div>
                        <p class="text-xs font-bold uppercase tracking-[0.14em] text-slate-500">Conversation</p>
                        <h3 class="mt-1 text-lg font-semibold tracking-tight text-slate-950">
                            What they said recently
                        </h3>
                        <p class="mt-1 text-sm text-slate-500">
                            Recent replies with the outbound messages around them.
                        </p>
                    </div>

                    @if($conversationItems->isNotEmpty())
                        <span class="rounded-full bg-slate-100 px-3 py-1 text-xs font-semibold text-slate-600">
                            {{ $conversationItems->count() }} recent
                        </span>
                    @endif
                </div>

                @if($latestInboundReply)
                    <div class="rounded-2xl border border-indigo-200 bg-indigo-50/70 p-4">
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="rounded-full bg-white px-2.5 py-1 text-[11px] font-bold uppercase tracking-wide text-indigo-700 ring-1 ring-indigo-200">
                                    Latest reply
                                </span>
                                @if(filled($latestInboundReply['channel'] ?? null))
                                    <span class="text-xs font-semibold uppercase text-slate-500">
                                        {{ $latestInboundReply['channel'] }}
                                    </span>
                                @endif
                            </div>

                            @if($latestInboundReply['occurred_at'] ?? null)
                                <span class="text-xs text-slate-500">
                                    {{ $latestInboundReply['occurred_at']->copy()->setTimezone($clientTimezone)->format('M j, Y g:i A') }}
                                </span>
                            @endif
                        </div>

                        @if(filled($latestInboundReply['title'] ?? null))
                            <p class="mt-3 text-sm font-semibold text-slate-900">
                                {{ $latestInboundReply['title'] }}
                            </p>
                        @endif

                        <p class="mt-2 whitespace-pre-wrap text-sm leading-6 text-slate-800">
                            {{ $latestInboundReply['body'] ?: '(No message body)' }}
                        </p>

                        @if(filled($latestInboundReply['intent'] ?? null))
                            <p class="mt-3 text-xs font-semibold text-indigo-700">
                                Reply signal: {{ $latestInboundReply['intent'] }}
                            </p>
                        @endif
                    </div>
                @else
                    <div class="rounded-2xl border border-dashed border-slate-300 bg-slate-50 px-4 py-5 text-sm text-slate-500">
                        No inbound replies yet. When they reply, the message they answered will appear here with it.
                    </div>
                @endif

                @if($conversationTimeline->isNotEmpty())
                    <div class="divide-y divide-slate-200 rounded-2xl border border-slate-200 bg-white">
                        @foreach($conversationTimeline as $conversationItem)
                            <div class="p-4">
                                <div class="flex flex-wrap items-start justify-between gap-3">
                                    <div class="min-w-0">
                                        <div class="flex flex-wrap items-center gap-2">
                                            <span class="rounded-full px-2.5 py-1 text-[11px] font-bold uppercase tracking-wide {{ ($conversationItem['direction'] ?? null) === 'inbound' ? 'bg-indigo-50 text-indigo-700' : 'bg-slate-100 text-slate-600' }}">
                                                {{ ($conversationItem['direction'] ?? null) === 'inbound' ? 'Inbound' : 'Outbound' }}
                                            </span>
                                            @if(filled($conversationItem['channel'] ?? null))
                                                <span class="text-xs font-semibold uppercase text-slate-500">
                                                    {{ $conversationItem['channel'] }}
                                                </span>
                                            @endif
                                        </div>

                                        <p class="mt-2 break-words text-sm font-semibold text-slate-900">
                                            {{ $conversationItem['title'] ?? 'Message' }}
                                        </p>

                                        @if(filled($conversationItem['body'] ?? null))
                                            <p class="mt-1 whitespace-pre-wrap text-sm leading-6 text-slate-600">
                                                {{ $conversationItem['body'] }}
                                            </p>
                                        @endif

                                        @if(filled($conversationItem['intent'] ?? null))
                                            <p class="mt-2 text-xs font-semibold text-indigo-700">
                                                Reply signal: {{ $conversationItem['intent'] }}
                                            </p>
                                        @endif
                                    </div>

                                    @if($conversationItem['occurred_at'] ?? null)
                                        <span class="shrink-0 text-xs text-slate-500">
                                            {{ $conversationItem['occurred_at']->copy()->setTimezone($clientTimezone)->format('M j, Y g:i A') }}
                                        </span>
                                    @endif
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endif
            </x-ui.card>
        @endif

        <x-crm.contact-visibility :sections="$contactVisibilitySections" />

        <div id="contact-activity" class="grid gap-6">
            <x-ui.card class="{{ $activityPanelClass }}" data-module-panel="{{ module_enabled('tasks') ? 'tasks' : 'core' }}">
                <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <h3 class="text-lg font-semibold tracking-tight">
                            Activity
                        </h3>

                        <p class="text-sm text-slate-500">
                            Capture what happened and manage the manual follow-up that still needs a person.
                        </p>
                    </div>

                    <div class="flex w-full rounded-xl bg-slate-100 p-1 text-sm font-semibold sm:w-auto">
                        @if(module_enabled('tasks'))
                            <button
                                type="button"
                                x-on:click="activityTab = 'tasks'"
                                class="flex-1 rounded-lg px-3 py-1.5 sm:flex-none"
                                x-bind:class="activityTab === 'tasks' ? 'bg-white text-slate-900 shadow-sm' : 'text-slate-500 hover:text-slate-700'"
                            >
                                Tasks
                            </button>
                        @endif

                        <button
                            type="button"
                            x-on:click="activityTab = 'notes'"
                            class="flex-1 rounded-lg px-3 py-1.5 sm:flex-none"
                            x-bind:class="activityTab === 'notes' ? 'bg-white text-slate-900 shadow-sm' : 'text-slate-500 hover:text-slate-700'"
                        >
                            Notes
                        </button>
                    </div>
                </div>

                @if(module_enabled('tasks'))
                    <div x-show="activityTab === 'tasks'" class="space-y-4">
                        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                            <h4 class="font-semibold tracking-tight">
                                Tasks
                            </h4>

                            <x-ui.button
                                type="button"
                                class="w-full sm:w-auto"
                                x-on:click="taskModalOpen = true"
                            >
                                Add Task
                            </x-ui.button>
                        </div>

                        <x-crm.tasks.task-list
                            :tasks="$tasks"
                            :archived-tasks="$archivedTasks"
                            :task-view="$taskView"
                        />
                    </div>
                @endif

                <div x-show="activityTab === 'notes'" class="space-y-4">
                    <h4 class="font-semibold tracking-tight">
                        Add note
                    </h4>

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

                            <x-ui.form.error name="body" />
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
                                <div x-show="! editing" class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                                    <div class="space-y-2">
                                        <p class="text-slate-800">
                                            {{ $note->body }}
                                        </p>

                                        <p class="text-xs text-slate-500">
                                            {{ $note->created_at->format('M j, Y g:i A') }}
                                        </p>
                                    </div>

                                    <div class="flex flex-wrap gap-3">
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
            </x-ui.card>

            @if(module_enabled('tasks'))
                <x-crm.tasks.create-task-modal
                    :subject="$contact"
                    subject-label="{{ $businessLabel }}"
                    :assignee-options="$taskAssigneeOptions"
                    :current-assignee-key="$currentTaskAssigneeKey"
                    :default-due-at="$defaultContactTaskDueAt"
                />
            @endif
        </div>

        @if(module_enabled('messaging'))
            <x-ui.card class="{{ $messagesPanelClass }}" data-module-panel="messaging">
                <div>
                    <h3 class="text-lg font-semibold tracking-tight">
                        Messages & consent
                    </h3>

                    <p class="text-sm text-slate-500">
                        Review delivery history and whether this contact can receive follow-up.
                    </p>
                </div>

                <div class="border-b border-slate-200">
                    <nav class="-mb-px flex gap-4 overflow-x-auto" aria-label="Tabs">
                        <button
                            type="button"
                            x-on:click="messageTab = 'messages'"
                            class="border-b-2 px-1 pb-3 text-sm font-semibold"
                            x-bind:class="messageTab === 'messages'
                                ? 'border-indigo-600 text-indigo-600'
                                : 'border-transparent text-slate-500 hover:border-slate-300 hover:text-slate-700'"
                        >
                            Messages
                        </button>

                        <button
                            type="button"
                            x-on:click="messageTab = 'consents'"
                            class="border-b-2 px-1 pb-3 text-sm font-semibold"
                            x-bind:class="messageTab === 'consents'
                                ? 'border-indigo-600 text-indigo-600'
                                : 'border-transparent text-slate-500 hover:border-slate-300 hover:text-slate-700'"
                        >
                            Consent
                        </button>
                    </nav>
                </div>

                <div x-show="messageTab === 'messages'" class="space-y-3">
                    @forelse ($scheduledMessages as $message)
                        @php
                            $channel = str($message->channel)->replace('_', ' ')->title();
                            $scope = str($message->scope)->replace('_', ' ')->title();
                            $messageType = str($message->message_type)->replace('_', ' ')->title();
                        @endphp

                        <div class="rounded-xl border border-slate-200 p-4">
                            <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                                <div>
                                    <p class="font-medium text-slate-900">
                                        {{ $scope }} {{ $messageType }} {{ $channel }}
                                    </p>

                                    <p class="mt-1 text-sm text-slate-500">
                                        {{ str($message->purpose)->replace('_', ' ')->title() }} follow-up
                                    </p>
                                </div>

                                <span class="rounded-full bg-blue-50 px-2.5 py-1 text-xs font-semibold text-blue-700">
                                    {{ str($message->status)->replace('_', ' ')->title() }}
                                </span>
                            </div>

                            <div class="mt-3 grid gap-2 text-xs text-slate-500 sm:grid-cols-2">
                                <p>
                                    Send time:
                                    <span class="font-medium text-slate-700">
                                        {{ $message->send_at?->format('M j, Y g:i A') ?? '—' }}
                                    </span>
                                </p>

                                <p>
                                    Channel:
                                    <span class="font-medium text-slate-700">
                                        {{ $channel }}
                                    </span>
                                </p>
                            </div>
                        </div>
                    @empty
                        <p class="text-sm text-slate-500">
                            No messages yet.
                        </p>
                    @endforelse

                    @if ($scheduledMessages->hasPages())
                        <div class="pt-2">
                            {{ $scheduledMessages->appends(['messages_tab' => 'messages'])->links() }}
                        </div>
                    @endif
                </div>

                <div x-show="messageTab === 'consents'" class="space-y-4">
                    <div class="space-y-3">
                        @forelse ($messageConsents as $consent)
                            @php
                                $channel = str($consent->channel->value)->replace('_', ' ')->title();
                                $purpose = str($consent->purpose->value)->replace('_', ' ')->title();
                                $scope = str($consent->scope)->replace('_', ' ')->title();
                            @endphp

                            <div class="rounded-xl border border-slate-200 p-4">
                                <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                                    <div>
                                        <p class="font-medium text-slate-900">
                                            Can receive {{ strtolower($scope) }} {{ strtolower($purpose) }} by {{ strtolower($channel) }}
                                        </p>

                                        <p class="mt-1 text-sm text-slate-500">
                                            Consented {{ $consent->consented_at?->format('M j, Y g:i A') ?? '—' }}
                                        </p>
                                    </div>

                                    <span class="rounded-full bg-green-50 px-2.5 py-1 text-xs font-semibold text-green-700">
                                        Consented
                                    </span>
                                </div>

                                <p class="mt-3 text-xs text-slate-500">
                                    Source:
                                    <span class="font-medium text-slate-700">
                                        {{ $consent->source ? str($consent->source)->replace('_', ' ')->title() : '—' }}
                                    </span>
                                </p>
                            </div>
                        @empty
                            <p class="text-sm text-slate-500">
                                No active message consents.
                            </p>
                        @endforelse
                    </div>

                    <div class="border-t border-slate-200 pt-4">
                        <h4 class="text-sm font-semibold text-slate-900">
                            Revocation history
                        </h4>

                        <div class="mt-3 space-y-3">
                            @forelse ($consentRevocations as $revocation)
                                <div class="rounded-xl border border-slate-200 p-4">
                                    <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                                        <div>
                                            <p class="font-medium text-slate-900">
                                                {{ str($revocation->channel->value)->replace('_', ' ')->title() }} {{ str($revocation->purpose->value)->replace('_', ' ')->title() }} revoked
                                            </p>

                                            <p class="mt-1 text-sm text-slate-500">
                                                Scope: {{ str($revocation->scope)->replace('_', ' ')->title() }}
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
        @endif
    </div>
</x-layouts.crm>