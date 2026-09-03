<form
    class="mt-6 grid gap-5"
    method="POST"
    action="{{ route('scheduling.public.services.prepare', ['serviceKey' => $selectedService->key], false) }}"
    data-booking-address-form
>
    @csrf
    <div class="grid gap-4 sm:grid-cols-2">
        <label class="grid gap-2 text-sm font-bold text-slate-800 sm:col-span-2" for="address_line_1">
            Street address
            <input
                class="min-h-12 w-full rounded-xl border border-slate-300 bg-white px-4 py-3 font-medium text-slate-950 outline-none transition focus:border-[var(--public-primary)] focus:ring-2 focus:ring-[var(--public-accent)]/30"
                id="address_line_1"
                name="address_line_1"
                type="text"
                value="{{ old('address_line_1', $preparedLocation['address_line_1'] ?? '') }}"
                autocomplete="address-line1"
                required
            >
        </label>

        <label class="grid gap-2 text-sm font-bold text-slate-800 sm:col-span-2" for="address_line_2">
            <span>Apartment, suite, etc. <span class="font-medium text-slate-500">(optional)</span></span>
            <input
                class="min-h-12 w-full rounded-xl border border-slate-300 bg-white px-4 py-3 font-medium text-slate-950 outline-none transition focus:border-[var(--public-primary)] focus:ring-2 focus:ring-[var(--public-accent)]/30"
                id="address_line_2"
                name="address_line_2"
                type="text"
                value="{{ old('address_line_2', $preparedLocation['address_line_2'] ?? '') }}"
                autocomplete="address-line2"
            >
        </label>

        <label class="grid gap-2 text-sm font-bold text-slate-800" for="city">
            City
            <input
                class="min-h-12 w-full rounded-xl border border-slate-300 bg-white px-4 py-3 font-medium text-slate-950 outline-none transition focus:border-[var(--public-primary)] focus:ring-2 focus:ring-[var(--public-accent)]/30"
                id="city"
                name="city"
                type="text"
                value="{{ old('city', $preparedLocation['city'] ?? '') }}"
                autocomplete="address-level2"
                required
            >
        </label>

        <label class="grid gap-2 text-sm font-bold text-slate-800" for="region">
            State / region
            <input
                class="min-h-12 w-full rounded-xl border border-slate-300 bg-white px-4 py-3 font-medium text-slate-950 outline-none transition focus:border-[var(--public-primary)] focus:ring-2 focus:ring-[var(--public-accent)]/30"
                id="region"
                name="region"
                type="text"
                value="{{ old('region', $preparedLocation['region'] ?? '') }}"
                autocomplete="address-level1"
                required
            >
        </label>

        <label class="grid gap-2 text-sm font-bold text-slate-800" for="postal_code">
            Postal code
            <input
                class="min-h-12 w-full rounded-xl border border-slate-300 bg-white px-4 py-3 font-medium text-slate-950 outline-none transition focus:border-[var(--public-primary)] focus:ring-2 focus:ring-[var(--public-accent)]/30"
                id="postal_code"
                name="postal_code"
                type="text"
                value="{{ old('postal_code', $preparedLocation['postal_code'] ?? '') }}"
                autocomplete="postal-code"
                required
            >
        </label>

        <label class="grid gap-2 text-sm font-bold text-slate-800" for="country">
            Country code
            <input
                class="min-h-12 w-full rounded-xl border border-slate-300 bg-white px-4 py-3 font-medium uppercase text-slate-950 outline-none transition focus:border-[var(--public-primary)] focus:ring-2 focus:ring-[var(--public-accent)]/30"
                id="country"
                name="country"
                type="text"
                maxlength="2"
                value="{{ old('country', $preparedLocation['country'] ?? 'US') }}"
                autocomplete="country"
                required
            >
        </label>
    </div>

    <div class="flex justify-end">
        <x-public-surface.button type="submit">{{ $buttonLabel }}</x-public-surface.button>
    </div>
</form>