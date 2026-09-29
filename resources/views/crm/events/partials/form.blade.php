<div class="space-y-6">
    @if ($errors->any())
        <x-ui.feedback.alert type="error">
            <ul class="list-disc space-y-1 pl-5">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </x-ui.feedback.alert>
    @endif

    <x-ui.card class="space-y-5">
        <div>
            <h2 class="text-lg font-semibold text-slate-950">Event details</h2>
            <p class="mt-1 text-sm leading-6 text-slate-600">
                Save the concrete occurrence first. Drafts can be adjusted until they are ready to become upcoming.
            </p>
        </div>

        <div class="grid gap-5 lg:grid-cols-2">
            <div>
                <x-ui.form.label for="type_key">Event type</x-ui.form.label>
                <select id="type_key" name="type_key" class="mt-1 w-full rounded-lg border-slate-300 text-sm">
                    <option value="">No type</option>
                    @foreach ($eventTypes as $eventType)
                        <option value="{{ $eventType['key'] }}" @selected(old('type_key', $formValues['type_key']) === $eventType['key'])>
                            {{ $eventType['label'] }}
                        </option>
                    @endforeach
                </select>
                <x-ui.form.error name="type_key" />
            </div>

            <div>
                <x-ui.form.label for="attendance_mode">Attendance mode</x-ui.form.label>
                <select id="attendance_mode" name="attendance_mode" class="mt-1 w-full rounded-lg border-slate-300 text-sm" required>
                    @foreach ($attendanceModeOptions as $value => $label)
                        <option value="{{ $value }}" @selected(old('attendance_mode', $formValues['attendance_mode']) === $value)>
                            {{ $label }}
                        </option>
                    @endforeach
                </select>
                <x-ui.form.error name="attendance_mode" />
            </div>

            <div class="lg:col-span-2">
                <x-ui.form.label for="title">Title</x-ui.form.label>
                <x-ui.form.input id="title" name="title" value="{{ old('title', $formValues['title']) }}" required />
                <x-ui.form.error name="title" />
            </div>

            <div class="lg:col-span-2">
                <x-ui.form.label for="description">Description</x-ui.form.label>
                <textarea id="description" name="description" rows="4" class="mt-1 w-full rounded-lg border-slate-300 text-sm">{{ old('description', $formValues['description']) }}</textarea>
                <x-ui.form.error name="description" />
            </div>
        </div>
    </x-ui.card>

    <x-ui.card class="space-y-5">
        <div>
            <h2 class="text-lg font-semibold text-slate-950">Schedule</h2>
            <p class="mt-1 text-sm leading-6 text-slate-600">
                Enter times in the Event timezone. The system stores the corresponding UTC instants.
            </p>
        </div>

        <div class="grid gap-5 lg:grid-cols-2">
            <div>
                <x-ui.form.label for="starts_at_local">Starts</x-ui.form.label>
                <x-ui.form.input id="starts_at_local" name="starts_at_local" type="datetime-local" value="{{ old('starts_at_local', $formValues['starts_at_local']) }}" required />
                <x-ui.form.error name="starts_at_local" />
            </div>

            <div>
                <x-ui.form.label for="ends_at_local">Ends</x-ui.form.label>
                <x-ui.form.input id="ends_at_local" name="ends_at_local" type="datetime-local" value="{{ old('ends_at_local', $formValues['ends_at_local']) }}" />
                <x-ui.form.error name="ends_at_local" />
            </div>

            <div>
                <x-ui.form.label for="timezone">Timezone</x-ui.form.label>
                <select id="timezone" name="timezone" class="mt-1 w-full rounded-lg border-slate-300 text-sm" required>
                    @foreach ($timezoneOptions as $timezoneOption)
                        <option value="{{ $timezoneOption }}" @selected(old('timezone', $formValues['timezone']) === $timezoneOption)>
                            {{ $timezoneOption }}
                        </option>
                    @endforeach
                </select>
                <x-ui.form.error name="timezone" />
            </div>

            <div>
                <x-ui.form.label for="announcement_at_local">Announcement begins</x-ui.form.label>
                <x-ui.form.input id="announcement_at_local" name="announcement_at_local" type="datetime-local" value="{{ old('announcement_at_local', $formValues['announcement_at_local']) }}" />
                <x-ui.form.error name="announcement_at_local" />
                <p class="mt-1 text-xs leading-5 text-slate-500">
                    Leave blank when the announcement date is not yet known. Downstream promotion remains blocked until a date is known and reached.
                </p>
            </div>
        </div>
    </x-ui.card>

    <x-ui.card class="space-y-5">
        <div>
            <h2 class="text-lg font-semibold text-slate-950">Location snapshot</h2>
            <p class="mt-1 text-sm leading-6 text-slate-600">
                Keep the location that belongs to this occurrence. Physical and hybrid Events need a venue, city, and country before promotion to upcoming.
            </p>
        </div>

        <div class="grid gap-5 lg:grid-cols-2">
            <div class="lg:col-span-2">
                <x-ui.form.label for="venue_name">Venue name</x-ui.form.label>
                <x-ui.form.input id="venue_name" name="venue_name" value="{{ old('venue_name', $formValues['venue_name']) }}" />
                <x-ui.form.error name="venue_name" />
            </div>

            <div class="lg:col-span-2">
                <x-ui.form.label for="address_line_1">Address line 1</x-ui.form.label>
                <x-ui.form.input id="address_line_1" name="address_line_1" value="{{ old('address_line_1', $formValues['address_line_1']) }}" />
                <x-ui.form.error name="address_line_1" />
            </div>

            <div class="lg:col-span-2">
                <x-ui.form.label for="address_line_2">Address line 2</x-ui.form.label>
                <x-ui.form.input id="address_line_2" name="address_line_2" value="{{ old('address_line_2', $formValues['address_line_2']) }}" />
                <x-ui.form.error name="address_line_2" />
            </div>

            <div>
                <x-ui.form.label for="city">City</x-ui.form.label>
                <x-ui.form.input id="city" name="city" value="{{ old('city', $formValues['city']) }}" />
                <x-ui.form.error name="city" />
            </div>

            <div>
                <x-ui.form.label for="region">State / region</x-ui.form.label>
                <x-ui.form.input id="region" name="region" value="{{ old('region', $formValues['region']) }}" />
                <x-ui.form.error name="region" />
            </div>

            <div>
                <x-ui.form.label for="postal_code">Postal code</x-ui.form.label>
                <x-ui.form.input id="postal_code" name="postal_code" value="{{ old('postal_code', $formValues['postal_code']) }}" />
                <x-ui.form.error name="postal_code" />
            </div>

            <div>
                <x-ui.form.label for="country">Country code</x-ui.form.label>
                <x-ui.form.input id="country" name="country" maxlength="2" value="{{ old('country', $formValues['country']) }}" placeholder="US" />
                <x-ui.form.error name="country" />
            </div>
        </div>
    </x-ui.card>

    @if ($errors->has('confirm_duplicate'))
        <x-ui.card class="border-amber-200 bg-amber-50">
            <label class="flex items-start gap-3">
                <input type="hidden" name="confirm_duplicate" value="0">
                <input type="checkbox" name="confirm_duplicate" value="1" class="mt-1 rounded border-slate-300" @checked(old('confirm_duplicate'))>
                <span>
                    <span class="block font-semibold text-amber-950">Confirm this is a separate Event</span>
                    <span class="mt-1 block text-sm leading-6 text-amber-800">
                        A similar occurrence already exists. Check this only after confirming that the new record should remain separate.
                    </span>
                </span>
            </label>
        </x-ui.card>
    @endif

    <div class="flex flex-col-reverse gap-3 sm:flex-row sm:items-center sm:justify-between">
        <a href="{{ $cancelUrl }}" class="inline-flex items-center justify-center rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 shadow-sm hover:bg-slate-50">
            Cancel
        </a>
        <x-ui.button type="submit">{{ $submitLabel }}</x-ui.button>
    </div>
</div>