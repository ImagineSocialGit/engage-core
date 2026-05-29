<x-layouts.public title="Email Preferences Updated">
    <section class="px-6 py-20">
        <div class="mx-auto max-w-2xl">
            <div class="overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-sm">
                <div class="px-8 py-10 sm:px-10">
                    <div class="inline-flex items-center rounded-full border border-emerald-200 bg-emerald-50 px-3 py-1 text-sm font-medium text-emerald-700">
                        Preferences Updated
                    </div>

                    <h1 class="mt-6 text-3xl font-bold tracking-tight text-slate-950 sm:text-4xl">
                        You’ve been unsubscribed from marketing emails.
                    </h1>

                    <p class="mt-5 text-base leading-7 text-slate-600">
                        Your email preferences have been updated successfully.
                    </p>

                    <p class="mt-4 text-base leading-7 text-slate-600">
                        You may still receive transactional emails related to webinar registrations,
                        reminders, account activity, or other operational communications if you have
                        separately consented to those messages.
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