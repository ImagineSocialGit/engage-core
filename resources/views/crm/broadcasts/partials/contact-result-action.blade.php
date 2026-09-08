<form method="POST" action="{{ route('crm.broadcasts.from-contact-results') }}">
    @csrf
    <x-crm.contact-result-filter-fields :payload="$contactResultPayload" />

    <x-ui.button type="submit" variant="secondary">
        {{ $contactResultAction->label }}
    </x-ui.button>
</form>