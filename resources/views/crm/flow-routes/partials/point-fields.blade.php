@switch($pointType)
    @case(\App\Modules\FlowRoutes\Enums\FlowRoutePointType::Wait->value)
        <div class="grid gap-4 sm:grid-cols-3">
            <div>
                <label for="wait-mode-{{ $fieldSuffix }}" class="text-sm font-semibold text-slate-900">
                    Wait type
                </label>

                <select
                    id="wait-mode-{{ $fieldSuffix }}"
                    name="wait_mode"
                    class="mt-1 block w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm text-slate-950 shadow-sm focus:border-orange-500 focus:outline-none focus:ring-2 focus:ring-orange-200"
                >
                    <option value="duration" @selected(!isset($definition['resume_at']))>For a duration</option>
                    <option value="resume_at" @selected(isset($definition['resume_at']))>Until a date and time</option>
                </select>
            </div>

            <div>
                <label for="duration-value-{{ $fieldSuffix }}" class="text-sm font-semibold text-slate-900">
                    Duration
                </label>

                @php
                    $durationUnit = collect(['weeks', 'days', 'hours', 'minutes'])
                        ->first(fn ($unit) => array_key_exists($unit, $definition), 'days');
                    $durationValue = $definition[$durationUnit] ?? 1;
                @endphp

                <input
                    id="duration-value-{{ $fieldSuffix }}"
                    name="duration_value"
                    type="number"
                    min="0"
                    value="{{ old('duration_value', $durationValue) }}"
                    class="mt-1 block w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm text-slate-950 shadow-sm focus:border-orange-500 focus:outline-none focus:ring-2 focus:ring-orange-200"
                >
            </div>

            <div>
                <label for="duration-unit-{{ $fieldSuffix }}" class="text-sm font-semibold text-slate-900">
                    Unit
                </label>

                <select
                    id="duration-unit-{{ $fieldSuffix }}"
                    name="duration_unit"
                    class="mt-1 block w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm text-slate-950 shadow-sm focus:border-orange-500 focus:outline-none focus:ring-2 focus:ring-orange-200"
                >
                    @foreach(['minutes' => 'Minutes', 'hours' => 'Hours', 'days' => 'Days', 'weeks' => 'Weeks'] as $value => $label)
                        <option value="{{ $value }}" @selected($durationUnit === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
        </div>

        <div>
            <label for="resume-at-{{ $fieldSuffix }}" class="text-sm font-semibold text-slate-900">
                Or wait until
            </label>

            <input
                id="resume-at-{{ $fieldSuffix }}"
                name="resume_at"
                type="datetime-local"
                value="{{ old('resume_at', $definition['resume_at'] ?? '') }}"
                class="mt-1 block w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm text-slate-950 shadow-sm focus:border-orange-500 focus:outline-none focus:ring-2 focus:ring-orange-200"
            >

            <p class="mt-1 text-xs leading-5 text-slate-600">
                Choose “Until a date and time” above to use this field.
            </p>
        </div>
        @break

    @case(\App\Modules\FlowRoutes\Enums\FlowRoutePointType::ChangeStatus->value)
        <div>
            <label for="contact-status-{{ $fieldSuffix }}" class="text-sm font-semibold text-slate-900">
                Move contact to
            </label>

            <select
                id="contact-status-{{ $fieldSuffix }}"
                name="contact_status_key"
                required
                class="mt-1 block w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm text-slate-950 shadow-sm focus:border-amber-500 focus:outline-none focus:ring-2 focus:ring-amber-200"
            >
                <option value="">Choose a status</option>

                @foreach($editorOptions['contact_statuses'] as $status)
                    <option value="{{ $status->key }}" @selected(($definition['contact_status_key'] ?? null) === $status->key)>
                        {{ $status->name }}
                    </option>
                @endforeach
            </select>
        </div>
        @break

    @case(\App\Modules\FlowRoutes\Enums\FlowRoutePointType::CreateTask->value)
        <div>
            <label for="task-template-{{ $fieldSuffix }}" class="text-sm font-semibold text-slate-900">
                Task Template <span class="font-normal text-slate-600">(recommended when available)</span>
            </label>

            <select
                id="task-template-{{ $fieldSuffix }}"
                name="task_template_key"
                class="mt-1 block w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm text-slate-950 shadow-sm focus:border-emerald-500 focus:outline-none focus:ring-2 focus:ring-emerald-200"
            >
                <option value="">Create an inline task instead</option>

                @foreach($editorOptions['task_templates'] as $template)
                    <option value="{{ $template->key }}" @selected(($definition['task_template_key'] ?? null) === $template->key)>
                        {{ $template->name ?: $template->title }}
                    </option>
                @endforeach
            </select>
        </div>

        <div>
            <label for="task-title-{{ $fieldSuffix }}" class="text-sm font-semibold text-slate-900">
                Inline task title
            </label>

            <input
                id="task-title-{{ $fieldSuffix }}"
                name="title"
                type="text"
                value="{{ old('title', $definition['title'] ?? '') }}"
                placeholder="Follow up with contact"
                class="mt-1 block w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm text-slate-950 shadow-sm focus:border-emerald-500 focus:outline-none focus:ring-2 focus:ring-emerald-200"
            >

            <p class="mt-1 text-xs leading-5 text-slate-600">
                Used only when no Task Template is selected.
            </p>
        </div>

        <div>
            <label for="task-description-{{ $fieldSuffix }}" class="text-sm font-semibold text-slate-900">
                Task description <span class="font-normal text-slate-600">(optional)</span>
            </label>

            <textarea
                id="task-description-{{ $fieldSuffix }}"
                name="description"
                rows="3"
                class="mt-1 block w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm text-slate-950 shadow-sm focus:border-emerald-500 focus:outline-none focus:ring-2 focus:ring-emerald-200"
            >{{ old('description', $definition['description'] ?? '') }}</textarea>
        </div>

        <div class="grid gap-4 sm:grid-cols-2">
            <div>
                <label for="task-due-{{ $fieldSuffix }}" class="text-sm font-semibold text-slate-900">
                    Due after minutes <span class="font-normal text-slate-600">(optional)</span>
                </label>

                <input
                    id="task-due-{{ $fieldSuffix }}"
                    name="due_offset_minutes"
                    type="number"
                    min="0"
                    value="{{ old('due_offset_minutes', $definition['due_offset_minutes'] ?? '') }}"
                    class="mt-1 block w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm text-slate-950 shadow-sm focus:border-emerald-500 focus:outline-none focus:ring-2 focus:ring-emerald-200"
                >
            </div>

            <div>
                <label for="task-priority-{{ $fieldSuffix }}" class="text-sm font-semibold text-slate-900">
                    Priority <span class="font-normal text-slate-600">(optional)</span>
                </label>

                <select
                    id="task-priority-{{ $fieldSuffix }}"
                    name="priority"
                    class="mt-1 block w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm text-slate-950 shadow-sm focus:border-emerald-500 focus:outline-none focus:ring-2 focus:ring-emerald-200"
                >
                    <option value="">Default</option>
                    @foreach(['low' => 'Low', 'normal' => 'Normal', 'high' => 'High', 'urgent' => 'Urgent'] as $value => $label)
                        <option value="{{ $value }}" @selected(($definition['priority'] ?? null) === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
        </div>
        @break

    @case(\App\Modules\FlowRoutes\Enums\FlowRoutePointType::SendMessage->value)
        <div>
            <label for="message-template-{{ $fieldSuffix }}" class="text-sm font-semibold text-slate-900">
                Message Template
            </label>

            <select
                id="message-template-{{ $fieldSuffix }}"
                name="message_template_preset_id"
                required
                class="mt-1 block w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm text-slate-950 shadow-sm focus:border-sky-500 focus:outline-none focus:ring-2 focus:ring-sky-200"
            >
                <option value="">Choose a reusable message</option>

                @foreach($editorOptions['message_templates'] as $template)
                    <option
                        value="{{ $template->id }}"
                        @selected(($definition['message_template_preset_key'] ?? null) === $template->key)
                    >
                        {{ $template->name }} · {{ ucfirst($template->channel) }}
                    </option>
                @endforeach
            </select>

            <p class="mt-1 text-xs leading-5 text-slate-600">
                Sending still respects communication permissions, suppressions, channel availability, and delivery rules.
            </p>
        </div>
        @break

    @case(\App\Modules\FlowRoutes\Enums\FlowRoutePointType::EnrollCampaign->value)
        <div>
            <label for="campaign-enroll-{{ $fieldSuffix }}" class="text-sm font-semibold text-slate-900">
                Campaign
            </label>

            <select
                id="campaign-enroll-{{ $fieldSuffix }}"
                name="campaign_key"
                required
                class="mt-1 block w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm text-slate-950 shadow-sm focus:border-rose-500 focus:outline-none focus:ring-2 focus:ring-rose-200"
            >
                <option value="">Choose a Campaign</option>

                @foreach($editorOptions['campaigns'] as $campaign)
                    <option value="{{ $campaign->key }}" @selected(($definition['campaign_key'] ?? null) === $campaign->key)>
                        {{ $campaign->name }}
                    </option>
                @endforeach
            </select>
        </div>
        @break

    @case(\App\Modules\FlowRoutes\Enums\FlowRoutePointType::CancelCampaign->value)
        <div>
            <label for="campaign-cancel-{{ $fieldSuffix }}" class="text-sm font-semibold text-slate-900">
                Campaign to stop
            </label>

            <select
                id="campaign-cancel-{{ $fieldSuffix }}"
                name="campaign_key"
                required
                class="mt-1 block w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm text-slate-950 shadow-sm focus:border-rose-500 focus:outline-none focus:ring-2 focus:ring-rose-200"
            >
                <option value="">Choose a Campaign</option>

                @foreach($editorOptions['campaigns'] as $campaign)
                    <option value="{{ $campaign->key }}" @selected(($definition['campaign_key'] ?? null) === $campaign->key)>
                        {{ $campaign->name }}
                    </option>
                @endforeach
            </select>
        </div>

        <label class="flex items-start gap-3 rounded-xl bg-white/80 p-3 ring-1 ring-black/5">
            <input
                type="checkbox"
                name="skip_pending_messages"
                value="1"
                class="mt-1 rounded border-slate-300 text-rose-700 focus:ring-rose-300"
                @checked((bool) ($definition['skip_pending_messages'] ?? true))
            >

            <span>
                <span class="block text-sm font-semibold text-slate-900">Skip pending messages from this enrollment</span>
                <span class="mt-1 block text-xs leading-5 text-slate-600">Recommended when the reason for stopping the Campaign means future scheduled follow-ups should no longer send.</span>
            </span>
        </label>
        @break
@endswitch
