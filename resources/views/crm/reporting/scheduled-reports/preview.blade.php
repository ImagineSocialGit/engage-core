<x-layouts.crm
    :title="$title"
    :heading="$heading"
    :subheading="$subheading"
    module="reporting"
>
    <div class="w-full max-w-5xl space-y-6">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <button
                type="button"
                onclick="window.history.back()"
                class="text-sm font-semibold text-slate-700 underline decoration-slate-300 underline-offset-4"
            >
                Back to unsaved settings
            </button>

            <a
                href="{{ route('crm.reporting.scheduled-reports.index') }}"
                class="text-sm font-semibold text-slate-600 hover:underline"
            >
                Scheduled reports
            </a>
        </div>

        <section class="rounded-3xl border border-slate-200 bg-white shadow-sm">
            <div class="border-b border-slate-100 p-5 sm:p-8">
                <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                    <div>
                        <p class="text-xs font-bold uppercase tracking-[0.18em] text-slate-500">{{ $reportLabel }}</p>
                        <h2 class="mt-2 text-xl font-semibold text-slate-950">{{ $previewResult->subject }}</h2>
                        <p class="mt-2 text-sm leading-6 text-slate-600">{{ $previewResult->preheader }}</p>
                    </div>
                    <div class="rounded-2xl bg-slate-50 px-4 py-3 text-xs text-slate-600 ring-1 ring-slate-200">
                        Generated {{ $generatedAt->format('M j, Y g:i A T') }}
                    </div>
                </div>
            </div>

            <div class="space-y-6 p-5 sm:p-8">
                <div>
                    <h3 class="text-lg font-semibold text-slate-950">{{ $previewResult->headline }}</h3>
                    <div class="mt-4 space-y-3 text-sm leading-6 text-slate-700">
                        @foreach($previewResult->body as $line)
                            @if($line === '')
                                <div class="h-1"></div>
                            @else
                                <p class="whitespace-pre-line">{{ $line }}</p>
                            @endif
                        @endforeach
                    </div>
                </div>

                @if($previewResult->details !== [])
                    <div class="grid gap-3 border-t border-slate-100 pt-6 sm:grid-cols-2 lg:grid-cols-3">
                        @foreach($previewResult->details as $label => $value)
                            <div class="rounded-2xl bg-slate-50 p-4 ring-1 ring-slate-200">
                                <div class="text-xs font-bold uppercase tracking-wide text-slate-500">{{ $label }}</div>
                                <div class="mt-1 text-lg font-semibold text-slate-950">{{ $value }}</div>
                            </div>
                        @endforeach
                    </div>
                @endif

                @if($previewResult->cta)
                    <div class="border-t border-slate-100 pt-6">
                        <a
                            href="{{ $previewResult->cta['url'] }}"
                            class="inline-flex rounded-xl bg-slate-950 px-4 py-2 text-sm font-semibold text-white"
                        >
                            {{ $previewResult->cta['label'] }}
                        </a>
                    </div>
                @endif
            </div>
        </section>

        <section class="rounded-2xl border border-amber-200 bg-amber-50 p-5 text-sm leading-6 text-amber-950">
            This is a live preview. Saving the schedule controls when and to whom it is delivered; previewing it does not create, update, or send a scheduled report.
        </section>
    </div>
</x-layouts.crm>