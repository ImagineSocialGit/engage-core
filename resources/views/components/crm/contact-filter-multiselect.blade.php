@props([
    'criterion',
    'name',
    'selectedValues' => [],
    'idPrefix' => 'contact_filter',
])

<div
    class="space-y-2"
    x-data="{
        open: false,
        search: '',
        selected: @js(array_values(array_map('strval', $selectedValues))),
        options: @js($criterion['options'] ?? []),
        normalized(value) {
            return String(value ?? '');
        },
        toggle(value) {
            value = this.normalized(value);

            if (this.selected.includes(value)) {
                this.selected = this.selected.filter((selectedValue) => selectedValue !== value);
                return;
            }

            this.selected.push(value);
        },
        remove(value) {
            value = this.normalized(value);
            this.selected = this.selected.filter((selectedValue) => selectedValue !== value);
        },
        reset() {
            this.selected = [];
            this.search = '';
            this.open = false;
        },
        get filteredOptions() {
            const needle = this.search.trim().toLowerCase();

            if (needle === '') {
                return this.options;
            }

            return this.options.filter((option) => String(option.label ?? '').toLowerCase().includes(needle));
        },
        get selectedOptions() {
            return this.options.filter((option) => this.selected.includes(this.normalized(option.value)));
        },
    }"
    x-on:audience-clear-filters.window="reset()"
>
    <template x-for="value in selected" :key="`{{ $idPrefix }}-selected-${value}`">
        <input type="hidden" name="{{ $name }}[]" x-bind:value="value">
    </template>

    <div class="relative" x-on:click.outside="open = false">
        <button
            type="button"
            class="flex min-h-11 w-full items-center justify-between gap-3 rounded-lg border border-slate-300 bg-white px-3 py-2 text-left text-sm shadow-sm hover:border-slate-400 focus:border-slate-500 focus:outline-none focus:ring-1 focus:ring-slate-500"
            x-on:click="open = ! open"
            x-bind:aria-expanded="open"
        >
            <span class="min-w-0 truncate text-slate-700" x-text="selected.length > 0 ? `${selected.length} selected` : 'Choose…'"></span>
            <span class="shrink-0 text-slate-400">⌄</span>
        </button>

        <div
            x-cloak
            x-show="open"
            x-transition.opacity
            class="absolute z-40 mt-2 w-full rounded-xl border border-slate-200 bg-white p-3 shadow-xl"
        >
            <input
                type="search"
                x-model.debounce.100ms="search"
                placeholder="Type to search…"
                class="block w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-slate-500 focus:outline-none focus:ring-1 focus:ring-slate-500"
            >

            <div class="mt-2 max-h-64 overflow-y-auto rounded-lg border border-slate-100">
                <template x-for="option in filteredOptions" :key="normalized(option.value)">
                    <label class="flex cursor-pointer items-start gap-3 border-b border-slate-100 px-3 py-2 last:border-b-0 hover:bg-slate-50">
                        <input
                            type="checkbox"
                            class="mt-0.5 rounded border-slate-300"
                            x-bind:checked="selected.includes(normalized(option.value))"
                            x-on:change="toggle(option.value)"
                        >
                        <span class="text-sm text-slate-700" x-text="option.label"></span>
                    </label>
                </template>

                <div x-show="filteredOptions.length === 0" class="px-3 py-4 text-center text-xs text-slate-500">
                    No matches.
                </div>
            </div>
        </div>
    </div>

    <div class="flex flex-wrap gap-2" x-show="selectedOptions.length > 0" x-cloak>
        <template x-for="option in selectedOptions" :key="`chip-${normalized(option.value)}`">
            <button
                type="button"
                class="inline-flex max-w-full items-center gap-1 rounded-full bg-slate-100 px-2.5 py-1 text-xs font-medium text-slate-700 hover:bg-slate-200"
                x-on:click="remove(option.value)"
            >
                <span class="truncate" x-text="option.label"></span>
                <span aria-hidden="true">×</span>
            </button>
        </template>
    </div>

    @if(filled($criterion['help'] ?? null))
        <p class="text-xs text-slate-500">{{ $criterion['help'] }}</p>
    @endif
</div>