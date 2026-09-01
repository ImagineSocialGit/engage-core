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
                Insert a field into the subject or message. Only fields available for this kind of message are shown.
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
                        @php
                            $example = filled($field['example'] ?? null)
                                ? ' Example: '.$field['example'].'.'
                                : '';
                        @endphp
                        <button
                            type="button"
                            class="inline-flex min-h-10 flex-col items-start justify-center rounded-xl border border-slate-300 bg-white px-3 py-1.5 text-left text-xs text-slate-800 hover:border-slate-400 hover:bg-slate-100"
                            title="{{ $field['description'].$example }}"
                            data-message-field-token="{{ $field['token'] }}"
                            data-message-field-syntax="{{ $field['syntax'] }}"
                            x-on:click="$dispatch('message-field-insert', { syntax: @js($field['syntax']) })"
                        >
                            <span class="font-semibold">{{ $field['label'] }}</span>
                            @if(filled($field['example'] ?? null))
                                <span class="mt-0.5 text-[11px] font-medium text-slate-500">
                                    Example: {{ $field['example'] }}
                                </span>
                            @endif
                        </button>
                    @endforeach
                </div>
            </section>
        @endforeach
    </div>
</div>