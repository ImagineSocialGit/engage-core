<div class="space-y-4 rounded-2xl border border-slate-200 bg-slate-50 p-4">
    <div>
        <div class="text-sm font-semibold text-slate-900">Include in this report</div>
        <div class="mt-3 flex flex-wrap gap-4">
            @foreach([
                'include_replies' => 'Replies needing attention',
                'include_tasks' => 'Overdue and due-today tasks',
                'include_appointments' => 'Appointments today',
            ] as $key => $label)
                <label class="inline-flex items-center gap-2 text-sm text-slate-700">
                    <input type="hidden" name="parameters[{{ $key }}]" value="0">
                    <input
                        type="checkbox"
                        name="parameters[{{ $key }}]"
                        value="1"
                        @checked((bool) ($parameters[$key] ?? true))
                        class="rounded border-slate-300"
                    >
                    {{ $label }}
                </label>
            @endforeach
        </div>
    </div>

    <div class="overflow-x-auto rounded-2xl border border-slate-200 bg-white">
        <table class="min-w-full divide-y divide-slate-200 text-sm">
            <thead class="bg-slate-50">
                <tr>
                    <th class="px-4 py-3 text-left font-semibold text-slate-700">Contact status</th>
                    <th class="px-4 py-3 text-center font-semibold text-slate-700">New lead</th>
                    <th class="px-4 py-3 text-center font-semibold text-slate-700">Incomplete application</th>
                    <th class="px-4 py-3 text-center font-semibold text-slate-700">Needs next action</th>
                    <th class="px-4 py-3 text-left font-semibold text-slate-700">Personal follow-up after</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @foreach($parameterData['status_options'] as $status)
                    <tr>
                        <td class="px-4 py-3 font-semibold text-slate-900">{{ $status['name'] }}</td>
                        <td class="px-4 py-3 text-center">
                            <input
                                type="checkbox"
                                name="parameters[new_lead_status_keys][]"
                                value="{{ $status['key'] }}"
                                @checked(in_array($status['key'], $parameters['new_lead_status_keys'] ?? [], true))
                                class="rounded border-slate-300"
                            >
                        </td>
                        <td class="px-4 py-3 text-center">
                            <input
                                type="checkbox"
                                name="parameters[incomplete_application_status_keys][]"
                                value="{{ $status['key'] }}"
                                @checked(in_array($status['key'], $parameters['incomplete_application_status_keys'] ?? [], true))
                                class="rounded border-slate-300"
                            >
                        </td>
                        <td class="px-4 py-3 text-center">
                            <input
                                type="checkbox"
                                name="parameters[next_action_status_keys][]"
                                value="{{ $status['key'] }}"
                                @checked(in_array($status['key'], $parameters['next_action_status_keys'] ?? [], true))
                                class="rounded border-slate-300"
                            >
                        </td>
                        <td class="px-4 py-3">
                            <div class="flex items-center gap-2">
                                <input
                                    type="number"
                                    name="parameters[follow_up_status_days][{{ $status['key'] }}]"
                                    min="1"
                                    max="3650"
                                    value="{{ $parameters['follow_up_status_days'][$status['key']] ?? '' }}"
                                    class="w-24 rounded-xl border-slate-300 text-sm shadow-sm"
                                    placeholder="—"
                                >
                                <span class="text-xs text-slate-500">days</span>
                            </div>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>