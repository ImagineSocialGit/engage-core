<details>
    <summary
        class="cursor-pointer list-none rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-semibold text-slate-700 shadow-sm hover:bg-slate-50"
    >
        {{ $contactResultAction->label }}
    </summary>

    <div class="mt-2 w-80 rounded-xl border border-slate-200 bg-white p-4 shadow-lg">
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
                Applies to all {{ number_format($contactResultCount) }} matching Contacts you can currently manage.
            </p>

            <x-ui.button type="submit" class="w-full">
                Add tag
            </x-ui.button>
        </form>
    </div>
</details>