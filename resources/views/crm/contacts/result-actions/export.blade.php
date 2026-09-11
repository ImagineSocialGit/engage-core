<div class="space-y-3">
    <div>
        <p class="text-sm font-semibold text-slate-900">{{ $contactResultAction->label }}</p>
        <p class="mt-1 text-xs leading-5 text-slate-500">{{ $contactResultAction->description }}</p>
    </div>

    <form method="POST" action="{{ route('crm.contacts.results.export') }}">
        @csrf
        <x-crm.contact-result-filter-fields :payload="$contactResultPayload" />

        <x-ui.button type="submit" variant="secondary" class="w-full">
            Export {{ number_format($contactResultCount) }} {{ $leadPlural }}
        </x-ui.button>
    </form>
</div>