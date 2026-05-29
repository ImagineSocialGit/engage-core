<x-layouts.public title="Unsubscribe Link Expired">
    <section class="px-6 py-20">
        <div class="mx-auto max-w-2xl">
            <div class="overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-sm">
                <div class="px-8 py-10 sm:px-10">
                    <div class="inline-flex items-center rounded-full border border-amber-200 bg-amber-50 px-3 py-1 text-sm font-medium text-amber-700">
                        Link Expired
                    </div>

                    <h1 class="mt-6 text-3xl font-bold tracking-tight text-slate-950 sm:text-4xl">
                        This unsubscribe link is no longer valid.
                    </h1>

                    <p class="mt-5 text-base leading-7 text-slate-600">
                        For your protection, unsubscribe links expire after a set period of time.
                    </p>

                    <p class="mt-4 text-base leading-7 text-slate-600">
                        Please use the unsubscribe link from the most recent email you received.
                    </p>

                    <div class="mt-10">
                        <a
                            href="{{ route('webinar.index') }}"
                            class="inline-flex items-center justify-center rounded-full bg-slate-950 px-6 py-3 text-sm font-semibold text-white transition hover:bg-slate-800"
                        >
                            Return to Webinars
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </section>
</x-layouts.public>