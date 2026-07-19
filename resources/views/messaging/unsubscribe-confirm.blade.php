<x-layouts.public title="Confirm Unsubscribe">
    <section class="px-6 py-20">
        <div class="mx-auto max-w-2xl">
            <div class="overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-sm">
                <div class="px-8 py-10 sm:px-10">
                    <div class="inline-flex items-center rounded-full border border-slate-200 bg-slate-50 px-3 py-1 text-sm font-medium text-slate-700">
                        Confirm Your Request
                    </div>

                    <h1 class="mt-6 text-3xl font-bold tracking-tight text-slate-950 sm:text-4xl">
                        Unsubscribe from marketing emails?
                    </h1>

                    <p class="mt-5 text-base leading-7 text-slate-600">
                        Confirm that you no longer want to receive marketing emails.
                    </p>

                    <p class="mt-4 text-base leading-7 text-slate-600">
                        You may still receive transactional messages related to registrations,
                        account activity, or other services you requested.
                    </p>

                    <form method="POST" action="{{ $confirmUrl }}" class="mt-10">
                        @csrf

                        <button
                            type="submit"
                            class="inline-flex items-center justify-center rounded-full bg-slate-950 px-6 py-3 text-sm font-semibold text-white transition hover:bg-slate-800"
                        >
                            Confirm Unsubscribe
                        </button>
                    </form>

                    <p class="mt-6 text-sm leading-6 text-slate-500">
                        If you do not want to unsubscribe, you can safely close this page.
                    </p>
                </div>
            </div>
        </div>
    </section>
</x-layouts.public>