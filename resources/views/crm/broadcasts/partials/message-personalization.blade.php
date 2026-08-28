<section
    class="space-y-4 rounded-2xl border border-slate-200 bg-slate-50 p-4 sm:p-5"
    data-broadcast-message-personalization
    x-data="{
        form: null,
        activeMessageInput: null,
        activeSelectionStart: null,
        copyRevision: 0,
        messageFieldGroups: @js($broadcastMessageFields),
        tokenFallbackPolicies: {},
        initialize(form) {
            this.form = form;
            this.setTokenFallbackPolicies(@js($initialTokenFallbacks ?? []));
        },
        setTokenFallbackPolicies(policies) {
            const normalized = {};

            if (Array.isArray(policies)) {
                policies.forEach((policy) => {
                    if (! policy || typeof policy.token !== 'string') {
                        return;
                    }

                    const token = policy.token.trim();
                    const behavior = ['required', 'fallback_value', 'replace_segment'].includes(policy.missing_behavior)
                        ? policy.missing_behavior
                        : 'required';

                    if (! token) {
                        return;
                    }

                    normalized[token] = {
                        missing_behavior: behavior,
                        fallback: typeof policy.fallback === 'string' ? policy.fallback : '',
                        segment: typeof policy.segment === 'string' ? policy.segment : '',
                    };
                });
            }

            this.tokenFallbackPolicies = normalized;
            this.copyRevision++;
        },
        ensureTokenFallbackPolicy(token) {
            if (this.tokenFallbackPolicies[token]) {
                return;
            }

            this.tokenFallbackPolicies[token] = {
                missing_behavior: 'required',
                fallback: '',
                segment: '',
            };
        },
        fieldMap() {
            const map = {};

            this.messageFieldGroups.forEach((group) => {
                (group.fields || []).forEach((field) => {
                    if (field.token) {
                        map[field.token] = field;
                    }

                    if (field.insert_token) {
                        map[field.insert_token] = field;
                    }
                });
            });

            return map;
        },
        currentChannel() {
            const input = this.form?.querySelector('[name=channel]');

            return input?.value === 'sms' ? 'sms' : 'email';
        },
        currentMessageCopy() {
            this.copyRevision;

            if (! this.form) {
                return '';
            }

            if (this.currentChannel() === 'sms') {
                return this.form.querySelector('[name=message]')?.value || '';
            }

            const subject = this.form.querySelector('[name=subject]')?.value || '';
            const body = this.form.querySelector('[name=body]')?.value || '';

            return `${subject}\n${body}`;
        },
        referencedTokens() {
            const copy = this.currentMessageCopy();
            const tokens = [];
            const braced = /\{([a-zA-Z_][a-zA-Z0-9_.:-]*)\}/g;
            const colon = /(^|[^a-zA-Z0-9_]):([a-zA-Z_][a-zA-Z0-9_-]*(?:\.[a-zA-Z_][a-zA-Z0-9_-]*)*)/g;
            let match;

            while ((match = braced.exec(copy)) !== null) {
                tokens.push(match[1]);
            }

            while ((match = colon.exec(copy)) !== null) {
                tokens.push(match[2]);
            }

            return [...new Set(tokens)];
        },
        usedMessageFields() {
            const map = this.fieldMap();
            const fields = this.referencedTokens()
                .filter((token) => Boolean(map[token]))
                .map((token) => ({
                    token,
                    label: map[token].label || token,
                    syntax: `{${token}}`,
                }));

            fields.forEach((field) => this.ensureTokenFallbackPolicy(field.token));

            return fields;
        },
        rememberMessageInput(target) {
            if (! target || ! ['subject', 'body', 'message'].includes(target.name)) {
                return;
            }

            if (this.form && target.closest('form') !== this.form) {
                return;
            }

            this.activeMessageInput = target;
            this.activeSelectionStart = Number.isInteger(target.selectionStart)
                ? target.selectionStart
                : target.value.length;
        },
        insertMessageField(syntax) {
            if (! this.form || typeof syntax !== 'string' || syntax === '') {
                return;
            }

            const channel = this.currentChannel();
            const allowedNames = channel === 'sms' ? ['message'] : ['subject', 'body'];
            let input = this.activeMessageInput;

            if (! input || ! allowedNames.includes(input.name) || input.closest('form') !== this.form) {
                input = this.form.querySelector(channel === 'sms' ? '[name=message]' : '[name=body]');
            }

            if (! input) {
                return;
            }

            const start = input === this.activeMessageInput && Number.isInteger(this.activeSelectionStart)
                ? this.activeSelectionStart
                : input.value.length;
            const end = input === this.activeMessageInput && Number.isInteger(input.selectionEnd)
                ? input.selectionEnd
                : start;
            const nextValue = input.value.slice(0, start) + syntax + input.value.slice(end);
            const cursor = start + syntax.length;

            input.value = nextValue;
            input.dispatchEvent(new Event('input', { bubbles: true }));
            this.copyRevision++;
            this.activeMessageInput = input;
            this.activeSelectionStart = cursor;

            requestAnimationFrame(() => {
                input.focus();
                input.setSelectionRange?.(cursor, cursor);
            });
        },
    }"
    x-init="initialize($el.closest('form'))"
    x-on:message-field-insert="insertMessageField($event.detail.syntax)"
    x-on:focusin.window="rememberMessageInput($event.target)"
    x-on:focusout.window="rememberMessageInput($event.target)"
    x-on:input.window="if (form && $event.target?.closest?.('form') === form) copyRevision++"
    x-on:change.window="if (form && $event.target?.closest?.('form') === form) copyRevision++"
    x-on:broadcast-message-template-applied.window="setTokenFallbackPolicies($event.detail.tokenFallbacks || [])"
