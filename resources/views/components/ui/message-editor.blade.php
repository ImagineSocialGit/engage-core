@props([
    'subject' => null,
    'body' => null,
    'sms' => null,
])

<div {{ $attributes->class(['space-y-4']) }} data-message-editor>
    @if(is_array($subject))
        <div
            @if(filled($subject['visible_bind'] ?? null))
                x-show="{{ $subject['visible_bind'] }}"
                x-cloak
            @endif
            data-message-editor-field-wrap="subject"
        >
            <label
                @if(filled($subject['id'] ?? null)) for="{{ $subject['id'] }}" @endif
                class="{{ $subject['label_class'] ?? 'mb-1.5 block text-sm font-extrabold text-slate-800' }}"
            >
                {{ $subject['label'] ?? 'Email subject' }}
            </label>

            <input
                @if(filled($subject['id'] ?? null)) id="{{ $subject['id'] }}" @endif
                @if(filled($subject['name_bind'] ?? null))
                    x-bind:name="{{ $subject['name_bind'] }}"
                @elseif(filled($subject['name'] ?? null))
                    name="{{ $subject['name'] }}"
                @endif
                type="text"
                @if(array_key_exists('value', $subject)) value="{{ $subject['value'] }}" @endif
                @if(filled($subject['model'] ?? null)) x-model="{{ $subject['model'] }}" @endif
                @if(filled($subject['required_bind'] ?? null)) x-bind:required="{{ $subject['required_bind'] }}" @elseif(($subject['required'] ?? false) === true) required @endif
                @if(filled($subject['maxlength'] ?? null)) maxlength="{{ $subject['maxlength'] }}" @endif
                @if(filled($subject['placeholder'] ?? null)) placeholder="{{ $subject['placeholder'] }}" @endif
                @if(filled($subject['focus'] ?? null)) x-on:focus="{{ $subject['focus'] }}" @endif
                @if(filled($subject['data_field'] ?? null)) data-template-authoring-field="{{ $subject['data_field'] }}" @endif
                class="{{ $subject['input_class'] ?? 'block w-full rounded-xl border border-slate-300 bg-white px-4 py-2.5 text-sm text-slate-900 shadow-sm focus:border-slate-500 focus:outline-none focus:ring-0' }}"
                data-message-editor-field="subject"
            >

            @if(filled($subject['help'] ?? null))
                <p class="mt-2 text-xs leading-5 text-slate-600">{{ $subject['help'] }}</p>
            @endif

            @if(filled($subject['error'] ?? null))
                <p class="mt-2 text-sm font-semibold text-red-600">{{ $subject['error'] }}</p>
            @endif
        </div>
    @endif

    @if(is_array($body))
        <div
            @if(filled($body['visible_bind'] ?? null))
                x-show="{{ $body['visible_bind'] }}"
                x-cloak
            @endif
            data-message-editor-field-wrap="body"
        >
            <label
                @if(filled($body['id'] ?? null)) for="{{ $body['id'] }}" @endif
                class="{{ $body['label_class'] ?? 'mb-1.5 block text-sm font-extrabold text-slate-800' }}"
            >
                {{ $body['label'] ?? 'Message' }}
            </label>

            <textarea
                @if(filled($body['id'] ?? null)) id="{{ $body['id'] }}" @endif
                @if(filled($body['name_bind'] ?? null))
                    x-bind:name="{{ $body['name_bind'] }}"
                @elseif(filled($body['name'] ?? null))
                    name="{{ $body['name'] }}"
                @endif
                rows="{{ $body['rows'] ?? 8 }}"
                @if(filled($body['model'] ?? null)) x-model="{{ $body['model'] }}" @endif
                @if(filled($body['required_bind'] ?? null)) x-bind:required="{{ $body['required_bind'] }}" @elseif(($body['required'] ?? false) === true) required @endif
                @if(filled($body['maxlength'] ?? null)) maxlength="{{ $body['maxlength'] }}" @endif
                @if(filled($body['placeholder'] ?? null)) placeholder="{{ $body['placeholder'] }}" @endif
                @if(filled($body['focus'] ?? null)) x-on:focus="{{ $body['focus'] }}" @endif
                @if(filled($body['data_field'] ?? null)) data-template-authoring-field="{{ $body['data_field'] }}" @endif
                class="{{ $body['input_class'] ?? 'block w-full rounded-xl border border-slate-300 bg-white px-4 py-3 text-sm leading-6 text-slate-900 shadow-sm focus:border-slate-500 focus:outline-none focus:ring-0' }}"
                data-message-editor-field="body"
            >{{ $body['value'] ?? '' }}</textarea>

            @if(filled($body['help'] ?? null))
                <p class="mt-2 text-xs leading-5 text-slate-600">{{ $body['help'] }}</p>
            @endif

            @if(filled($body['error'] ?? null))
                <p class="mt-2 text-sm font-semibold text-red-600">{{ $body['error'] }}</p>
            @endif
        </div>
    @endif

    @if(is_array($sms))
        <div
            @if(filled($sms['visible_bind'] ?? null))
                x-show="{{ $sms['visible_bind'] }}"
                x-cloak
            @endif
            data-message-editor-field-wrap="sms"
        >
            <label
                @if(filled($sms['id'] ?? null)) for="{{ $sms['id'] }}" @endif
                class="{{ $sms['label_class'] ?? 'mb-1.5 block text-sm font-extrabold text-slate-800' }}"
            >
                {{ $sms['label'] ?? 'Message' }}
            </label>

            <textarea
                @if(filled($sms['id'] ?? null)) id="{{ $sms['id'] }}" @endif
                @if(filled($sms['name_bind'] ?? null))
                    x-bind:name="{{ $sms['name_bind'] }}"
                @elseif(filled($sms['name'] ?? null))
                    name="{{ $sms['name'] }}"
                @endif
                rows="{{ $sms['rows'] ?? 6 }}"
                @if(filled($sms['model'] ?? null)) x-model="{{ $sms['model'] }}" @endif
                @if(filled($sms['required_bind'] ?? null)) x-bind:required="{{ $sms['required_bind'] }}" @elseif(($sms['required'] ?? false) === true) required @endif
                @if(filled($sms['maxlength'] ?? null)) maxlength="{{ $sms['maxlength'] }}" @endif
                @if(filled($sms['placeholder'] ?? null)) placeholder="{{ $sms['placeholder'] }}" @endif
                @if(filled($sms['focus'] ?? null)) x-on:focus="{{ $sms['focus'] }}" @endif
                @if(filled($sms['data_field'] ?? null)) data-template-authoring-field="{{ $sms['data_field'] }}" @endif
                class="{{ $sms['input_class'] ?? 'block w-full rounded-xl border border-slate-300 bg-white px-4 py-3 text-sm leading-6 text-slate-900 shadow-sm focus:border-slate-500 focus:outline-none focus:ring-0' }}"
                data-message-editor-field="sms"
            >{{ $sms['value'] ?? '' }}</textarea>

            @if(filled($sms['help'] ?? null))
                <p class="mt-2 text-xs leading-5 text-slate-600">{{ $sms['help'] }}</p>
            @endif

            @if(filled($sms['error'] ?? null))
                <p class="mt-2 text-sm font-semibold text-red-600">{{ $sms['error'] }}</p>
            @endif
        </div>
    @endif
</div>