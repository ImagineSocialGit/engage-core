@php
    $schedulingPercent = static fn (array $metric): string => $metric['percent'] === null
        ? '—'
        : number_format((float) $metric['percent'], 1).'%';
    $schedulingRatio = static fn (array $metric): string => $metric['denominator'] > 0
        ? number_format((int) $metric['numerator']).' / '.number_format((int) $metric['denominator'])
        : 'No eligible sessions';
    $campaignLabel = static function (array $dimensions): string {
        $parts = array_filter([
            $dimensions['utm_source'] ?? null,
            $dimensions['utm_campaign'] ?? null,
            $dimensions['utm_content'] ?? null,
            $dimensions['referrer_host'] ?? null,
        ], fn (mixed $value): bool => is_string($value) && trim($value) !== '');

        return $parts === []
            ? 'Unlabeled source'
            : implode(' · ', array_map(
                fn (string $value): string => \Illuminate\Support\Str::headline($value),
                $parts,
            ));
    };
@endphp

<section
    id="scheduling-booking-report"
    class="scroll-mt-6 rounded-3xl border border-slate-200 bg-white/90 shadow-sm"
    data-report-surface="scheduling-public-booking"
>
    <div class="border-b border-slate-100 p-5 sm:p-8">
        <p class="text-sm font-semibold uppercase tracking-[0.16em] text-teal-700">Appointment booking</p>
        <h2 class="mt-2 text-2xl font-semibold tracking-tight text-slate-950">See where people stop before booking</h2>
        <p class="mt-3 max-w-3xl text-sm leading-6 text-slate-700">
            This follows privacy-safe public-booking activity from the service list through an authoritative appointment. Likely automated and unresolved traffic stay outside the primary conversion rate.
        </p>
        <div class="mt-4 flex flex-wrap gap-x-5 gap-y-1 text-xs text-slate-500">
            <span>{{ $schedulingReport['range']['from']->format('M j, Y') }} – {{ $schedulingReport['range']['through']->format('M j, Y') }}</span>
            @if($schedulingReport['updated_at'])
                <span>Updated through {{ $schedulingReport['updated_at']->timezone($schedulingReport['timezone'])->format('M j, Y g:i A') }}</span>
            @endif
        </div>
    </div>

    @if($schedulingReport['has_data'])
        <div class="grid gap-4 p-5 sm:grid-cols-2 sm:p-8 xl:grid-cols-5">
            @foreach([
                ['label' => 'Likely-human visits', 'value' => number_format((int) $schedulingReport['summary']['likely_human_sessions']), 'detail' => number_format((int) $schedulingReport['summary']['observed_sessions']).' observed'],
                ['label' => 'Booked appointments', 'value' => number_format((int) $schedulingReport['summary']['public_appointments']), 'detail' => 'Authoritative local appointments'],
                ['label' => 'Booking conversion', 'value' => $schedulingPercent($schedulingReport['summary']['booking_conversion']), 'detail' => $schedulingRatio($schedulingReport['summary']['booking_conversion'])],
                ['label' => 'Validation failures', 'value' => $schedulingPercent($schedulingReport['summary']['validation_failure_rate']), 'detail' => $schedulingRatio($schedulingReport['summary']['validation_failure_rate'])],
                ['label' => 'Browser correlation', 'value' => $schedulingPercent($schedulingReport['summary']['correlation_coverage']), 'detail' => $schedulingRatio($schedulingReport['summary']['correlation_coverage'])],
            ] as $metric)
                <article class="rounded-2xl border border-slate-200 bg-slate-50 p-4">
                    <div class="text-xs font-bold uppercase tracking-wide text-slate-500">{{ $metric['label'] }}</div>
                    <div class="mt-2 text-2xl font-bold text-slate-950">{{ $metric['value'] }}</div>
                    <div class="mt-1 text-xs text-slate-600">{{ $metric['detail'] }}</div>
                </article>
            @endforeach
        </div>

        @if($schedulingReport['largest_drop'])
            <div class="border-t border-slate-100 p-5 sm:px-8">
                <div class="rounded-2xl border border-amber-200 bg-amber-50 p-4 text-amber-950" data-scheduling-largest-drop>
                    <div class="text-xs font-bold uppercase tracking-wide text-amber-800">Largest measured drop</div>
                    <div class="mt-1 font-semibold">
                        {{ $schedulingReport['largest_drop']['from'] }} → {{ $schedulingReport['largest_drop']['to'] }}
                    </div>
                    <p class="mt-1 text-sm leading-6">
                        {{ number_format((int) $schedulingReport['largest_drop']['lost_sessions']) }} likely-human session(s) stopped between these measured stages ({{ number_format((float) $schedulingReport['largest_drop']['loss_percent'], 1) }}%). Treat this as the first place to investigate, not proof of causation.
                    </p>
                </div>
            </div>
        @endif

        <div class="border-t border-slate-100 p-5 sm:p-8">
            <h3 class="font-semibold text-slate-950">Booking funnel</h3>
            <p class="mt-1 text-sm leading-6 text-slate-600">Each stage counts distinct likely-human browser sessions. Code verification is shown separately because some services may not require it.</p>
            <div class="mt-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-4" data-scheduling-funnel>
                @foreach($schedulingReport['funnel'] as $stage)
                    <article class="rounded-2xl border border-slate-200 p-4">
                        <div class="text-xs font-bold uppercase tracking-wide text-slate-500">{{ $stage['label'] }}</div>
                        <div class="mt-2 text-2xl font-bold text-slate-950">{{ number_format((int) $stage['count']) }}</div>
                        <div class="mt-1 text-xs text-slate-600">
                            @if($stage['from_previous_percent'] !== null)
                                {{ number_format((float) $stage['from_previous_percent'], 1) }}% of prior stage
                            @else
                                Starting population
                            @endif
                        </div>
                    </article>
                @endforeach
            </div>
        </div>

        <div class="grid gap-6 border-t border-slate-100 p-5 sm:p-8 lg:grid-cols-3">
            @foreach([
                ['label' => 'Where validation failed', 'rows' => $schedulingReport['validation_fields'], 'value' => 'value'],
                ['label' => 'Availability results', 'rows' => $schedulingReport['availability_outcomes'], 'value' => 'value'],
                ['label' => 'Appointment outcomes', 'rows' => $schedulingReport['appointment_outcomes'], 'value' => 'value'],
            ] as $breakdown)
                <div>
                    <h3 class="font-semibold text-slate-950">{{ $breakdown['label'] }}</h3>
                    @if($breakdown['rows'] === [])
                        <p class="mt-2 text-sm text-slate-600">No recorded results in this period.</p>
                    @else
                        <ul class="mt-3 space-y-2">
                            @foreach($breakdown['rows'] as $row)
                                <li class="flex items-center justify-between gap-3 rounded-xl bg-slate-50 px-3 py-2 text-sm ring-1 ring-slate-200">
                                    <span>{{ \Illuminate\Support\Str::headline($row[$breakdown['value']]) }}</span>
                                    <span class="font-bold">{{ number_format((int) $row['count']) }}</span>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </div>
            @endforeach
        </div>

        <details class="group border-t border-slate-100" data-scheduling-campaign-report>
            <summary class="flex cursor-pointer list-none items-center justify-between gap-4 p-5 sm:px-8">
                <div>
                    <span class="font-semibold text-slate-950">Campaign and source performance</span>
                    <span class="ml-2 text-sm text-slate-500">First-party visits, bookings, and Meta-click evidence</span>
                </div>
                <span class="text-sm font-semibold text-slate-600 group-open:hidden">Show</span>
                <span class="hidden text-sm font-semibold text-slate-600 group-open:inline">Hide</span>
            </summary>
            <div class="overflow-x-auto border-t border-slate-100">
                @if($schedulingReport['campaigns'] === [])
                    <div class="p-6 text-sm text-slate-600">No tagged campaign or referrer traffic is available for this period.</div>
                @else
                    <table class="min-w-[58rem] text-sm">
                        <thead class="bg-slate-50 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">
                            <tr>
                                <th class="px-5 py-3">Campaign / source</th>
                                <th class="px-5 py-3 text-right">Observed</th>
                                <th class="px-5 py-3 text-right">Likely human</th>
                                <th class="px-5 py-3 text-right">Selected time</th>
                                <th class="px-5 py-3 text-right">Reached submit</th>
                                <th class="px-5 py-3 text-right">Appointments</th>
                                <th class="px-5 py-3 text-right">Conversion</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 bg-white">
                            @foreach($schedulingReport['campaigns'] as $row)
                                <tr>
                                    <td class="px-5 py-4">
                                        <div class="font-medium text-slate-900">{{ $campaignLabel($row['dimensions']) }}</div>
                                        @if(($row['dimensions']['external_campaign_id'] ?? null) || ($row['dimensions']['external_creative_id'] ?? null))
                                            <div class="mt-1 text-xs text-slate-500">
                                                Campaign {{ $row['dimensions']['external_campaign_id'] ?? '—' }} · Creative {{ $row['dimensions']['external_creative_id'] ?? '—' }}
                                            </div>
                                        @endif
                                    </td>
                                    <td class="px-5 py-4 text-right">{{ number_format((int) $row['observed_sessions']) }}</td>
                                    <td class="px-5 py-4 text-right">{{ number_format((int) $row['likely_human_sessions']) }}</td>
                                    <td class="px-5 py-4 text-right">{{ number_format((int) $row['time_selected_sessions']) }}</td>
                                    <td class="px-5 py-4 text-right">{{ number_format((int) $row['submit_sessions']) }}</td>
                                    <td class="px-5 py-4 text-right font-semibold">{{ number_format((int) $row['attributed_appointments']) }}</td>
                                    <td class="px-5 py-4 text-right font-semibold">{{ $schedulingPercent($row['booking_conversion']) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif
            </div>
        </details>

        <details class="group border-t border-slate-100" data-scheduling-service-report>
            <summary class="flex cursor-pointer list-none items-center justify-between gap-4 p-5 sm:px-8">
                <span class="font-semibold text-slate-950">Performance by service</span>
                <span class="text-sm font-semibold text-slate-600 group-open:hidden">Show</span>
                <span class="hidden text-sm font-semibold text-slate-600 group-open:inline">Hide</span>
            </summary>
            <div class="overflow-x-auto border-t border-slate-100">
                @if($schedulingReport['services'] === [])
                    <div class="p-6 text-sm text-slate-600">No service-level results are available for this period.</div>
                @else
                    <table class="min-w-[44rem] text-sm">
                        <thead class="bg-slate-50 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">
                            <tr>
                                <th class="px-5 py-3">Service</th>
                                <th class="px-5 py-3 text-right">Likely human</th>
                                <th class="px-5 py-3 text-right">Selected time</th>
                                <th class="px-5 py-3 text-right">Appointments</th>
                                <th class="px-5 py-3 text-right">Conversion</th>
                                <th class="px-5 py-3 text-right">Validation</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 bg-white">
                            @foreach($schedulingReport['services'] as $row)
                                <tr>
                                    <td class="px-5 py-4 font-medium text-slate-900">{{ \Illuminate\Support\Str::headline((string) ($row['dimensions']['service_key'] ?? 'Unknown service')) }}</td>
                                    <td class="px-5 py-4 text-right">{{ number_format((int) $row['likely_human_sessions']) }}</td>
                                    <td class="px-5 py-4 text-right">{{ number_format((int) $row['time_selected_sessions']) }}</td>
                                    <td class="px-5 py-4 text-right font-semibold">{{ number_format((int) $row['public_appointments']) }}</td>
                                    <td class="px-5 py-4 text-right">{{ $schedulingPercent($row['booking_conversion']) }}</td>
                                    <td class="px-5 py-4 text-right">{{ $schedulingPercent($row['validation_failure_rate']) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif
            </div>
        </details>

        <details class="group border-t border-slate-100" data-scheduling-ad-comparison>
            <summary class="flex cursor-pointer list-none items-center justify-between gap-4 p-5 sm:px-8">
                <span class="font-semibold text-slate-950">Ad platform comparison</span>
                <span class="text-sm font-semibold text-slate-600 group-open:hidden">Show</span>
                <span class="hidden text-sm font-semibold text-slate-600 group-open:inline">Hide</span>
            </summary>
            <div class="grid gap-4 border-t border-slate-100 p-5 sm:p-8 lg:grid-cols-2">
                @forelse($schedulingReport['ad_platform_comparisons'] as $comparison)
                    <article class="rounded-2xl border border-slate-200 bg-slate-50 p-4">
                        <div class="flex items-start justify-between gap-4">
                            <div>
                                <div class="font-semibold text-slate-950">{{ \Illuminate\Support\Str::headline($comparison['platform']) }}</div>
                                <div class="mt-1 text-xs text-slate-500">{{ $comparison['period_start']->format('M j') }} – {{ $comparison['period_end']->format('M j, Y') }}</div>
                            </div>
                            <div class="text-right text-sm">
                                <div class="font-semibold">{{ $comparison['external']['spend'] !== null ? ($comparison['currency'] ? $comparison['currency'].' ' : '').number_format((float) $comparison['external']['spend'], 2) : 'Spend unavailable' }}</div>
                                <div class="mt-1 text-xs text-slate-500">{{ number_format((int) $comparison['matched_stable_rows']) }} exact row match(es)</div>
                            </div>
                        </div>
                        @if($comparison['exact_comparison']['available'])
                            <div class="mt-4 grid grid-cols-2 gap-3 text-sm">
                                <div><span class="font-semibold">Likely-human visits:</span> {{ number_format((int) $comparison['exact_comparison']['engage_likely_human_sessions']) }}</div>
                                <div><span class="font-semibold">Appointments:</span> {{ number_format((int) $comparison['exact_comparison']['engage_appointments']) }}</div>
                                <div class="col-span-2"><span class="font-semibold">Cost / appointment:</span> {{ $comparison['exact_comparison']['cost_per_appointment'] !== null ? ($comparison['currency'] ? $comparison['currency'].' ' : '').number_format((float) $comparison['exact_comparison']['cost_per_appointment'], 2) : '—' }}</div>
                            </div>
                        @elseif($comparison['name_fallback_rows'] > 0)
                            <p class="mt-4 text-sm leading-6 text-amber-900">This export lacks stable campaign/ad IDs, so Reporting will not claim an exact match from names alone.</p>
                        @else
                            <p class="mt-4 text-sm leading-6 text-slate-600">No matching tracked appointment-booking traffic was found for these stable IDs.</p>
                        @endif
                    </article>
                @empty
                    <p class="text-sm text-slate-600">No ad-platform report overlaps this date range. Import one from Reporting to compare spend with attributed appointments.</p>
                @endforelse
            </div>
        </details>

        <details class="group border-t border-slate-100" data-scheduling-verification-report>
            <summary class="flex cursor-pointer list-none items-center justify-between gap-4 p-5 sm:px-8">
                <span class="font-semibold text-slate-950">Code verification activity</span>
                <span class="text-sm font-semibold text-slate-600 group-open:hidden">Show</span>
                <span class="hidden text-sm font-semibold text-slate-600 group-open:inline">Hide</span>
            </summary>
            <div class="border-t border-slate-100 p-5 sm:p-8">
                @if($schedulingReport['verification_channels'] === [])
                    <p class="text-sm text-slate-600">No code-verification activity was recorded in this period.</p>
                @else
                    <div class="flex flex-wrap gap-2">
                        @foreach($schedulingReport['verification_channels'] as $row)
                            <span class="rounded-full bg-slate-100 px-3 py-2 text-sm text-slate-800 ring-1 ring-slate-200">
                                {{ \Illuminate\Support\Str::headline($row['channel']) }} · {{ \Illuminate\Support\Str::headline($row['stage']) }}: <strong>{{ number_format((int) $row['count']) }}</strong>
                            </span>
                        @endforeach
                    </div>
                @endif
            </div>
        </details>
    @else
        <div class="p-8 text-center" data-scheduling-report-empty>
            <h3 class="font-semibold text-slate-950">No appointment-booking data in this period yet</h3>
            <p class="mx-auto mt-2 max-w-2xl text-sm leading-6 text-slate-600">Once visitors use the tracked public booking page and the Reporting projection runs, funnel and attribution results will appear here.</p>
        </div>
    @endif
</section>