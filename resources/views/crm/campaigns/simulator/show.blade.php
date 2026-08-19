<x-layouts.crm>
    @php
        $display = fn (?string $value) => $value
            ? \Illuminate\Support\Carbon::parse($value)->timezone($timezone)->format('M j, Y g:i A')
            : '—';
    @endphp

    <div class="mx-auto w-full max-w-7xl space-y-6 px-4 py-6 sm:px-6 lg:px-8">
        <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
            <div class="min-w-0">
                <div class="text-xs font-bold uppercase tracking-[0.18em] text-amber-700">Development only</div>
                <h1 class="mt-2 break-words text-3xl font-bold tracking-tight text-slate-950">{{ $snapshot['campaign']['name'] }}</h1>
                <p class="mt-2 break-words text-sm text-slate-600">
                    Simulating {{ $snapshot['contact']['name'] }} · Run {{ $snapshot['run_id'] }}
                </p>
            </div>

            <a href="{{ route('crm.campaigns.simulator.index') }}" class="inline-flex min-h-11 w-full items-center justify-center rounded-full border border-slate-300 bg-white px-5 text-sm font-bold text-slate-800 hover:bg-slate-50 sm:w-auto">
                All simulations
            </a>
        </div>

        @if(session('status'))
            <div class="rounded-2xl border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-900">{{ session('status') }}</div>
        @endif

        @if(session('error'))
            <div class="rounded-2xl border border-rose-200 bg-rose-50 p-4 text-sm text-rose-900">{{ session('error') }}</div>
        @endif

        @if($errors->any())
            <div class="rounded-2xl border border-rose-200 bg-rose-50 p-4 text-sm text-rose-900">
                <ul class="list-disc space-y-1 pl-5">
                    @foreach($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <section class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <div class="rounded-2xl border border-slate-200 bg-white p-4">
                <div class="text-xs font-bold uppercase tracking-wide text-slate-500">Fake now</div>
                <div class="mt-2 font-semibold text-slate-950">{{ $display($snapshot['fake_current_at']) }}</div>
                <div class="mt-1 text-xs text-slate-500">{{ $timezone }}</div>
            </div>
            <div class="rounded-2xl border border-slate-200 bg-white p-4">
                <div class="text-xs font-bold uppercase tracking-wide text-slate-500">Chain state</div>
                <div class="mt-2 font-semibold text-slate-950">{{ ucfirst($snapshot['chain']['status']) }}</div>
                <div class="mt-1 text-xs text-slate-500">Version {{ $snapshot['chain']['version'] }}</div>
            </div>
            <div class="rounded-2xl border border-slate-200 bg-white p-4">
                <div class="text-xs font-bold uppercase tracking-wide text-slate-500">Current step</div>
                <div class="mt-2 break-words font-semibold text-slate-950">{{ $snapshot['chain']['current_step_name'] ?: 'No active step' }}</div>
            </div>
            <div class="rounded-2xl border border-slate-200 bg-white p-4">
                <div class="text-xs font-bold uppercase tracking-wide text-slate-500">Next event</div>
                <div class="mt-2 font-semibold text-slate-950">{{ $display($snapshot['next_event_at']) }}</div>
            </div>
        </section>

        <section class="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6">
            <h2 class="text-lg font-semibold text-slate-950">Move the fake clock</h2>
            <p class="mt-1 text-sm text-slate-600">Each advance runs due work synchronously through the real MessageChain and ScheduledMessage runtime. Local deliveries are captured by DevMessageSink.</p>

            <div class="mt-5 flex flex-col gap-3 lg:flex-row lg:flex-wrap">
                <form method="POST" action="{{ route('crm.campaigns.simulator.process', $simulation) }}">
                    @csrf
                    <button class="inline-flex min-h-11 w-full items-center justify-center rounded-full bg-slate-950 px-5 text-sm font-bold text-white hover:bg-slate-800 lg:w-auto">Process due work</button>
                </form>

                @foreach(['next' => 'Advance to next event', 'hour' => '+1 hour', 'day' => '+1 day'] as $mode => $label)
                    <form method="POST" action="{{ route('crm.campaigns.simulator.advance', $simulation) }}">
                        @csrf
                        <input type="hidden" name="mode" value="{{ $mode }}">
                        <button class="inline-flex min-h-11 w-full items-center justify-center rounded-full border border-slate-300 bg-white px-5 text-sm font-bold text-slate-800 hover:bg-slate-50 lg:w-auto">{{ $label }}</button>
                    </form>
                @endforeach
            </div>

            <form method="POST" action="{{ route('crm.campaigns.simulator.advance', $simulation) }}" class="mt-4 flex flex-col gap-3 sm:flex-row sm:items-end">
                @csrf
                <input type="hidden" name="mode" value="custom">
                <label class="block min-w-0 flex-1">
                    <span class="text-sm font-semibold text-slate-900">Custom fake time</span>
                    <input type="datetime-local" name="fake_now" value="{{ $fakeCurrentLocal->format('Y-m-d\TH:i') }}" required class="mt-2 min-h-11 w-full rounded-xl border border-slate-300 bg-white px-3 text-sm">
                </label>
                <button class="inline-flex min-h-11 w-full items-center justify-center rounded-full border border-slate-300 bg-white px-5 text-sm font-bold text-slate-800 hover:bg-slate-50 sm:w-auto">Advance & process</button>
            </form>
        </section>

        <section class="overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-sm">
            <div class="border-b border-slate-200 p-5 sm:p-6">
                <h2 class="text-lg font-semibold text-slate-950">Message timeline</h2>
                <p class="mt-1 text-sm text-slate-600">These are actual ScheduledMessage records created by the runtime.</p>
            </div>

            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-slate-200 text-left text-sm">
                    <thead class="bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
                        <tr>
                            <th class="px-4 py-3">Step / variant</th>
                            <th class="px-4 py-3">Channel</th>
                            <th class="px-4 py-3">Send at</th>
                            <th class="px-4 py-3">Status</th>
                            <th class="px-4 py-3">Result</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-200">
                        @forelse($snapshot['messages'] as $message)
                            <tr class="align-top">
                                <td class="px-4 py-4 text-slate-900">
                                    <div class="font-semibold">{{ $message['step_key'] ?: '—' }}</div>
                                    <div class="mt-1 text-xs text-slate-500">{{ $message['variant_key'] ?: '—' }} · #{{ $message['id'] }}</div>
                                </td>
                                <td class="px-4 py-4 text-slate-700">{{ $message['channel'] }}<div class="mt-1 text-xs text-slate-500">{{ $message['purpose'] }} / {{ $message['scope'] }}</div></td>
                                <td class="px-4 py-4 whitespace-nowrap text-slate-700">{{ $display($message['send_at']) }}</td>
                                <td class="px-4 py-4 font-semibold text-slate-900">{{ $message['status'] }}</td>
                                <td class="max-w-md px-4 py-4 text-slate-700">
                                    @if($message['terminal'])
                                        <div>{{ $message['terminal']['provider'] ?: $message['terminal']['reason_code'] ?: 'Terminal' }}</div>
                                        @if($message['terminal']['reason'])
                                            <div class="mt-1 break-words text-xs text-slate-500">{{ $message['terminal']['reason'] }}</div>
                                        @endif
                                    @else
                                        <span class="text-slate-400">Waiting</span>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="px-4 py-8 text-center text-slate-500">No messages have materialized yet.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>

        <section class="space-y-4">
            <div>
                <h2 class="text-lg font-semibold text-slate-950">Chain steps and variants</h2>
                <p class="mt-1 text-sm text-slate-600">A variant with no ScheduledMessage has not been materialized by the real runtime yet. Conditions and dependency policy are shown for diagnosis.</p>
            </div>

            @foreach($snapshot['steps'] as $step)
                <article class="rounded-3xl border {{ $step['is_current'] ? 'border-slate-950' : 'border-slate-200' }} bg-white p-5 shadow-sm sm:p-6">
                    <div class="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
                        <div>
                            <h3 class="font-semibold text-slate-950">{{ $step['name'] ?: $step['key'] }}</h3>
                            <p class="mt-1 text-xs text-slate-500">{{ $step['timing_type'] }} · offset {{ $step['offset_seconds'] }}s · {{ $step['variant_strategy'] }} · {{ $step['advance_policy'] }}</p>
                        </div>
                        @if($step['is_current'])
                            <span class="inline-flex w-fit rounded-full bg-slate-950 px-2.5 py-1 text-xs font-bold text-white">Current</span>
                        @endif
                    </div>

                    @if($step['conditions'])
                        <pre class="mt-4 overflow-x-auto rounded-xl bg-slate-950 p-3 text-xs text-white">{{ json_encode($step['conditions'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
                    @endif

                    <div class="mt-4 grid gap-3 lg:grid-cols-2">
                        @foreach($step['variants'] as $variant)
                            <div class="rounded-2xl border border-slate-200 p-4">
                                <div class="flex flex-wrap items-center gap-2">
                                    <span class="font-semibold text-slate-950">{{ $variant['key'] }}</span>
                                    <span class="rounded-full bg-slate-100 px-2 py-1 text-xs font-semibold text-slate-600">{{ $variant['channel'] }}</span>
                                    @if($variant['materialized_message'])
                                        <span class="rounded-full bg-emerald-50 px-2 py-1 text-xs font-semibold text-emerald-700">{{ $variant['materialized_message']['status'] }}</span>
                                    @else
                                        <span class="rounded-full bg-slate-50 px-2 py-1 text-xs font-semibold text-slate-500">Not materialized</span>
                                    @endif
                                </div>
                                <div class="mt-2 text-xs text-slate-500">{{ $variant['purpose'] }} / {{ $variant['scope'] }} · {{ $variant['message_type'] }}</div>

                                @if($variant['dependency_policy'])
                                    <div class="mt-3 text-xs font-semibold text-slate-700">Dependency policy</div>
                                    <pre class="mt-1 overflow-x-auto rounded-xl bg-slate-50 p-3 text-xs text-slate-700">{{ json_encode($variant['dependency_policy'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
                                @endif
                                @if($variant['conditions'])
                                    <div class="mt-3 text-xs font-semibold text-slate-700">Conditions</div>
                                    <pre class="mt-1 overflow-x-auto rounded-xl bg-slate-50 p-3 text-xs text-slate-700">{{ json_encode($variant['conditions'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
                                @endif
                            </div>
                        @endforeach
                    </div>
                </article>
            @endforeach
        </section>

        <section class="rounded-3xl border border-rose-200 bg-rose-50 p-5 sm:p-6">
            <h2 class="font-semibold text-rose-950">Reset this simulation</h2>
            <p class="mt-1 text-sm text-rose-800">Deletes only the simulator-owned CampaignEnrollment, MessageChainEnrollment, and their ScheduledMessages. It does not delete the Campaign or Contact.</p>
            <form method="POST" action="{{ route('crm.campaigns.simulator.destroy', $simulation) }}" class="mt-4" onsubmit="return confirm('Reset this Campaign simulation and delete its simulator-owned runtime records?')">
                @csrf
                @method('DELETE')
                <button class="inline-flex min-h-11 w-full items-center justify-center rounded-full bg-rose-700 px-5 text-sm font-bold text-white hover:bg-rose-800 sm:w-auto">Reset simulation</button>
            </form>
        </section>
    </div>
</x-layouts.crm>