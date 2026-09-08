<form method="POST" action="{{ route('crm.contacts.results.export') }}">
    @csrf
    <x-crm.contact-result-filter-fields :payload="$contactResultPayload" />

    <x-ui.button type="submit" variant="secondary">
        {{ $contactResultAction->label }}
    </x-ui.button>
</form>