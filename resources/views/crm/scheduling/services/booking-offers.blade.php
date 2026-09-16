<x-layouts.crm
    :title="$title"
    :heading="$heading"
    subheading="Add optional codes that can qualify a booking for limited rewards or other future booking benefits."
>
    <div class="space-y-6" data-scheduling-booking-offers-workspace="{{ $service->id }}">
        <a href="{{ route('crm.scheduling.configuration.services.details.edit', $service) }}#offers" class="inline-flex items-center rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">Back to {{ $service->name }}</a>

        @if (session('success'))
            <x-ui.feedback.alert type="success">{{ session('success') }}</x-ui.feedback.alert>
        @endif

        @if ($errors->any())
            <x-ui.feedback.alert type="error">
                <ul class="list-disc space-y-1 pl-5">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </x-ui.feedback.alert>
        @endif

        @include('crm.scheduling.partials.setup-progress', ['setupProgress' => $setupProgress])

        <x-ui.card class="space-y-2">
            <h2 class="text-lg font-semibold text-slate-900">How offer codes work</h2>
            <p class="text-sm leading-6 text-slate-600">Customers enter an offer code with their contact details near the end of public booking. Eligibility is checked only when the appointment is completed, and limited claim numbers are allocated transactionally.</p>
        </x-ui.card>

        @foreach ($offers as $offerRow)
            <form id="offer-{{ $offerRow['model']->id }}" method="POST" action="{{ route('crm.scheduling.configuration.services.offers.update', ['bookableService' => $service, 'bookingOffer' => $offerRow['model']]) }}" class="scroll-mt-6 space-y-5">
                @csrf
                @method('PUT')

                <x-ui.card class="space-y-5">
                    <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                        <div>
                            <div class="text-xs font-bold uppercase tracking-[0.14em] text-teal-700">{{ $offerRow['model']->code }}</div>
                            <h2 class="mt-1 text-lg font-semibold text-slate-900">{{ $offerRow['model']->name }}</h2>
                        </div>
                        <label class="inline-flex items-center gap-2 text-sm font-semibold text-slate-700">
                            <input type="hidden" name="enabled" value="0">
                            <input type="checkbox" name="enabled" value="1" class="rounded border-slate-300 text-teal-700 focus:ring-teal-500" @checked($offerRow['model']->isActive())>
                            Active
                        </label>
                    </div>

                    <div class="grid gap-4 lg:grid-cols-2">
                        <label class="block text-sm font-medium text-slate-700">
                            Offer code
                            <input class="mt-1 block w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm uppercase" type="text" name="code" maxlength="40" value="{{ old('code', $offerRow['model']->code) }}" required>
                        </label>
                        <label class="block text-sm font-medium text-slate-700">
                            Internal name
                            <input class="mt-1 block w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm" type="text" name="name" maxlength="255" value="{{ old('name', $offerRow['model']->name) }}" required>
                        </label>
                    </div>

                    <div class="grid gap-4 lg:grid-cols-2">
                        <label class="block text-sm font-medium text-slate-700">
                            Eligibility type <span class="font-normal text-slate-400">(optional)</span>
                            <select class="mt-1 block w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm" name="eligibility_provider">
                                <option value="">No eligibility restriction</option>
                                @foreach ($eligibilityProviders as $provider)
                                    <option value="{{ $provider['key'] }}" @selected($offerRow['eligibility_provider'] === $provider['key'])>{{ $provider['label'] }}</option>
                                @endforeach
                            </select>
                        </label>

                        <label class="block text-sm font-medium text-slate-700">
                            Qualifying audience
                            <select class="mt-1 block w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm" name="eligibility_option">
                                <option value="">Choose an audience when eligibility is restricted</option>
                                @foreach ($eligibilityProviders as $provider)
                                    <optgroup label="{{ $provider['label'] }}">
                                        @foreach ($provider['options'] as $option)
                                            <option value="{{ $option['value'] }}" @selected($offerRow['eligibility_option'] === $option['value'])>{{ $option['label'] }}</option>
                                        @endforeach
                                    </optgroup>
                                @endforeach
                            </select>
                        </label>
                    </div>

                    <div class="grid gap-4 lg:grid-cols-3">
                        <label class="block text-sm font-medium text-slate-700">
                            Claim limit <span class="font-normal text-slate-400">(optional)</span>
                            <input class="mt-1 block w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm" type="number" min="1" max="100000" name="claim_limit" value="{{ old('claim_limit', $offerRow['model']->claim_limit) }}">
                        </label>
                        <label class="block text-sm font-medium text-slate-700">
                            Opens ({{ $clientTimezone }})
                            <input class="mt-1 block w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm" type="datetime-local" name="starts_at" value="{{ old('starts_at', $offerRow['model']->starts_at?->timezone($clientTimezone)->format('Y-m-d\TH:i')) }}">
                        </label>
                        <label class="block text-sm font-medium text-slate-700">
                            Ends ({{ $clientTimezone }})
                            <input class="mt-1 block w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm" type="datetime-local" name="ends_at" value="{{ old('ends_at', $offerRow['model']->ends_at?->timezone($clientTimezone)->format('Y-m-d\TH:i')) }}">
                        </label>
                    </div>

                    <div class="space-y-3">
                        <div>
                            <h3 class="font-semibold text-slate-900">Limited rewards</h3>
                            <p class="mt-1 text-sm leading-6 text-slate-500">A booking receives every reward whose first-N threshold includes its claim number.</p>
                        </div>

                        @foreach ($offerRow['rewards'] as $index => $reward)
                            <div class="grid gap-3 rounded-xl border border-slate-200 bg-slate-50 p-4 lg:grid-cols-[1fr_10rem_1fr]">
                                <label class="block text-sm font-medium text-slate-700">
                                    Reward name
                                    <input class="mt-1 block w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm" type="text" name="rewards[{{ $index }}][name]" value="{{ $reward['name'] }}">
                                </label>
                                <label class="block text-sm font-medium text-slate-700">
                                    First N bookings
                                    <input class="mt-1 block w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm" type="number" min="1" max="100000" name="rewards[{{ $index }}][max_claim_number]" value="{{ $reward['max_claim_number'] }}">
                                </label>
                                <label class="block text-sm font-medium text-slate-700">
                                    Contact tag
                                    <input class="mt-1 block w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm" type="text" maxlength="255" name="rewards[{{ $index }}][contact_tag]" value="{{ $reward['contact_tag'] }}">
                                </label>
                            </div>
                        @endforeach
                    </div>

                    <div class="grid gap-4 lg:grid-cols-2">
                        <label class="block text-sm font-medium text-slate-700">
                            Ineligible message
                            <textarea class="mt-1 block min-h-20 w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm" name="ineligible_message" maxlength="1000">{{ old('ineligible_message', $offerRow['model']->ineligible_message) }}</textarea>
                        </label>
                        <label class="block text-sm font-medium text-slate-700">
                            Exhausted message
                            <textarea class="mt-1 block min-h-20 w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm" name="exhausted_message" maxlength="1000">{{ old('exhausted_message', $offerRow['model']->exhausted_message) }}</textarea>
                        </label>
                    </div>

                    <div class="flex flex-wrap justify-end gap-3">
                        <button type="submit" class="inline-flex justify-center rounded-lg bg-slate-900 px-4 py-2.5 text-sm font-semibold text-white hover:bg-slate-800">Save offer</button>
                    </div>
                </x-ui.card>
            </form>

            <form method="POST" action="{{ route('crm.scheduling.configuration.services.offers.destroy', ['bookableService' => $service, 'bookingOffer' => $offerRow['model']]) }}" class="-mt-3 flex justify-end">
                @csrf
                @method('DELETE')
                <button type="submit" class="text-sm font-semibold text-red-700 hover:text-red-800">Remove offer</button>
            </form>
        @endforeach

        <form id="add-offer" method="POST" action="{{ route('crm.scheduling.configuration.services.offers.store', $service) }}" class="scroll-mt-6 space-y-5">
            @csrf

            <x-ui.card class="space-y-5 border-dashed">
                <div>
                    <div class="text-xs font-bold uppercase tracking-[0.14em] text-teal-700">Optional</div>
                    <h2 class="mt-1 text-lg font-semibold text-slate-900">Add offer</h2>
                </div>

                <input type="hidden" name="enabled" value="1">

                <div class="grid gap-4 lg:grid-cols-2">
                    <label class="block text-sm font-medium text-slate-700">
                        Offer code
                        <input class="mt-1 block w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm uppercase" type="text" name="code" maxlength="40" placeholder="FREEVA" required>
                    </label>
                    <label class="block text-sm font-medium text-slate-700">
                        Internal name
                        <input class="mt-1 block w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm" type="text" name="name" maxlength="255" placeholder="Webinar consultation offer" required>
                    </label>
                </div>

                <div class="grid gap-4 lg:grid-cols-2">
                    <label class="block text-sm font-medium text-slate-700">
                        Eligibility type <span class="font-normal text-slate-400">(optional)</span>
                        <select class="mt-1 block w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm" name="eligibility_provider">
                            <option value="">No eligibility restriction</option>
                            @foreach ($eligibilityProviders as $provider)
                                <option value="{{ $provider['key'] }}">{{ $provider['label'] }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label class="block text-sm font-medium text-slate-700">
                        Qualifying audience
                        <select class="mt-1 block w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm" name="eligibility_option">
                            <option value="">Choose an audience when eligibility is restricted</option>
                            @foreach ($eligibilityProviders as $provider)
                                <optgroup label="{{ $provider['label'] }}">
                                    @foreach ($provider['options'] as $option)
                                        <option value="{{ $option['value'] }}">{{ $option['label'] }}</option>
                                    @endforeach
                                </optgroup>
                            @endforeach
                        </select>
                    </label>
                </div>

                <div class="grid gap-4 lg:grid-cols-3">
                    <label class="block text-sm font-medium text-slate-700">
                        Claim limit <span class="font-normal text-slate-400">(optional)</span>
                        <input class="mt-1 block w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm" type="number" min="1" max="100000" name="claim_limit" placeholder="30">
                    </label>
                    <label class="block text-sm font-medium text-slate-700">
                        Opens ({{ $clientTimezone }})
                        <input class="mt-1 block w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm" type="datetime-local" name="starts_at">
                    </label>
                    <label class="block text-sm font-medium text-slate-700">
                        Ends ({{ $clientTimezone }})
                        <input class="mt-1 block w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm" type="datetime-local" name="ends_at">
                    </label>
                </div>

                <div class="space-y-3">
                    <h3 class="font-semibold text-slate-900">Limited rewards</h3>
                    @foreach ($blankRewardRows as $index => $reward)
                        <div class="grid gap-3 rounded-xl border border-slate-200 bg-slate-50 p-4 lg:grid-cols-[1fr_10rem_1fr]">
                            <label class="block text-sm font-medium text-slate-700">
                                Reward name
                                <input class="mt-1 block w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm" type="text" name="rewards[{{ $index }}][name]" placeholder="Free appraisal certificate">
                            </label>
                            <label class="block text-sm font-medium text-slate-700">
                                First N bookings
                                <input class="mt-1 block w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm" type="number" min="1" max="100000" name="rewards[{{ $index }}][max_claim_number]" placeholder="10">
                            </label>
                            <label class="block text-sm font-medium text-slate-700">
                                Contact tag
                                <input class="mt-1 block w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm" type="text" maxlength="255" name="rewards[{{ $index }}][contact_tag]" placeholder="free-appraisal">
                            </label>
                        </div>
                    @endforeach
                </div>

                <div class="flex justify-end">
                    <button type="submit" class="inline-flex w-full justify-center rounded-lg bg-slate-900 px-4 py-2.5 text-sm font-semibold text-white hover:bg-slate-800 sm:w-auto">Add offer</button>
                </div>
            </x-ui.card>
        </form>
    </div>
</x-layouts.crm>