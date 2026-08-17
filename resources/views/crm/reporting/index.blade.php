<x-layouts.crm
    :title="$title"
    :heading="$heading"
    :subheading="$subheading"
    module="reporting"
>
    @php
        $percent = static fn (array $metric): string => $metric['percent'] === null
            ? '—'
            : number_format((float) $metric['percent'], 1).'%';
        $ratioDetail = static fn (array $metric): string => $metric['denominator'] > 0
            ? number_format((int) $metric['numerator']).' / '.number_format((int) $metric['denominator'])
            : 'No eligible records';
        $toneClasses = [
            'positive' => 'border-emerald-200 bg-emerald-50 text-emerald-950',
            'attention' => 'border-amber-300 bg-amber-50 text-amber-950',
            'neutral' => 'border-slate-200 bg-slate-50 text-slate-900',
        ];
    @endphp

    <div class="space-y-6">
        <section class="rounded-3xl border border-slate-200 bg-white/90 shadow-sm">
            <div class="p-6 sm:p-8">
                <div class="flex flex-col gap-6 lg:flex-row lg:items-end lg:justify-between">
                    <div>
                        <p class="text-sm font-semibold uppercase tracking-[0.16em] text-slate-600">
                            Webinar Registration
                        </p>
                        <h2 class="mt-2 text-2xl font-semibold tracking-tight text-slate-950">
                            See where visitors stop before they register
                        </h2>
                        <p class="mt-3 max-w-3xl text-sm leading-6 text-slate-700">
                            This report follows privacy-safe landing and form behavior, separates likely human traffic from automation, and connects browser activity to authoritative registration outcomes.
                        </p>
                    </div>

                    <div>
                        <p class="text-xs font-bold uppercase tracking-wide text-slate-500">Date range</p>
                        <div class="mt-2 flex flex-wrap gap-2">
                            @foreach($rangeOptions as $rangeDays)
                                <a
                                    href="{{ route('crm.reporting.index', ['days' => $rangeDays]) }}"
                                    class="inline-flex min-h-10 items-center justify-center rounded-xl px-4 text-sm font-semibold transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-slate-400 {{ $report['range']['days'] === $rangeDays ? 'bg-slate-950 text-white' : 'border border-slate-300 bg-white text-slate-800 hover:bg-slate-100' }}"
                                >
                                    {{ $rangeDays }} days
                                </a>
                            @endforeach
                        </div>
                    </div>
                </div>

                <div class="mt-5 flex flex-wrap gap-x-5 gap-y-1 text-xs text-slate-500">
                    <span>
                        {{ $report['range']['from']->format('M j, Y') }} – {{ $report['range']['through']->format('M j, Y') }}
                    </span>
                    @if($report['updated_at'])
                        <span>
                            Updated through {{ $report['updated_at']->timezone($report['timezone'])->format('M j, Y g:i A') }}
                        </span>
                    @endif
                </div>
            </div>
        </section>

        @unless($report['has_data'])
            <section class="rounded-3xl border border-dashed border-slate-300 bg-white p-8 text-center shadow-sm">
                <h2 class="text-lg font-semibold text-slate-950">No Reporting data in this period yet</h2>
                <p class="mx-auto mt-2 max-w-2xl text-sm leading-6 text-slate-700">
                    Once visitors reach a tracked Webinar registration page and the daily projection runs, traffic quality and funnel results will appear here automatically.
                </p>
            </section>
        @else
            <section class="rounded-3xl border border-slate-200 bg-white/90 shadow-sm">
                <div class="border-b border-slate-100 p-6 sm:p-8">
                    <h2 class="text-xl font-semibold tracking-tight text-slate-950">What stands out</h2>
                    <p class="mt-1 max-w-3xl text-sm leading-6 text-slate-700">
                        These callouts describe what the data shows. They do not assume a universal conversion benchmark.
                    </p>
                </div>

                <div class="grid gap-4 p-6 sm:p-8 lg:grid-cols-2">
                    @foreach($report['insights'] as $insight)
                        <article class="rounded-2xl border p-5 {{ $toneClasses[$insight['tone']] ?? $toneClasses['neutral'] }}">
                            <h3 class="font-semibold">{{ $insight['title'] }}</h3>
                            <p class="mt-2 text-sm leading-6">{{ $insight['body'] }}</p>
                        </article>
                    @endforeach
                </div>
            </section>

            <section class="rounded-3xl border border-slate-200 bg-white/90 shadow-sm">
                <div class="border-b border-slate-100 p-6 sm:p-8">
                    <div class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
                        <div>
                            <h2 class="text-xl font-semibold tracking-tight text-slate-950">Registration funnel</h2>
                            <p class="mt-1 max-w-3xl text-sm leading-6 text-slate-700">
                                The primary funnel uses likely-human landing sessions. “Registered” means a browser session was correlated to at least one authoritative local registration.
                            </p>
                        </div>

                        <div class="grid grid-cols-2 gap-3 text-sm sm:grid-cols-3">
                            <div class="rounded-xl bg-slate-50 px-4 py-3 ring-1 ring-slate-200">
                                <div class="text-xs font-semibold uppercase tracking-wide text-slate-500">Conversion</div>
                                <div class="mt-1 text-lg font-bold text-slate-950">{{ $percent($report['summary']['registration_conversion']) }}</div>
                            </div>
                            <div class="rounded-xl bg-slate-50 px-4 py-3 ring-1 ring-slate-200">
                                <div class="text-xs font-semibold uppercase tracking-wide text-slate-500">Validation failures</div>
                                <div class="mt-1 text-lg font-bold text-slate-950">{{ $percent($report['summary']['validation_failure_rate']) }}</div>
                            </div>
                            <div class="rounded-xl bg-slate-50 px-4 py-3 ring-1 ring-slate-200">
                                <div class="text-xs font-semibold uppercase tracking-wide text-slate-500">Likely human</div>
                                <div class="mt-1 text-lg font-bold text-slate-950">{{ $percent($report['summary']['human_share']) }}</div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="p-6 sm:p-8">
                    @php
                        $landingCount = max(1, (int) ($report['funnel'][0]['count'] ?? 0));
                    @endphp
                    <div class="space-y-5">
                        @foreach($report['funnel'] as $stage)
                            @php
                                $width = min(
                                    100,
                                    max(2, round(((int) $stage['count'] / $landingCount) * 100, 1)),
                                );
                            @endphp
                            <div>
                                <div class="flex items-end justify-between gap-4">
                                    <div>
                                        <div class="font-semibold text-slate-950">{{ $stage['label'] }}</div>
                                        @if($stage['from_previous_percent'] !== null)
                                            <div class="mt-0.5 text-xs text-slate-500">
                                                {{ number_format((float) $stage['from_previous_percent'], 1) }}% reached this stage from the prior measured stage
                                            </div>
                                        @else
                                            <div class="mt-0.5 text-xs text-slate-500">Likely-human observed sessions</div>
                                        @endif
                                    </div>
                                    <div class="text-right">
                                        <div class="text-2xl font-bold text-slate-950">{{ number_format((int) $stage['count']) }}</div>
                                        @if($stage['from_landing_percent'] !== null)
                                            <div class="text-xs font-semibold text-slate-500">{{ number_format((float) $stage['from_landing_percent'], 1) }}% of landing</div>
                                        @endif
                                    </div>
                                </div>
                                <div class="mt-2 h-2 overflow-hidden rounded-full bg-slate-100">
                                    <div class="h-full rounded-full bg-slate-700" style="width: {{ $width }}%"></div>
                                </div>
                            </div>
                        @endforeach
                    </div>

                    <div class="mt-6 grid gap-3 sm:grid-cols-2">
                        <div class="rounded-2xl border border-slate-200 bg-slate-50 p-4">
                            <div class="text-xs font-bold uppercase tracking-wide text-slate-500">CTA engagement</div>
                            <div class="mt-1 text-xl font-bold text-slate-950">{{ number_format((int) $report['behavior']['cta_sessions']) }}</div>
                            <p class="mt-1 text-xs leading-5 text-slate-600">Likely-human sessions that clicked a tracked registration CTA.</p>
                        </div>
                        <div class="rounded-2xl border border-slate-200 bg-slate-50 p-4">
                            <div class="text-xs font-bold uppercase tracking-wide text-slate-500">Registration opened</div>
                            <div class="mt-1 text-xl font-bold text-slate-950">{{ number_format((int) $report['behavior']['registration_open_sessions']) }}</div>
                            <p class="mt-1 text-xs leading-5 text-slate-600">Tracked modal opens. Inline registration presentations do not need this interaction.</p>
                        </div>
                    </div>

                    @if($report['validation_fields'] !== [])
                        <div class="mt-6 rounded-2xl border border-amber-200 bg-amber-50 p-5">
                            <h3 class="font-semibold text-amber-950">Where validation failed</h3>
                            <p class="mt-1 text-sm leading-6 text-amber-900">
                                Safe field categories from validation-failure events. Counts are failure events, not unique people.
                            </p>
                            <div class="mt-4 flex flex-wrap gap-2">
                                @foreach($report['validation_fields'] as $field)
                                    <span class="inline-flex items-center gap-2 rounded-full bg-white px-3 py-1.5 text-sm font-semibold text-amber-950 ring-1 ring-amber-300">
                                        {{ \Illuminate\Support\Str::headline($field['value']) }}
                                        <span class="text-amber-700">{{ number_format((int) $field['count']) }}</span>
                                    </span>
                                @endforeach
                            </div>
                        </div>
                    @endif
                </div>
            </section>

            <section class="rounded-3xl border border-slate-200 bg-white/90 shadow-sm">
                <div class="border-b border-slate-100 p-6 sm:p-8">
                    <h2 class="text-xl font-semibold tracking-tight text-slate-950">Traffic quality</h2>
                    <p class="mt-1 max-w-3xl text-sm leading-6 text-slate-700">
                        Classification is intentionally conservative. Unknown traffic stays visible instead of being silently counted as human or automated.
                    </p>
                </div>

                <div class="grid gap-4 p-6 sm:grid-cols-3 sm:p-8">
                    @foreach([
                        ['key' => 'likely_human', 'label' => 'Likely human', 'description' => 'Recognized browser traffic with same-origin request signals.'],
                        ['key' => 'likely_automated', 'label' => 'Likely automated', 'description' => 'Explicit crawler, bot, or headless signals.'],
                        ['key' => 'unknown', 'label' => 'Unknown', 'description' => 'Not enough evidence to classify confidently.'],
                    ] as $trafficItem)
                        @php
                            $traffic = $report['traffic'][$trafficItem['key']];
                        @endphp
                        <article class="rounded-2xl border border-slate-200 bg-slate-50 p-5">
                            <div class="text-sm font-semibold text-slate-950">{{ $trafficItem['label'] }}</div>
                            <div class="mt-2 flex items-baseline gap-2">
                                <span class="text-3xl font-bold text-slate-950">{{ number_format((int) $traffic['count']) }}</span>
                                <span class="text-sm font-semibold text-slate-600">{{ $percent($traffic['share']) }}</span>
                            </div>
                            <p class="mt-2 text-xs leading-5 text-slate-600">{{ $trafficItem['description'] }}</p>
                        </article>
                    @endforeach
                </div>
            </section>

            <section class="rounded-3xl border border-slate-200 bg-white/90 shadow-sm">
                <div class="border-b border-slate-100 p-6 sm:p-8">
                    <h2 class="text-xl font-semibold tracking-tight text-slate-950">Campaign / source traffic</h2>
                    <p class="mt-1 max-w-3xl text-sm leading-6 text-slate-700">
                        Compare attributed traffic without assuming every campaign value represents paid media. Names come from safe UTM values; stable platform IDs are retained separately for reconciliation.
                    </p>
                </div>

                @if($report['campaigns'] === [])
                    <div class="p-8 text-sm text-slate-600">No attributed campaign traffic was projected in this date range.</div>
                @else
                    <div class="overflow-x-auto">
                        <table class="min-w-full text-sm">
                            <thead class="bg-slate-50 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">
                                <tr>
                                    <th class="px-5 py-3">Source / medium</th>
                                    <th class="px-5 py-3">Campaign</th>
                                    <th class="px-5 py-3">Group</th>
                                    <th class="px-5 py-3">Creative</th>
                                    <th class="px-5 py-3">Platform / placement</th>
                                    <th class="px-5 py-3 text-right">Landing</th>
                                    <th class="px-5 py-3 text-right">Likely human</th>
                                    <th class="px-5 py-3 text-right">Form starts</th>
                                    <th class="px-5 py-3 text-right">Reached submit</th>
                                    <th class="px-5 py-3 text-right">Registration</th>
                                    <th class="px-5 py-3 text-right">Validation</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100 bg-white">
                                @foreach($report['campaigns'] as $row)
                                    <tr>
                                        <td class="px-5 py-4 font-medium text-slate-900">
                                            {{ $row['dimensions']['utm_source'] ?? '—' }}
                                            <span class="text-slate-400">/</span>
                                            {{ $row['dimensions']['utm_medium'] ?? '—' }}
                                        </td>
                                        <td class="px-5 py-4 text-slate-700">
                                            <div>{{ $row['dimensions']['utm_campaign'] ?? '—' }}</div>
                                            @if(filled($row['dimensions']['external_campaign_id'] ?? null))
                                                <div class="mt-1 text-xs text-slate-400">ID {{ $row['dimensions']['external_campaign_id'] }}</div>
                                            @endif
                                        </td>
                                        <td class="px-5 py-4 text-slate-700">
                                            <div>{{ $row['dimensions']['utm_term'] ?? '—' }}</div>
                                            @if(filled($row['dimensions']['external_group_id'] ?? null))
                                                <div class="mt-1 text-xs text-slate-400">ID {{ $row['dimensions']['external_group_id'] }}</div>
                                            @endif
                                        </td>
                                        <td class="px-5 py-4 text-slate-700">
                                            <div>{{ $row['dimensions']['utm_content'] ?? '—' }}</div>
                                            @if(filled($row['dimensions']['external_creative_id'] ?? null))
                                                <div class="mt-1 text-xs text-slate-400">ID {{ $row['dimensions']['external_creative_id'] }}</div>
                                            @endif
                                        </td>
                                        <td class="px-5 py-4 text-slate-700">
                                            <div>{{ isset($row['dimensions']['external_platform']) ? \Illuminate\Support\Str::headline((string) $row['dimensions']['external_platform']) : '—' }}</div>
                                            @if(filled($row['dimensions']['external_placement'] ?? null))
                                                <div class="mt-1 text-xs text-slate-500">{{ \Illuminate\Support\Str::headline((string) $row['dimensions']['external_placement']) }}</div>
                                            @endif
                                        </td>
                                        <td class="px-5 py-4 text-right text-slate-700">{{ number_format((int) $row['landing_sessions']) }}</td>
                                        <td class="px-5 py-4 text-right text-slate-700">{{ $percent($row['human_share']) }}</td>
                                        <td class="px-5 py-4 text-right text-slate-700">{{ number_format((int) $row['form_starts']) }}</td>
                                        <td class="px-5 py-4 text-right text-slate-700">{{ number_format((int) $row['submit_sessions']) }}</td>
                                        <td class="px-5 py-4 text-right font-semibold text-slate-950">{{ $percent($row['registration_conversion']) }}</td>
                                        <td class="px-5 py-4 text-right text-slate-700">{{ $percent($row['validation_failure_rate']) }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </section>

            <section class="rounded-3xl border border-slate-200 bg-white/90 shadow-sm">
                <div class="border-b border-slate-100 p-6 sm:p-8">
                    <h2 class="text-xl font-semibold tracking-tight text-slate-950">Compare public-facing experience</h2>
                    <p class="mt-1 max-w-3xl text-sm leading-6 text-slate-700">
                        These breakdowns stay aligned with the dimensions actually retained in the daily aggregates. They do not pretend arbitrary cross-filtering is available when it was not projected.
                    </p>
                </div>

                <div class="divide-y divide-slate-100">
                    @foreach([
                        ['key' => 'paths', 'label' => 'Landing pages', 'identity' => 'path'],
                        ['key' => 'presentations', 'label' => 'Page revision / registration presentation', 'identity' => 'presentation'],
                        ['key' => 'devices', 'label' => 'Device class', 'identity' => 'device'],
                    ] as $breakdown)
                        <details class="group" @if(count($report[$breakdown['key']]) > 1) open @endif>
                            <summary class="flex cursor-pointer list-none items-center justify-between gap-4 p-5 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-slate-300 sm:px-8">
                                <div>
                                    <span class="font-semibold text-slate-950">{{ $breakdown['label'] }}</span>
                                    <span class="ml-2 text-sm text-slate-500">{{ count($report[$breakdown['key']]) }} observed</span>
                                </div>
                                <span class="text-sm font-semibold text-slate-600 group-open:hidden">Show</span>
                                <span class="hidden text-sm font-semibold text-slate-600 group-open:inline">Hide</span>
                            </summary>

                            <div class="overflow-x-auto border-t border-slate-100">
                                @if($report[$breakdown['key']] === [])
                                    <div class="p-6 text-sm text-slate-600">No {{ strtolower($breakdown['label']) }} breakdown is available for this period.</div>
                                @else
                                    <table class="min-w-full text-sm">
                                        <thead class="bg-slate-50 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">
                                            <tr>
                                                <th class="px-5 py-3">{{ $breakdown['label'] }}</th>
                                                <th class="px-5 py-3 text-right">Landing</th>
                                                <th class="px-5 py-3 text-right">Likely human</th>
                                                <th class="px-5 py-3 text-right">Form starts</th>
                                                <th class="px-5 py-3 text-right">Reached submit</th>
                                                <th class="px-5 py-3 text-right">Registration</th>
                                                <th class="px-5 py-3 text-right">Validation</th>
                                            </tr>
                                        </thead>
                                        <tbody class="divide-y divide-slate-100 bg-white">
                                            @foreach($report[$breakdown['key']] as $row)
                                                @php
                                                    $identity = match($breakdown['identity']) {
                                                        'path' => $row['dimensions']['path'] ?? 'Unknown path',
                                                        'presentation' => trim(implode(' · ', array_filter([
                                                            isset($row['dimensions']['presentation']) ? \Illuminate\Support\Str::headline((string) $row['dimensions']['presentation']) : null,
                                                            isset($row['dimensions']['page_revision']) ? \Illuminate\Support\Str::headline((string) $row['dimensions']['page_revision']) : null,
                                                        ]))) ?: 'Unknown presentation',
                                                        'device' => isset($row['dimensions']['device_class']) ? \Illuminate\Support\Str::headline((string) $row['dimensions']['device_class']) : 'Unknown device',
                                                        default => 'Unknown',
                                                    };
                                                @endphp
                                                <tr>
                                                    <td class="px-5 py-4 font-medium text-slate-900">{{ $identity }}</td>
                                                    <td class="px-5 py-4 text-right text-slate-700">{{ number_format((int) $row['landing_sessions']) }}</td>
                                                    <td class="px-5 py-4 text-right text-slate-700">{{ $percent($row['human_share']) }}</td>
                                                    <td class="px-5 py-4 text-right text-slate-700">{{ number_format((int) $row['form_starts']) }}</td>
                                                    <td class="px-5 py-4 text-right text-slate-700">{{ number_format((int) $row['submit_sessions']) }}</td>
                                                    <td class="px-5 py-4 text-right font-semibold text-slate-950">{{ $percent($row['registration_conversion']) }}</td>
                                                    <td class="px-5 py-4 text-right text-slate-700">{{ $percent($row['validation_failure_rate']) }}</td>
                                                </tr>
                                            @endforeach
                                        </tbody>
                                    </table>
                                @endif
                            </div>
                        </details>
                    @endforeach
                </div>
            </section>

            <section class="rounded-3xl border border-slate-200 bg-white/90 shadow-sm">
                <div class="border-b border-slate-100 p-6 sm:p-8">
                    <h2 class="text-xl font-semibold tracking-tight text-slate-950">After registration</h2>
                    <p class="mt-1 max-w-3xl text-sm leading-6 text-slate-700">
                        These metrics come from authoritative Webinar and Messaging outcomes. A provider-stage failure is not also counted as a confirmation-planning failure.
                    </p>
                </div>

                <div class="grid gap-4 p-6 sm:grid-cols-2 sm:p-8 xl:grid-cols-4">
                    @foreach([
                        ['label' => 'Local registrations', 'type' => 'count', 'value' => $report['after_registration']['local_registrations'], 'note' => 'Authoritative public Webinar registrations.'],
                        ['label' => 'Browser correlation', 'type' => 'ratio', 'value' => $report['after_registration']['correlation_coverage'], 'note' => 'Registrations matched to a browser submit attempt.'],
                        ['label' => 'Provider completion', 'type' => 'ratio', 'value' => $report['after_registration']['provider_completion'], 'note' => 'Provider-required registrations that completed provider sync.'],
                        ['label' => 'Confirmation planned', 'type' => 'ratio', 'value' => $report['after_registration']['confirmation_planning'], 'note' => 'Eligible completed registrations with confirmation planning.'],
                        ['label' => 'Confirmation delivered', 'type' => 'ratio', 'value' => $report['after_registration']['confirmation_delivery'], 'note' => 'Planned confirmations with authoritative sent outcomes.'],
                        ['label' => 'Joined', 'type' => 'ratio', 'value' => $report['after_registration']['join_rate'], 'note' => 'Eligible registrations after their occurrence started.'],
                        ['label' => 'Attended', 'type' => 'ratio', 'value' => $report['after_registration']['attendance_rate'], 'note' => 'Registrations in occurrences with finalized attendance.'],
                    ] as $metric)
                        <article class="rounded-2xl border border-slate-200 bg-slate-50 p-5">
                            <div class="text-xs font-bold uppercase tracking-wide text-slate-500">{{ $metric['label'] }}</div>
                            @if($metric['type'] === 'count')
                                <div class="mt-2 text-3xl font-bold text-slate-950">{{ number_format((int) $metric['value']) }}</div>
                            @else
                                <div class="mt-2 text-3xl font-bold text-slate-950">{{ $percent($metric['value']) }}</div>
                                <div class="mt-1 text-xs font-semibold text-slate-500">{{ $ratioDetail($metric['value']) }}</div>
                            @endif
                            <p class="mt-2 text-xs leading-5 text-slate-600">{{ $metric['note'] }}</p>
                        </article>
                    @endforeach
                </div>

                <details class="group border-t border-slate-100">
                    <summary class="flex cursor-pointer list-none items-center justify-between gap-4 p-5 sm:px-8">
                        <div>
                            <span class="font-semibold text-slate-950">Outcome details</span>
                            <span class="ml-2 text-sm text-slate-500">Why downstream percentages are not 100%</span>
                        </div>
                        <span class="text-sm font-semibold text-slate-600 group-open:hidden">Show</span>
                        <span class="hidden text-sm font-semibold text-slate-600 group-open:inline">Hide</span>
                    </summary>

                    <div class="grid gap-4 border-t border-slate-100 p-6 sm:grid-cols-2 sm:p-8 xl:grid-cols-4">
                        @foreach([
                            ['label' => 'Registration finalization', 'rows' => $report['finalization_outcomes']],
                            ['label' => 'Provider outcomes', 'rows' => $report['provider_outcomes']],
                            ['label' => 'Confirmation outcomes', 'rows' => $report['confirmation_outcomes']],
                            ['label' => 'Attendance outcomes', 'rows' => $report['attendance_outcomes']],
                        ] as $outcomeGroup)
                            <div class="rounded-2xl border border-slate-200 bg-slate-50 p-4">
                                <h3 class="text-sm font-semibold text-slate-950">{{ $outcomeGroup['label'] }}</h3>
                                @if($outcomeGroup['rows'] === [])
                                    <p class="mt-2 text-xs text-slate-500">No eligible outcomes in this period.</p>
                                @else
                                    <ul class="mt-3 space-y-2">
                                        @foreach($outcomeGroup['rows'] as $outcome)
                                            <li class="flex items-start justify-between gap-3 text-xs text-slate-700">
                                                <span>
                                                    {{ \Illuminate\Support\Str::headline($outcome['outcome']) }}
                                                    @if(!empty($outcome['reason']))
                                                        <span class="block text-slate-500">{{ \Illuminate\Support\Str::headline($outcome['reason']) }}</span>
                                                    @endif
                                                </span>
                                                <span class="font-bold text-slate-950">{{ number_format((int) $outcome['count']) }}</span>
                                            </li>
                                        @endforeach
                                    </ul>
                                @endif
                            </div>
                        @endforeach
                    </div>
                </details>

                <div class="border-t border-slate-100">
                    @foreach([
                        ['label' => 'Series outcomes', 'rows' => $report['series'], 'kind' => 'series'],
                        ['label' => 'Occurrence outcomes', 'rows' => $report['occurrences'], 'kind' => 'occurrence'],
                    ] as $group)
                        <details class="group">
                            <summary class="flex cursor-pointer list-none items-center justify-between gap-4 p-5 sm:px-8">
                                <span class="font-semibold text-slate-950">{{ $group['label'] }}</span>
                                <span class="text-sm font-semibold text-slate-600 group-open:hidden">Show</span>
                                <span class="hidden text-sm font-semibold text-slate-600 group-open:inline">Hide</span>
                            </summary>
                            <div class="overflow-x-auto border-t border-slate-100">
                                @if($group['rows'] === [])
                                    <div class="p-6 text-sm text-slate-600">No {{ strtolower($group['label']) }} are available for this period.</div>
                                @else
                                    <table class="min-w-full text-sm">
                                        <thead class="bg-slate-50 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">
                                            <tr>
                                                <th class="px-5 py-3">{{ $group['kind'] === 'series' ? 'Series' : 'Occurrence' }}</th>
                                                <th class="px-5 py-3 text-right">Registrations</th>
                                                <th class="px-5 py-3 text-right">Provider</th>
                                                <th class="px-5 py-3 text-right">Confirmation</th>
                                                <th class="px-5 py-3 text-right">Joined</th>
                                                <th class="px-5 py-3 text-right">Attended</th>
                                            </tr>
                                        </thead>
                                        <tbody class="divide-y divide-slate-100 bg-white">
                                            @foreach($group['rows'] as $row)
                                                @php
                                                    $slug = $group['kind'] === 'series'
                                                        ? ($row['dimensions']['series_slug'] ?? null)
                                                        : ($row['dimensions']['occurrence_slug'] ?? null);
                                                    $fallbackId = $group['kind'] === 'series'
                                                        ? ($row['dimensions']['series_id'] ?? null)
                                                        : ($row['dimensions']['occurrence_id'] ?? null);
                                                    $label = $slug
                                                        ? \Illuminate\Support\Str::headline((string) $slug)
                                                        : ($fallbackId ? ucfirst($group['kind']).' #'.$fallbackId : 'Unknown');
                                                @endphp
                                                <tr>
                                                    <td class="px-5 py-4 font-medium text-slate-900">{{ $label }}</td>
                                                    <td class="px-5 py-4 text-right text-slate-700">{{ number_format((int) $row['local_registrations']) }}</td>
                                                    <td class="px-5 py-4 text-right text-slate-700">{{ $percent($row['provider_completion']) }}</td>
                                                    <td class="px-5 py-4 text-right text-slate-700">{{ $percent($row['confirmation_delivery']) }}</td>
                                                    <td class="px-5 py-4 text-right text-slate-700">{{ $percent($row['join_rate']) }}</td>
                                                    <td class="px-5 py-4 text-right text-slate-700">{{ $percent($row['attendance_rate']) }}</td>
                                                </tr>
                                            @endforeach
                                        </tbody>
                                    </table>
                                @endif
                            </div>
                        </details>
                    @endforeach
                </div>
            </section>

            <details class="group rounded-3xl border border-slate-200 bg-white/90 shadow-sm">
                <summary class="flex cursor-pointer list-none items-center justify-between gap-4 p-6 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-slate-300 sm:p-8">
                    <div>
                        <h2 class="text-lg font-semibold text-slate-950">Technical collection signals</h2>
                        <p class="mt-1 text-sm leading-6 text-slate-600">
                            Secondary diagnostics for throttling and bot protection. This is not a security-monitoring workspace.
                        </p>
                    </div>
                    <span class="text-sm font-semibold text-slate-600 group-open:hidden">Show</span>
                    <span class="hidden text-sm font-semibold text-slate-600 group-open:inline">Hide</span>
                </summary>

                <div class="grid gap-6 border-t border-slate-100 p-6 sm:grid-cols-2 sm:p-8">
                    @foreach([
                        ['label' => 'Throttled requests', 'rows' => $report['diagnostics']['throttled_requests']],
                        ['label' => 'Bot-protection results', 'rows' => $report['diagnostics']['bot_protection_results']],
                    ] as $diagnostic)
                        <div>
                            <h3 class="font-semibold text-slate-950">{{ $diagnostic['label'] }}</h3>
                            @if($diagnostic['rows'] === [])
                                <p class="mt-2 text-sm text-slate-600">No recorded signals in this period.</p>
                            @else
                                <ul class="mt-3 space-y-2">
                                    @foreach($diagnostic['rows'] as $row)
                                        <li class="flex items-center justify-between gap-3 rounded-xl bg-slate-50 px-3 py-2 text-sm ring-1 ring-slate-200">
                                            <span>{{ \Illuminate\Support\Str::headline($row['value']) }}</span>
                                            <span class="font-semibold">{{ number_format((int) $row['count']) }}</span>
                                        </li>
                                    @endforeach
                                </ul>
                            @endif
                        </div>
                    @endforeach
                </div>
            </details>

            <section class="rounded-3xl border border-slate-200 bg-slate-50/80 p-6 text-sm text-slate-700 shadow-sm sm:p-8">
                <h2 class="font-semibold text-slate-950">How to read this report</h2>
                <ul class="mt-3 list-disc space-y-2 pl-5 leading-6">
                    <li>Primary registration conversion uses likely-human browser-observed landing sessions as its denominator.</li>
                    <li>Validation failure rate uses submit attempts, not landing sessions.</li>
                    <li>Imported or uncorrelated registrations are visible in authoritative totals but do not inflate browser conversion.</li>
                    <li>Provider, confirmation, join, and attendance percentages use their own eligible authoritative populations.</li>
                    <li>Campaign, page, presentation, device, series, and occurrence sections are separate retained breakdowns—not an arbitrary combined analytics cube.</li>
                </ul>
            </section>
        @endunless
    </div>
</x-layouts.crm>