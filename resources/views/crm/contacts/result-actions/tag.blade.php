<div class="space-y-3">
    <div>
        <p class="text-sm font-semibold text-slate-900">{{ $contactResultAction->label }}</p>
        <p class="mt-1 text-xs leading-5 text-slate-500">{{ $contactResultAction->description }}</p>
    </div>

    <form method="POST" action="{{ route('crm.contacts.results.tag') }}" class="space-y-3">
        @csrf
        <x-crm.contact-result-filter-fields :payload="$contactResultPayload" />

        <div>
            <x-ui.form.label for="contact_result_tag">Tag</x-ui.form.label>
            <x-ui.form.input
                id="contact_result_tag"
                name="tag"
                placeholder="Enter a tag"
                required
            />
        </div>

        <p class="text-xs leading-5 text-slate-500">
            Applies to all {{ number_format($contactResultCount) }} matching {{ $leadPlural }} you can currently manage.
        </p>

        <x-ui.button type="submit" class="w-full">
            Add tag
        </x-ui.button>
    </form>
</div>