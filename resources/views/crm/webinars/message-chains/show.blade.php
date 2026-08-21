<x-layouts.crm :title="$title" :heading="$heading">
    <div class="space-y-6">
        @if (session('success'))
            <div class="rounded-2xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-semibold text-emerald-800">
                {{ session('success') }}
            </div>
        @endif

        @if (session('error'))
            <div class="rounded-2xl border border-red-200 bg-red-50 px-4 py-3 text-sm font-semibold text-red-800">
                {{ session('error') }}
            </div>
        @endif

        @if ($errors->any())
            <div class="rounded-2xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">
                <p class="font-bold">The message copy could not be saved.</p>
                <ul class="mt-2 list-disc space-y-1 pl-5">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <section class="rounded-3xl border border-slate-200 bg-white p-4 shadow-sm sm:p-6">
            <div class="flex flex-col gap-5 lg:flex-row lg:items-start lg:justify-between">
                <div class="max-w-3xl">
                    <p class="text-xs font-extrabold uppercase tracking-[0.18em] text-slate-500">
                        Webinar message sequence
                    </p>
                    <h2 class="mt-2 text-2xl font-black tracking-tight text-slate-950">
                        Review {{ $series->title }} messages
                    </h2>
                    <p class="mt-2 text-sm leading-6 text-slate-600">
                        Switch between Email and SMS, then move through one message at a time in the order it appears in the configured sequence.
                    </p>

                    <div class="mt-4 flex flex-wrap gap-2 text-xs font-bold text-slate-600">
                        <span class="rounded-full bg-slate-100 px-3 py-1.5">
                            {{ $scheduleProfile?->name ?? 'No active schedule profile' }}
                        </span>
                        <span class="rounded-full {{ $bindings->isNotEmpty() ? 'bg-indigo-100 text-indigo-800' : 'bg-slate-100' }} px-3 py-1.5">
                            {{ $bindings->isNotEmpty() ? 'Customized for this series' : 'Using shared defaults' }}
                        </span>
                        <span class="rounded-full bg-slate-100 px-3 py-1.5">
                            {{ (int) ($messageReview['message_count'] ?? 0) }} {{ (int) ($messageReview['message_count'] ?? 0) === 1 ? 'message' : 'messages' }}
                        </span>
                    </div>
                </div>

                <div class="grid gap-2 sm:flex sm:flex-wrap">
                    @if(\Illuminate\Support\Facades\Route::has('crm.messaging.message-templates.index'))
                        <a
                            href="{{ route('crm.messaging.message-templates.index', ['module' => 'webinars']) }}"
                            class="inline-flex min-h-11 w-full items-center justify-center rounded-full border border-slate-300 bg-white px-5 text-center text-sm font-extrabold text-slate-700 transition hover:bg-slate-50 sm:w-auto"
                        >
                            Message Templates
                        </a>
                    @endif

                    <a
                        href="{{ route('crm.webinar-series.index') }}"
                        class="inline-flex min-h-11 w-full items-center justify-center rounded-full bg-slate-950 px-5 text-center text-sm font-extrabold text-white transition hover:bg-slate-800 sm:w-auto"
                    >
                        Back to webinars
                    </a>
                </div>
            </div>
        </section>

        @if($bindings->isNotEmpty())
            <section class="rounded-2xl border border-amber-200 bg-amber-50/60 px-5 py-4 text-sm text-amber-950">
                <p class="font-bold">Changes apply to future enrollments.</p>
                <p class="mt-1 leading-6">
                    Publishing an edit creates a new immutable template and chain version for this series. Existing enrollments stay pinned to the version they already started with.
                </p>
            </section>
        @endif

        <section
            data-webinar-message-carousel
            data-webinar-message-ownership="{{ $bindings->isNotEmpty() ? 'series' : 'shared' }}"
            class="space-y-4"
        >
            <x-messaging.message-chain-carousel
                :presentation="$messageReview"
                :editable="$bindings->isNotEmpty()"
                empty-message="No effective Webinar messages are available for this series."
            />
        </section>

        @if($bindings->isEmpty())
            <section class="rounded-3xl border border-indigo-200 bg-indigo-50/50 p-4 shadow-sm sm:p-6">
                <div class="max-w-3xl">
                    <p class="text-xs font-extrabold uppercase tracking-[0.18em] text-indigo-700">
                        Customize only this series
                    </p>
                    <h2 class="mt-2 text-xl font-black tracking-tight text-slate-950">
                        Create series-specific messages
                    </h2>
                    <p class="mt-2 text-sm leading-6 text-slate-700">
                        The preview above is using the current shared defaults. Create a custom sequence only when this series needs different wording. The new sequence initially reuses the same immutable templates, then publishes series-owned versions only for messages you actually edit.
                    </p>
                </div>

                <form
                    method="POST"
                    action="{{ route('crm.webinar-series.message-chains.duplicate', $series) }}"
                    class="mt-6 max-w-xl space-y-4"
                >
                    @csrf

                    <div>
                        <label for="source_webinar_series_id" class="block text-sm font-bold text-slate-900">
                            Start from
                        </label>
                        <select
                            id="source_webinar_series_id"
                            name="source_webinar_series_id"
                            class="mt-1 block w-full rounded-xl border border-slate-300 bg-white px-4 py-3 text-sm text-slate-900 shadow-sm focus:border-slate-500 focus:outline-none focus:ring-0"
                        >
                            <option value="">This series' current shared defaults</option>
                            @foreach ($sourceSeriesOptions as $sourceSeries)
                                <option value="{{ $sourceSeries->getKey() }}">
                                    {{ $sourceSeries->title }}
                                </option>
                            @endforeach
                        </select>
                        <p class="mt-2 text-xs leading-5 text-slate-600">
                            Choosing another series copies its effective published messages as a starting point without linking future edits.
                        </p>
                    </div>

                    <button
                        type="submit"
                        class="inline-flex min-h-11 w-full items-center justify-center rounded-full bg-slate-950 px-5 text-center text-sm font-extrabold text-white transition hover:bg-slate-800 sm:w-auto"
                    >
                        Create custom messages
                    </button>
                </form>
            </section>
        @endif
    </div>
</x-layouts.crm>