>
    <input type="hidden" name="token_fallbacks_present" value="1">

    <div>
        <h3 class="text-sm font-bold text-slate-900">Personalize this message</h3>
        <p class="mt-1 text-xs leading-5 text-slate-600">
            Insert a Contact field and it will be filled separately for each recipient when the Broadcast is sent. If a field can be missing, choose what should happen instead of leaving the message unresolved.
        </p>
    </div>

    <x-messaging.available-fields :groups="$broadcastMessageFields" />

    @if($errors->has('token_fallbacks') || $errors->has('token_fallbacks.*'))
        <p class="text-sm font-semibold text-red-600">
            {{ $errors->first('token_fallbacks.*') ?: $errors->first('token_fallbacks') }}
        </p>
    @endif

    <div data-broadcast-token-fallbacks>
        <div class="mb-3">
            <h4 class="text-sm font-bold text-slate-900">If a field is missing</h4>
            <p class="mt-1 text-xs leading-5 text-slate-600">
                These choices apply only to fields currently used in the selected channel's copy.
            </p>
        </div>

        <p
            x-show="usedMessageFields().length === 0"
            class="rounded-xl bg-white px-3 py-3 text-xs leading-5 text-slate-600 ring-1 ring-slate-200"
        >
            Add a dynamic field above to configure its missing-value behavior.
        </p>

        <div class="space-y-3" x-show="usedMessageFields().length > 0">
            <template x-for="(item, fallbackIndex) in usedMessageFields()" x-bind:key="item.token">
                <div
                    class="rounded-xl border border-slate-200 bg-white p-3 sm:p-4"
                    x-bind:data-broadcast-token-fallback="item.token"
                >
                    <input
                        type="hidden"
                        x-bind:name="`token_fallbacks[${fallbackIndex}][token]`"
                        x-bind:value="item.token"
                    >

                    <div class="grid gap-3 lg:grid-cols-[minmax(0,1fr)_minmax(14rem,0.8fr)] lg:items-end">
                        <div>
                            <p class="text-sm font-extrabold text-slate-900" x-text="item.label"></p>
                            <p class="mt-0.5 font-mono text-[11px] text-slate-500" x-text="item.syntax"></p>
                        </div>

                        <div>
                            <label class="mb-1 block text-xs font-bold text-slate-700">When it’s missing</label>
                            <select
                                x-bind:name="`token_fallbacks[${fallbackIndex}][missing_behavior]`"
                                x-model="tokenFallbackPolicies[item.token].missing_behavior"
                                class="block w-full rounded-xl border border-slate-300 bg-white px-3 py-2.5 text-sm text-slate-900"
                            >
                                <option value="required">Don’t send this message</option>
                                <option value="fallback_value">Use another value</option>
                                <option value="replace_segment">Replace this phrase</option>
                            </select>
                        </div>
                    </div>

                    <div
                        class="mt-3"
                        x-show="tokenFallbackPolicies[item.token].missing_behavior === 'fallback_value'"
                    >
                        <label class="mb-1 block text-xs font-bold text-slate-700">Fallback value</label>
                        <input
                            type="text"
                            x-bind:name="`token_fallbacks[${fallbackIndex}][fallback]`"
                            x-model="tokenFallbackPolicies[item.token].fallback"
                            x-bind:disabled="tokenFallbackPolicies[item.token].missing_behavior !== 'fallback_value'"
                            placeholder="Example: there"
                            class="block w-full rounded-xl border border-slate-300 bg-white px-3 py-2.5 text-sm text-slate-900"
                        >
                        <p class="mt-1 text-xs text-slate-500">Use literal text only; another dynamic field cannot be used as the fallback.</p>
                    </div>

                    <div
                        class="mt-3 grid gap-3 lg:grid-cols-2"
                        x-show="tokenFallbackPolicies[item.token].missing_behavior === 'replace_segment'"
                    >
                        <div>
                            <label class="mb-1 block text-xs font-bold text-slate-700">Exact phrase to replace</label>
                            <textarea
                                x-bind:name="`token_fallbacks[${fallbackIndex}][segment]`"
                                x-model="tokenFallbackPolicies[item.token].segment"
                                x-bind:disabled="tokenFallbackPolicies[item.token].missing_behavior !== 'replace_segment'"
                                rows="3"
                                placeholder="Hey {first_name}, "
                                class="block w-full rounded-xl border border-slate-300 bg-white px-3 py-2.5 text-sm text-slate-900"
                            ></textarea>
                        </div>

                        <div>
                            <label class="mb-1 block text-xs font-bold text-slate-700">Replacement text</label>
                            <textarea
                                x-bind:name="`token_fallbacks[${fallbackIndex}][fallback]`"
                                x-model="tokenFallbackPolicies[item.token].fallback"
                                x-bind:disabled="tokenFallbackPolicies[item.token].missing_behavior !== 'replace_segment'"
                                rows="3"
                                placeholder="Leave blank to remove the phrase"
                                class="block w-full rounded-xl border border-slate-300 bg-white px-3 py-2.5 text-sm text-slate-900"
                            ></textarea>
                        </div>
                    </div>
                </div>
            </template>
        </div>
    </div>
</section>