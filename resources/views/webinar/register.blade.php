<x-layouts.public title="Register for Webinar">
    <section class="mx-auto w-full max-w-4xl px-6 py-20">
        <div class="grid gap-10 lg:grid-cols-2">
            <div class="space-y-4">
                <p class="text-sm font-semibold uppercase tracking-[0.2em] text-slate-500">
                    Free Webinar
                </p>
                <h1 class="text-4xl font-bold tracking-tight sm:text-5xl">
                    Register for the {{ $webinar->title }}
                </h1>
                <p class="text-lg leading-8 text-slate-600">
                    Structural page only for now. This will become the real webinar registration page.
                </p>
            </div>

            <x-ui.card>
                <form method="POST" action="{{ route('webinar.store', $series->slug) }}" class="space-y-4">
                    @csrf

                    <div>
                        <x-ui.form.label for="first_name">First Name</x-ui.form.label>
                        <x-ui.form.input id="first_name" name="first_name" :value="old('first_name')" />
                        @error('first_name') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <x-ui.form.label for="last_name">Last Name</x-ui.form.label>
                        <x-ui.form.input id="last_name" name="last_name" :value="old('last_name')" />
                        @error('last_name') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <x-ui.form.label for="email">Email</x-ui.form.label>
                        <x-ui.form.input id="email" name="email" type="email" :value="old('email')" />
                        @error('email') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <x-ui.form.label for="phone">Phone</x-ui.form.label>
                        <x-ui.form.input id="phone" name="phone" :value="old('phone')" />
                        @error('phone') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <x-ui.form.label for="notes">Notes</x-ui.form.label>
                        <x-ui.form.textarea id="notes" name="notes" rows="4">{{ old('notes') }}</x-ui.form.textarea>
                        @error('notes') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                    </div>

                    <x-ui.button type="submit" class="w-full">
                        Reserve My Spot
                    </x-ui.button>
                </form>
            </x-ui.card>
        </div>
    </section>
</x-layouts.public>