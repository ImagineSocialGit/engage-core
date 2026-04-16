<x-layouts.public title="Webinar">
    <section class="mx-auto w-full max-w-4xl px-6 py-20">
        <div class="space-y-6">
            <div>
                <p class="text-sm font-semibold uppercase tracking-[0.2em] text-slate-500">Webinar</p>
                <h1 class="mt-4 text-4xl font-bold tracking-tight sm:text-5xl">
                    UI primitives are working
                </h1>
            </div>

            <x-ui.card>
                <div class="space-y-4">
                    <div>
                        <x-ui.form.label for="email">Email</x-ui.form.label>
                        <x-ui.form.input id="email" type="email" placeholder="you@example.com" />
                    </div>

                    <div>
                        <x-ui.form.label for="notes">Notes</x-ui.form.label>
                        <x-ui.form.textarea id="notes" rows="4" placeholder="Add a note..."></x-ui.form.textarea>
                    </div>

                    <div class="flex gap-3">
                        <x-ui.button>Register</x-ui.button>
                        <x-ui.button variant="secondary">Learn More</x-ui.button>
                    </div>
                </div>
            </x-ui.card>
        </div>
    </section>
</x-layouts.public>