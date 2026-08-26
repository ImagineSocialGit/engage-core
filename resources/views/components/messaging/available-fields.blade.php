@props([
    'groups' => [],
])

<div
    {{ $attributes->class(['rounded-2xl border border-slate-200 bg-slate-50 p-4']) }}
    data-message-available-fields
>
    <div class="flex flex-col gap-1 sm:flex-row sm:items-end sm:justify-between sm:gap-4">
        <div>
            <div class="text-sm font-bold text-slate-900">Available fields</div>
            <p class="mt-1 text-xs leading-5 text-slate-600">
                Insert a field into the subject or message. The available choices come from the exact send context.
            </p>
        </div>
        <div class="text-xs font-semibold text-slate-500">Insert field</div>
    </div>

    <div class="mt-4 space-y-4">
        @foreach($groups as $group)
            <section data-message-field-group="{{ $group['key'] }}">
                <div class="text-xs font-bold uppercase tracking-[0.14em] text-slate-500">
                    {{ $group['label'] }}
                </div>

                <div class="mt-2 flex flex-wrap gap-2">
                    @foreach($group['fields'] as $field)
                        <button
                            type="button"
                            class="inline-flex min-h-9 items-center rounded-full border border-slate-300 bg-white px-3 text-xs font-semibold text-slate-800 hover:border-slate-400 hover:bg-slate-100"
                            title="{{ $field['description'] }}"
                            data-message-field-token="{{ $field['token'] }}"
                            data-message-field-syntax="{{ $field['syntax'] }}"
                            x-on:click="$dispatch('message-field-insert', { syntax: @js($field['syntax']) })"
                        >
                            {{ $field['label'] }}
                        </button>
                    @endforeach
                </div>
            </section>
        @endforeach
    </div>
</div>