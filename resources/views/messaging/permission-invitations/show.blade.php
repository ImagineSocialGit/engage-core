<x-layouts.public
    :title="$title"
    :meta-description="$content['meta_description'] ?? null"
>
    <section class="{{ $style['section'] ?? 'bg-white text-slate-900' }}">
        <div class="{{ $style['inner'] ?? 'mx-auto w-full max-w-3xl px-6 py-16' }}">
            <div class="{{ $style['card'] ?? 'rounded-3xl border border-slate-200 bg-white p-6 shadow-xl' }}">
                @if (session('success'))
                    <div class="mb-6 rounded-2xl border border-green-200 bg-green-50 p-4 text-sm font-semibold text-green-800">
                        {{ session('success') }}
                    </div>
                @endif

                <div>
                    <div class="{{ $style['eyebrow'] ?? 'text-sm font-bold uppercase tracking-wide text-slate-500' }}">
                        {{ $content['eyebrow'] ?? 'Communication Preferences' }}
                    </div>

                    <h1 class="{{ $style['heading'] ?? 'mt-3 text-4xl font-extrabold tracking-tight text-slate-900' }}">
                        {{ $content['heading'] ?? 'Choose how you want to hear from us.' }}
                    </h1>

                    <p class="{{ $style['body'] ?? 'mt-5 text-lg leading-8 text-slate-600' }}">
                        {{ $content['body'] ?? 'Please confirm which messages you want to receive going forward.' }}
                    </p>
                </div>

                @if($contact)
                    <div class="mt-6 rounded-2xl border border-black/10 bg-white p-4 text-sm text-slate-700">
                        Confirming preferences for
                        <span class="font-bold text-slate-950">
                            {{ $contact->email }}
                        </span>
                    </div>
                @endif

                <form method="POST" action="{{ route('messaging.permission-invitations.store', $invitation->token) }}" class="mt-8 space-y-6">
                    @csrf

                    <div class="space-y-3">
                        <label class="{{ $style['option'] ?? 'block rounded-2xl border border-slate-200 p-4' }}">
                            <div class="flex gap-3">
                                <input
                                    type="checkbox"
                                    name="channels[]"
                                    value="email"
                                    class="mt-1"
                                    @checked(in_array('email', old('channels', ['email']), true))
                                >

                                <div>
                                    <div class="{{ $style['option_label'] ?? 'font-bold text-slate-900' }}">
                                        {{ $content['options']['email']['label'] ?? 'Email updates' }}
                                    </div>

                                    <div class="{{ $style['option_body'] ?? 'mt-1 text-sm text-slate-600' }}">
                                        {{ $content['options']['email']['body'] ?? 'Receive updates by email.' }}
                                    </div>
                                </div>
                            </div>
                        </label>

                        <label class="{{ $style['option'] ?? 'block rounded-2xl border border-slate-200 p-4' }}">
                            <div class="flex gap-3">
                                <input
                                    type="checkbox"
                                    name="channels[]"
                                    value="sms"
                                    class="mt-1"
                                    @checked(in_array('sms', old('channels', []), true))
                                >

                                <div>
                                    <div class="{{ $style['option_label'] ?? 'font-bold text-slate-900' }}">
                                        {{ $content['options']['sms']['label'] ?? 'Text message updates' }}
                                    </div>

                                    <div class="{{ $style['option_body'] ?? 'mt-1 text-sm text-slate-600' }}">
                                        {{ $content['options']['sms']['body'] ?? 'Receive updates by SMS.' }}
                                    </div>
                                </div>
                            </div>
                        </label>

                        @error('channels')
                            <p class="text-sm font-semibold text-red-600">{{ $message }}</p>
                        @enderror

                        @error('channels.*')
                            <p class="text-sm font-semibold text-red-600">{{ $message }}</p>
                        @enderror
                    </div>

                    <div>
                        <label for="phone" class="block text-sm font-bold text-slate-900">
                            {{ $content['phone_label'] ?? 'Mobile phone number' }}
                        </label>

                        <input
                            id="phone"
                            name="phone"
                            type="tel"
                            value="{{ old('phone', $contact?->phone) }}"
                            class="mt-2 w-full rounded-2xl border border-black/10 px-4 py-3 text-base font-medium text-slate-900 shadow-sm"
                        >

                        <p class="mt-2 text-xs text-slate-500">
                            {{ $content['phone_help'] ?? 'Required if you choose text messages.' }}
                        </p>

                        @error('phone')
                            <p class="mt-2 text-sm font-semibold text-red-600">{{ $message }}</p>
                        @enderror
                    </div>

                    <p class="{{ $style['legal'] ?? 'text-xs leading-5 text-slate-600' }}">
                        {{ $content['legal'] ?? '' }}
                    </p>

                    <button type="submit" class="{{ $style['button'] ?? 'rounded-full bg-slate-900 px-8 py-3 font-bold text-white' }}">
                        {{ $content['submit_label'] ?? 'Confirm my preferences' }}
                    </button>
                </form>
            </div>
        </div>
    </section>
</x-layouts.public>