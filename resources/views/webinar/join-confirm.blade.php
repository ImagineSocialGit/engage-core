<x-layouts.public title="Join Webinar">
    <main class="mx-auto flex min-h-screen w-full max-w-3xl items-center px-6 py-16">
        <section class="w-full rounded-3xl border border-slate-200 bg-white p-8 shadow-sm sm:p-12">
            <div class="space-y-6 text-center">
                <div class="space-y-2">
                    <p class="text-sm font-bold uppercase tracking-wide text-slate-500">
                        Webinar access
                    </p>

                    <h1 class="text-3xl font-extrabold tracking-tight text-slate-950">
                        Ready to join?
                    </h1>

                    <p class="text-base leading-7 text-slate-600">
                        Continue below when you are ready to open the webinar.
                    </p>
                </div>

                @if($registration->webinar)
                    <div class="rounded-2xl bg-slate-50 p-5 text-left">
                        <p class="text-sm font-semibold text-slate-500">
                            Webinar
                        </p>

                        <p class="mt-1 font-bold text-slate-900">
                            {{ $registration->webinar->webinarSeries?->name ?? $registration->webinar->title ?? 'Upcoming webinar' }}
                        </p>

                        @if($registration->webinar->starts_at)
                            <p class="mt-2 text-sm text-slate-600">
                                {{ $registration->webinar->starts_at->format('F j, Y \\a\\t g:i A T') }}
                            </p>
                        @endif
                    </div>
                @endif

                <form method="POST" action="{{ $continueUrl }}">
                    @csrf

                    <button
                        type="submit"
                        class="inline-flex w-full items-center justify-center rounded-xl bg-slate-950 px-6 py-3 text-sm font-extrabold text-white transition hover:bg-slate-800 focus:outline-none focus:ring-4 focus:ring-slate-300"
                    >
                        Join webinar
                    </button>
                </form>

                <p class="text-sm leading-6 text-slate-500">
                    Opening this page alone does not mark you as joined or change your reminder preferences.
                </p>
            </div>
        </section>
    </main>
</x-layouts.public>