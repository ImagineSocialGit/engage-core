<form class="form" method="POST" action="{{ route('scheduling.public.services.prepare', ['serviceKey' => $selectedService->key], false) }}" style="margin-top:1rem">
    @csrf
    <div class="fields">
        <label class="field field-wide" for="address_line_1">Street address<input id="address_line_1" name="address_line_1" type="text" value="{{ old('address_line_1', $preparedLocation['address_line_1'] ?? '') }}" autocomplete="address-line1" required></label>
        <label class="field field-wide" for="address_line_2">Apartment, suite, etc. <span class="muted">(optional)</span><input id="address_line_2" name="address_line_2" type="text" value="{{ old('address_line_2', $preparedLocation['address_line_2'] ?? '') }}" autocomplete="address-line2"></label>
        <label class="field" for="city">City<input id="city" name="city" type="text" value="{{ old('city', $preparedLocation['city'] ?? '') }}" autocomplete="address-level2" required></label>
        <label class="field" for="region">State / region<input id="region" name="region" type="text" value="{{ old('region', $preparedLocation['region'] ?? '') }}" autocomplete="address-level1" required></label>
        <label class="field" for="postal_code">Postal code<input id="postal_code" name="postal_code" type="text" value="{{ old('postal_code', $preparedLocation['postal_code'] ?? '') }}" autocomplete="postal-code" required></label>
        <label class="field" for="country">Country code<input id="country" name="country" type="text" maxlength="2" value="{{ old('country', $preparedLocation['country'] ?? 'US') }}" autocomplete="country" required></label>
    </div>
    <div class="actions"><button type="submit">{{ $buttonLabel }}</button></div>
</form>