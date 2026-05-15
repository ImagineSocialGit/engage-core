@props([
    'page',
    'tokens',
    'series',
])

@php
    $checkbox = array_replace_recursive(
        config('webinars.style.components.checkbox', []),
        config('webinars.register.style.components.checkbox', []),
    );
@endphp

<div
    x-cloak
    x-show="formOpen"
    x-trap.noscroll="formOpen"
    x-init="$watch('formOpen', value => value && $nextTick(() => $refs.firstName?.focus()))"
    x-transition:enter="transition ease-out duration-200"
    x-transition:enter-start="opacity-0 scale-105"
    x-transition:enter-end="opacity-100 scale-100"
    x-transition:leave="transition ease-in duration-150"
    x-transition:leave-start="opacity-100 scale-100"
    x-transition:leave-end="opacity-0 scale-105"
    class="fixed inset-0 z-50 flex items-center justify-center p-4 sm:p-6"
    aria-labelledby="register-modal-title"
    aria-modal="true"
    role="dialog"
>
    <div
        class="absolute inset-0 bg-black/70"
        @click="formOpen = false"
    ></div>

    <div
        class="relative z-10 w-full max-w-2xl"
        @click.stop
    >
        <x-ui.card class="{{ $page['form_card']['class'] ?? '' }}">
            <div class="mb-6 flex items-start justify-between gap-4">
                <div class="space-y-2">
                    @if(filled($page['form_card']['title'] ?? null))
                        <h2
                            id="register-modal-title"
                            class="text-2xl font-bold tracking-tight text-slate-900"
                        >
                            {{ $page['form_card']['title'] }}
                        </h2>
                    @endif

                    @if(filled($page['form_card']['body'] ?? null))
                        <p class="{{ $tokens['muted_dark'] ?? 'text-sm text-slate-500' }}">
                            {{ $page['form_card']['body'] }}
                        </p>
                    @endif
                </div>

                <button
                    type="button"
                    @click="formOpen = false"
                    class="inline-flex h-10 w-10 items-center justify-center rounded-full text-slate-500 transition hover:bg-slate-100 hover:text-slate-900"
                    aria-label="Close registration form"
                >
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>

            <form
                method="POST"
                action="{{ route('webinar.registration.store', $series->slug) }}"
                class="{{ $tokens['form_grid'] ?? 'space-y-4' }}"
            >
                @csrf

                <div>
                    <x-ui.form.label for="first_name">
                        {{ $page['fields']['first_name']['label'] ?? 'First Name' }}
                    </x-ui.form.label>

                    <x-ui.form.input
                        id="first_name"
                        name="first_name"
                        x-ref="firstName"
                        :value="old('first_name')"
                        :placeholder="$page['fields']['first_name']['placeholder'] ?? 'First name'"
                    />

                    @error('first_name')
                        <p class="{{ $tokens['field_error'] ?? 'mt-1 text-sm text-red-600' }}">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <x-ui.form.label for="last_name">
                        {{ $page['fields']['last_name']['label'] ?? 'Last Name' }}
                    </x-ui.form.label>

                    <x-ui.form.input
                        id="last_name"
                        name="last_name"
                        :value="old('last_name')"
                        :placeholder="$page['fields']['last_name']['placeholder'] ?? 'Last name'"
                    />

                    @error('last_name')
                        <p class="{{ $tokens['field_error'] ?? 'mt-1 text-sm text-red-600' }}">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <x-ui.form.label for="email">
                        {{ $page['fields']['email']['label'] ?? 'Email Address' }}
                    </x-ui.form.label>

                    <x-ui.form.input
                        id="email"
                        name="email"
                        type="email"
                        :value="old('email')"
                        :placeholder="$page['fields']['email']['placeholder'] ?? 'Email address'"
                    />

                    @error('email')
                        <p class="{{ $tokens['field_error'] ?? 'mt-1 text-sm text-red-600' }}">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <x-ui.form.label for="phone">
                        {{ $page['fields']['phone']['label'] ?? 'Phone Number' }}
                    </x-ui.form.label>

                    <x-ui.form.input
                        id="phone"
                        name="phone"
                        :value="old('phone')"
                        :placeholder="$page['fields']['phone']['placeholder'] ?? 'Phone number'"
                    />

                    @error('phone')
                        <p class="{{ $tokens['field_error'] ?? 'mt-1 text-sm text-red-600' }}">{{ $message }}</p>
                    @enderror
                </div>

                {{-- @if(filled($page['fields']['sms_reminder']['label'] ?? null))
                    <div>
                        <label
                            for="sms_reminder"
                            class="{{ $checkbox['wrapper'] ?? 'flex items-start gap-3' }}"
                        >
                            <input
                                id="sms_reminder"
                                name="sms_reminder"
                                type="checkbox"
                                value="1"
                                @checked(old('sms_reminder'))
                                class="{{ $checkbox['input'] ?? 'mt-1 h-5 w-5 rounded border-slate-300 text-primary focus:ring-primary' }}"
                            >

                            <span class="{{ $checkbox['label'] ?? 'text-sm leading-6 text-slate-700' }}">
                                {{ $page['fields']['sms_reminder']['label'] }}
                            </span>
                        </label>

                        @error('sms_reminder')
                            <p class="{{ $tokens['field_error'] ?? 'mt-1 text-sm text-red-600' }}">
                                {{ $message }}
                            </p>
                        @enderror
                    </div>
                @endif --}}

                <div>
                    <label
                        for="email_consent"
                        class="{{ $checkbox['wrapper'] ?? 'flex items-start gap-3' }}"
                    >
                        <input
                            id="email_consent"
                            name="email_consent"
                            type="checkbox"
                            value="1"
                            @checked(old('email_consent'))
                            class="{{ $checkbox['input'] ?? 'mt-1 h-5 w-5 rounded border-slate-300 text-primary focus:ring-primary' }}"
                        >

                        <span class="{{ $checkbox['label'] ?? 'text-sm leading-6 text-slate-700' }}">
                            {{ $page['fields']['consent_messages']['email']['label'] }}
                        </span>
                    </label>

                    @error('email_consent')
                        <p class="{{ $tokens['field_error'] ?? 'mt-1 text-sm text-red-600' }}">
                            {{ $message }}
                        </p>
                    @enderror
                </div>

                <div>
                    <label
                        for="sms_consent"
                        class="{{ $checkbox['wrapper'] ?? 'flex items-start gap-3' }}"
                    >
                        <input
                            id="sms_consent"
                            name="sms_consent"
                            type="checkbox"
                            value="1"
                            @checked(old('sms_consent'))
                            class="{{ $checkbox['input'] ?? 'mt-1 h-5 w-5 rounded border-slate-300 text-primary focus:ring-primary' }}"
                        >

                        <span class="{{ $checkbox['label'] ?? 'text-sm leading-6 text-slate-700' }}">
                            {{ $page['fields']['consent_messages']['sms']['label'] }}
                        </span>
                    </label>

                    @error('sms_consent')
                        <p class="{{ $tokens['field_error'] ?? 'mt-1 text-sm text-red-600' }}">
                            {{ $message }}
                        </p>
                    @enderror
                </div>

                @if($page['legal_links']['enabled'] ?? false)
                    <p class="{{ $style['legal_links']['wrapper'] ?? 'text-xs leading-5 text-slate-600' }}">
                        By registering, you agree to our
                        <a
                            href="{{ $page['legal_links']['links'][0]['url'] }}"
                            target="_blank"
                            rel="noopener"
                            class="{{ $style['legal_links']['link'] ?? 'font-semibold underline' }}"
                        >
                            {{ $page['legal_links']['links'][0]['label'] }}
                        </a>
                        and
                        <a
                            href="{{ $page['legal_links']['links'][1]['url'] }}"
                            target="_blank"
                            rel="noopener"
                            class="{{ $style['legal_links']['link'] ?? 'font-semibold underline' }}"
                        >
                            {{ $page['legal_links']['links'][1]['label'] }}
                        </a>.
                    </p>
                @endif

                <x-ui.button type="submit" class="{{ $tokens['primary_button'] ?? 'w-full' }}">
                    {{ $page['submit']['label'] ?? 'Reserve My Spot' }}
                </x-ui.button>
            </form>
        </x-ui.card>
    </div>
</div>