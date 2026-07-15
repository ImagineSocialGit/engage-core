@php
    $fields = $fields ?? [];
    $authoringState = [];

    foreach ($fields as $field) {
        if (($field['state'] ?? false) !== true || ! isset($field['name'])) {
            continue;
        }

        $authoringState[$field['name']] = old($field['name'], $field['value'] ?? null);
    }
@endphp

<div class="space-y-4" x-data="{ authoringState: @js($authoringState) }">
    @foreach($fields as $field)
        @php
            $type = (string) ($field['type'] ?? 'text');
            $name = (string) ($field['name'] ?? '');
            $label = (string) ($field['label'] ?? '');
            $required = (bool) ($field['required'] ?? false);
            $value = $name !== '' ? old($name, $field['value'] ?? null) : null;
            $showWhen = is_array($field['show_when'] ?? null) ? $field['show_when'] : null;
            $fieldId = $name !== '' ? $name.'-'.$fieldSuffix : 'notice-'.$fieldSuffix.'-'.$loop->index;
        @endphp

        <div
            @if($showWhen)
                x-show='authoringState[@js($showWhen['field'] ?? '')] === @js($showWhen['equals'] ?? null)'
                x-cloak
            @endif
        >
            @if($type === 'notice')
                <div class="rounded-xl border border-amber-300 bg-amber-50 px-4 py-3 text-sm leading-6 text-amber-950">
                    @if(filled($field['title'] ?? null))
                        <p class="font-semibold">{{ $field['title'] }}</p>
                    @endif
                    <p @class(['mt-1' => filled($field['title'] ?? null)])>{{ $field['body'] ?? '' }}</p>
                </div>
            @elseif($type === 'checkbox')
                <input type="hidden" name="{{ $name }}" value="0">
                <label for="{{ $fieldId }}" class="flex gap-3 rounded-xl border border-slate-200 bg-white px-4 py-3">
                    <input
                        id="{{ $fieldId }}"
                        name="{{ $name }}"
                        type="checkbox"
                        value="1"
                        @checked((bool) $value)
                        class="mt-1 h-4 w-4 rounded border-slate-300 text-orange-600 focus:ring-orange-400"
                    >
                    <span>
                        <span class="block text-sm font-semibold text-slate-900">{{ $label }}</span>
                        @if(filled($field['help'] ?? null))
                            <span class="mt-1 block text-xs leading-5 text-slate-600">{{ $field['help'] }}</span>
                        @endif
                    </span>
                </label>
            @else
                <label for="{{ $fieldId }}" class="text-sm font-semibold text-slate-900">
                    {{ $label }}
                    @if($required)
                        <span class="text-red-700" aria-hidden="true">*</span>
                    @endif
                </label>

                @if($type === 'select')
                    <select
                        id="{{ $fieldId }}"
                        name="{{ $name }}"
                        @required($required)
                        @if(($field['state'] ?? false) === true) x-model='authoringState[@js($name)]' @endif
                        class="mt-1 block w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm text-slate-950 shadow-sm focus:border-orange-500 focus:outline-none focus:ring-2 focus:ring-orange-200"
                    >
                        @if(filled($field['placeholder'] ?? null))
                            <option value="">{{ $field['placeholder'] }}</option>
                        @endif
                        @foreach(($field['options'] ?? []) as $option)
                            <option value="{{ $option['value'] }}" @selected((string) $value === (string) $option['value'])>
                                {{ $option['label'] }}
                            </option>
                        @endforeach
                    </select>
                @elseif($type === 'textarea')
                    <textarea
                        id="{{ $fieldId }}"
                        name="{{ $name }}"
                        rows="{{ $field['rows'] ?? 4 }}"
                        @required($required)
                        class="mt-1 block w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm text-slate-950 shadow-sm focus:border-orange-500 focus:outline-none focus:ring-2 focus:ring-orange-200"
                    >{{ $value }}</textarea>
                @else
                    <input
                        id="{{ $fieldId }}"
                        name="{{ $name }}"
                        type="{{ $type }}"
                        value="{{ $value }}"
                        @required($required)
                        @if(isset($field['min'])) min="{{ $field['min'] }}" @endif
                        @if(isset($field['max'])) max="{{ $field['max'] }}" @endif
                        @if(filled($field['placeholder'] ?? null)) placeholder="{{ $field['placeholder'] }}" @endif
                        class="mt-1 block w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm text-slate-950 shadow-sm focus:border-orange-500 focus:outline-none focus:ring-2 focus:ring-orange-200"
                    >
                @endif

                @if(filled($field['help'] ?? null))
                    <p class="mt-1 text-xs leading-5 text-slate-600">{{ $field['help'] }}</p>
                @endif
            @endif

            @if($name !== '')
                @error($name)
                    <p class="mt-1 text-sm text-red-700">{{ $message }}</p>
                @enderror
            @endif
        </div>
    @endforeach
</div>
