<x-layouts.public title="Cancel Webinar Registration">
    <main class="mx-auto flex min-h-screen w-full max-w-3xl items-center px-6 py-16">
        <section class="w-full rounded-3xl border border-slate-200 bg-white p-8 shadow-sm sm:p-12">
            <div class="space-y-6 text-center">
                <div class="space-y-2">
                    <p class="text-sm font-bold uppercase tracking-wide text-slate-500">
                        Webinar registration
                    </p>

                    @if($cancellationState === 'already_cancelled')
                        <h1 class="text-3xl font-extrabold tracking-tight text-slate-950">
                            Your registration is already cancelled
                        </h1>

                        <p class="text-base leading-7 text-slate-600">
                            No further action is needed.
                        </p>
                    @elseif($cancellationState === 'ineligible')
                        <h1 class="text-3xl font-extrabold tracking-tight text-slate-950">
                            Cancellation is no longer available
                        </h1>

                        <p class="text-base leading-7 text-slate-600">
                            This registration can no longer be cancelled.
                        </p>
                    @else
                        <h1 class="text-3xl font-extrabold tracking-tight text-slate-950">
                            Cancel your registration?
                        </h1>

                        <p class="text-base leading-7 text-slate-600">
                            You will no longer receive reminders for this webinar.
                        </p>
                    @endif
                </div>

                @if($registration->webinar?->webinarSeries?->title)
                    <div class="rounded-2xl bg-slate-50 p-5 text-left">
                        <p class="text-sm font-semibold text-slate-500">
                            Webinar
                        </p>

                        <p class="mt-1 font-bold text-slate-900">
                            {{ $registration->webinar->webinarSeries->title }}
                        </p>

                        @if($registration->webinar?->starts_at)
                            <p class="mt-2 text-sm text-slate-600">
                                {{ $registration->webinar->starts_at->format('F j, Y \\a\\t g:i A T') }}
                            </p>
                        @endif
                    </div>
                @endif

                @if($cancellationState === 'cancellable')
                    <div class="grid gap-3 sm:grid-cols-2">
                        <a
                            href="{{ route('webinar.index') }}"
                            class="inline-flex w-full items-center justify-center rounded-xl border border-slate-300 bg-white px-6 py-3 text-sm font-extrabold text-slate-700 transition hover:bg-slate-50 focus:outline-none focus:ring-4 focus:ring-slate-200"
                        >
                            Keep my registration
                        </a>

                        <form method="POST" action="{{ $cancelUrl }}">
                            @csrf

                            <button
                                type="submit"
                                class="inline-flex w-full items-center justify-center rounded-xl bg-slate-950 px-6 py-3 text-sm font-extrabold text-white transition hover:bg-slate-800 focus:outline-none focus:ring-4 focus:ring-slate-300"
                            >
                                Cancel registration
                            </button>
                        </form>
                    </div>
                @else
                    <a
                        href="{{ route('webinar.index') }}"
                        class="inline-flex w-full items-center justify-center rounded-xl bg-slate-950 px-6 py-3 text-sm font-extrabold text-white transition hover:bg-slate-800 focus:outline-none focus:ring-4 focus:ring-slate-300"
                    >
                        View upcoming webinars
                    </a>
                @endif
            </div>
        </section>
    </main>
</x-layouts.public>