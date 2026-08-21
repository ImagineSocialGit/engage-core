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
                        {{ $series->title }}
                    </h2>
                    <p class="mt-2 text-sm leading-6 text-slate-600">
                        Review one message at a time. Choose Edit to replace the published preview with the editable copy, then save and continue through the sequence.
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

        <section class="rounded-2xl border {{ $bindings->isNotEmpty() ? 'border-amber-200 bg-amber-50/60' : 'border-indigo-200 bg-indigo-50/60' }} px-5 py-4 text-sm {{ $bindings->isNotEmpty() ? 'text-amber-950' : 'text-indigo-950' }}">
            @if($bindings->isNotEmpty())
                <p class="font-bold">Changes apply to future enrollments.</p>
                <p class="mt-1 leading-6">
                    Saving publishes a new immutable template and chain version for this series. Existing enrollments stay pinned to the version they already started with.
                </p>
            @else
                <p class="font-bold">This series currently uses shared message defaults.</p>
                <p class="mt-1 leading-6">
                    You can still edit directly below. The first edit automatically creates a series-specific copy before publishing, so other Webinar series keep their shared wording.
                </p>
            @endif
        </section>

        <section
            data-webinar-message-carousel
            data-webinar-message-ownership="{{ $bindings->isNotEmpty() ? 'series' : 'shared' }}"
        >
            <x-messaging.message-editor-carousel
                :presentation="$messageReview"
                :editable="true"
                empty-message="No effective Webinar messages are available for this series."
            />
        </section>

        @if($bindings->isEmpty() && $sourceSeriesOptions->isNotEmpty())
            <details class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm sm:p-5">
                <summary class="cursor-pointer text-sm font-extrabold text-slate-900">
                    Advanced: start the whole sequence from another Webinar series
                </summary>

                <form
                    method="POST"
                    action="{{ route('crm.webinar-series.message-chains.duplicate', $series) }}"
                    class="mt-5 max-w-xl space-y-4"
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
                            @foreach ($sourceSeriesOptions as $sourceSeries)
                                <option value="{{ $sourceSeries->getKey() }}">
                                    {{ $sourceSeries->title }}
                                </option>
                            @endforeach
                        </select>
                        <p class="mt-2 text-xs leading-5 text-slate-600">
                            This copies the other series' effective published sequence as the starting point. Future edits are independent.
                        </p>
                    </div>

                    <button
                        type="submit"
                        class="inline-flex min-h-11 w-full items-center justify-center rounded-full bg-slate-950 px-5 text-center text-sm font-extrabold text-white transition hover:bg-slate-800 sm:w-auto"
                    >
                        Copy sequence
                    </button>
                </form>
            </details>
        @endif
    </div>
</x-layouts.crm>