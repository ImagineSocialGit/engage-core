@foreach($trigger['fields'] as $field)
    @if(($field['type'] ?? 'text') === 'hidden')
        <input
            type="hidden"
            name="{{ $field['name'] }}"
            value="{{ old($field['name'], $createRouteTriggerValues[$field['name']] ?? ($field['value'] ?? '')) }}"
            x-bind:disabled="createTriggerKey !== @js($trigger['key'])"
            data-flow-route-trigger-field="{{ $field['name'] }}"
        >
    @elseif(($field['type'] ?? 'text') === 'notice')
        <div
            class="rounded-xl border border-amber-300 bg-amber-50 px-4 py-3 text-sm leading-6 text-amber-950"
            data-flow-route-trigger-field
        >
            @if(filled($field['title'] ?? null))
                <p class="font-semibold">{{ $field['title'] }}</p>
            @endif
            @if(filled($field['body'] ?? null))
                <p @class(['mt-1' => filled($field['title'] ?? null)])>{{ $field['body'] }}</p>
            @endif
        </div>
    @elseif(($field['type'] ?? 'text') === 'checkbox')
        <div data-flow-route-trigger-field="{{ $field['name'] }}">
            <input
                type="hidden"
                name="{{ $field['name'] }}"
                value="0"
                x-bind:disabled="createTriggerKey !== @js($trigger['key'])"
            >
            <label
                for="create-route-{{ str_replace('_', '-', (string) $field['name']) }}"
                class="flex gap-3 rounded-xl border border-slate-200 bg-white px-4 py-3"
            >
                <input
                    id="create-route-{{ str_replace('_', '-', (string) $field['name']) }}"
                    name="{{ $field['name'] }}"
                    type="checkbox"
                    value="1"
                    @checked(
                        (string) old(
                            $field['name'],
                            $createRouteTriggerValues[$field['name']] ?? ($field['value'] ?? '0'),
                        ) === '1'
                    )
                    x-bind:disabled="createTriggerKey !== @js($trigger['key'])"
                    @if((bool) ($field['required'] ?? false))
                        x-bind:required="createTriggerKey === @js($trigger['key'])"
                    @endif
                    class="mt-1 h-4 w-4 rounded border-slate-300 text-orange-600 focus:ring-orange-400"
                >
                <span>
                    <span class="block text-sm font-semibold text-slate-900">{{ $field['label'] ?? '' }}</span>
                    @if(filled($field['help'] ?? null))
                        <span class="mt-1 block text-xs leading-5 text-slate-600">{{ $field['help'] }}</span>
                    @endif
                </span>
            </label>

            @if($errors->has($field['name']))
                <p class="mt-1 text-sm text-red-700">{{ $errors->first($field['name']) }}</p>
            @endif
        </div>
    @else
        <div data-flow-route-trigger-field="{{ $field['name'] }}">
            <label
                for="create-route-{{ str_replace('_', '-', (string) $field['name']) }}"
                class="text-sm font-semibold text-slate-900"
            >
                {{ $field['label'] ?? '' }}
                @if((bool) ($field['required'] ?? false))
                    <span class="text-red-700" aria-hidden="true">*</span>
                @endif
            </label>

            @if(($field['type'] ?? 'text') === 'select')
                <select
                    id="create-route-{{ str_replace('_', '-', (string) $field['name']) }}"
                    name="{{ $field['name'] }}"
                    x-bind:disabled="createTriggerKey !== @js($trigger['key'])"
                    @if((bool) ($field['required'] ?? false))
                        x-bind:required="createTriggerKey === @js($trigger['key'])"
                    @endif
                    class="mt-1 block w-full rounded-xl border-slate-300 text-sm shadow-sm focus:border-orange-500 focus:ring-orange-500"
                >
                    @if(array_key_exists('placeholder', $field))
                        <option value="">{{ $field['placeholder'] }}</option>
                    @endif
                    @foreach(($field['options'] ?? []) as $option)
                        <option
                            value="{{ $option['value'] }}"
                            @selected(
                                (string) old(
                                    $field['name'],
                                    $createRouteTriggerValues[$field['name']] ?? ($field['value'] ?? ''),
                                ) === (string) $option['value']
                            )
                        >
                            {{ $option['label'] }}
                        </option>
                    @endforeach
                </select>
            @elseif(($field['type'] ?? 'text') === 'textarea')
                <textarea
                    id="create-route-{{ str_replace('_', '-', (string) $field['name']) }}"
                    name="{{ $field['name'] }}"
                    rows="{{ $field['rows'] ?? 4 }}"
                    x-bind:disabled="createTriggerKey !== @js($trigger['key'])"
                    @if((bool) ($field['required'] ?? false))
                        x-bind:required="createTriggerKey === @js($trigger['key'])"
                    @endif
                    @if(filled($field['placeholder'] ?? null))
                        placeholder="{{ $field['placeholder'] }}"
                    @endif
                    class="mt-1 block w-full rounded-xl border-slate-300 text-sm shadow-sm focus:border-orange-500 focus:ring-orange-500"
                >{{ old($field['name'], $createRouteTriggerValues[$field['name']] ?? ($field['value'] ?? '')) }}</textarea>
            @else
                <input
                    id="create-route-{{ str_replace('_', '-', (string) $field['name']) }}"
                    name="{{ $field['name'] }}"
                    type="{{ $field['type'] ?? 'text' }}"
                    value="{{ old($field['name'], $createRouteTriggerValues[$field['name']] ?? ($field['value'] ?? '')) }}"
                    x-bind:disabled="createTriggerKey !== @js($trigger['key'])"
                    @if((bool) ($field['required'] ?? false))
                        x-bind:required="createTriggerKey === @js($trigger['key'])"
                    @endif
                    @if(isset($field['min'])) min="{{ $field['min'] }}" @endif
                    @if(isset($field['max'])) max="{{ $field['max'] }}" @endif
                    @if(isset($field['step'])) step="{{ $field['step'] }}" @endif
                    @if(filled($field['placeholder'] ?? null)) placeholder="{{ $field['placeholder'] }}" @endif
                    class="mt-1 block w-full rounded-xl border-slate-300 text-sm shadow-sm focus:border-orange-500 focus:ring-orange-500"
                >
            @endif

            @if(filled($field['help'] ?? null))
                <p class="mt-1 text-xs leading-5 text-slate-600">{{ $field['help'] }}</p>
            @endif

            @if($errors->has($field['name']))
                <p class="mt-1 text-sm text-red-700">{{ $errors->first($field['name']) }}</p>
            @endif
        </div>
    @endif
@endforeach