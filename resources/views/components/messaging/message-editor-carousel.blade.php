@props([
    'presentation' => [],
    'editable' => true,
    'emptyMessage' => 'No messages are configured for this group.',
    'initialMessageId' => null,
    'formContext' => [],
])

@php
    $channels = is_array($presentation['channels'] ?? null)
        ? $presentation['channels']
        : [];
    $channelKeys = array_keys($channels);
    $initialChannel = $channelKeys[0] ?? null;
    $indexes = [];
    $counts = [];

    foreach ($channels as $channelKey => $channel) {
        $messages = is_array($channel['messages'] ?? null)
            ? array_values($channel['messages'])
            : [];
        $counts[$channelKey] = count($messages);
        $indexes[$channelKey] = 0;

        if (is_string($initialMessageId) && $initialMessageId !== '') {
            foreach ($messages as $messageIndex => $message) {
                if ((string) ($message['id'] ?? '') === $initialMessageId) {
                    $initialChannel = $channelKey;
                    $indexes[$channelKey] = $messageIndex;
                    break;
                }
            }
        }
    }

    $failedEditId = (string) old('_editing_message_id', '');

    if ($failedEditId !== '') {
        foreach ($channels as $channelKey => $channel) {
            foreach (array_values($channel['messages'] ?? []) as $messageIndex => $message) {
                if ((string) ($message['id'] ?? '') === $failedEditId) {
                    $initialChannel = $channelKey;
                    $indexes[$channelKey] = $messageIndex;
                    break 2;
                }
            }
        }
    }
@endphp

@if($initialChannel === null)
    <div
        data-message-editor-carousel-empty
        class="rounded-2xl border border-dashed border-slate-300 bg-slate-50 px-5 py-8 text-center"
    >
        <p class="font-bold text-slate-900">{{ $emptyMessage }}</p>
    </div>
@else
    <div
        data-message-editor-carousel
        x-data="{
            channel: @js($initialChannel),
            indexes: @js($indexes),
            counts: @js($counts),
            editingId: @js($failedEditId !== '' ? $failedEditId : null),
            dirty: {{ $failedEditId !== '' ? 'true' : 'false' }},
            touchStartX: null,
            currentCount() {
                return this.counts[this.channel] || 0;
            },
            currentPosition() {
                return (this.indexes[this.channel] || 0) + 1;
            },
            canLeaveEdit() {
                if (! this.editingId || ! this.dirty) {
                    return true;
                }

                return window.confirm('Discard your unsaved message changes?');
            },
            setChannel(channel) {
                if (channel === this.channel || ! this.canLeaveEdit()) {
                    return;
                }

                this.channel = channel;
                this.editingId = null;
                this.dirty = false;
            },
            navigate(delta) {
                const count = this.currentCount();

                if (count <= 1 || ! this.canLeaveEdit()) {
                    return;
                }

                const current = this.indexes[this.channel] || 0;
                this.indexes[this.channel] = (current + delta + count) % count;
                this.editingId = null;
                this.dirty = false;
            },
            startEdit(id) {
                this.editingId = id;
                this.dirty = false;
            },
            cancelEdit() {
                if (! this.canLeaveEdit()) {
                    return;
                }

                this.editingId = null;
                this.dirty = false;
            },
            touchStart(event) {
                this.touchStartX = event.changedTouches?.[0]?.screenX ?? null;
            },
            touchEnd(event) {
                if (this.touchStartX === null) {
                    return;
                }

                const endX = event.changedTouches?.[0]?.screenX ?? this.touchStartX;
                const distance = endX - this.touchStartX;
                this.touchStartX = null;

                if (Math.abs(distance) < 55) {
                    return;
                }

                this.navigate(distance < 0 ? 1 : -1);
            },
        }"
        class="overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-sm"
    >
        <div class="border-b border-slate-200 bg-slate-50/90 px-4 py-3 sm:px-6">
            <div class="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
                <div class="flex flex-wrap gap-2" aria-label="Message channels">
                    @foreach($channels as $channelKey => $channel)
                        <button
                            type="button"
                            data-message-editor-channel="{{ $channelKey }}"
                            x-on:click="setChannel(@js($channelKey))"
                            x-bind:class="channel === @js($channelKey)
                                ? 'bg-slate-950 text-white ring-slate-950'
                                : 'bg-white text-slate-700 ring-slate-200 hover:bg-slate-100'"
                            class="inline-flex min-h-10 items-center justify-center rounded-full px-4 text-sm font-extrabold ring-1 transition"
                        >
                            {{ $channel['label'] ?? strtoupper((string) $channelKey) }}
                            <span
                                class="ml-2 rounded-full px-2 py-0.5 text-xs"
                                x-bind:class="channel === @js($channelKey)
                                    ? 'bg-white/15 text-white'
                                    : 'bg-slate-100 text-slate-600'"
                            >
                                {{ count($channel['messages'] ?? []) }}
                            </span>
                        </button>
                    @endforeach
                </div>

                <div class="flex flex-wrap items-center gap-2 text-xs font-extrabold uppercase tracking-[0.12em] text-slate-500">
                    <span
                        data-message-editor-mode
                        class="rounded-full bg-white px-3 py-1.5 text-slate-700 ring-1 ring-slate-200"
                        x-text="editingId ? 'Edit copy' : 'Published copy'"
                    ></span>
                    <span class="rounded-full bg-white px-3 py-1.5 ring-1 ring-slate-200">
                        <span x-text="currentPosition()"></span>
                        of
                        <span x-text="currentCount()"></span>
                    </span>
                </div>
            </div>
        </div>

        @foreach($channels as $channelKey => $channel)
            @php
                $messages = is_array($channel['messages'] ?? null)
                    ? array_values($channel['messages'])
                    : [];
                $messageCount = count($messages);
            @endphp

            <div
                x-show="channel === @js($channelKey)"
                x-cloak
                data-message-editor-channel-panel="{{ $channelKey }}"
                class="relative"
                x-on:touchstart.passive="touchStart($event)"
                x-on:touchend.passive="touchEnd($event)"
            >
                <div class="relative px-12 py-4 sm:px-20 sm:py-6">
                    @if($messageCount > 1)
                        <button
                            type="button"
                            aria-label="Previous message"
                            title="Previous message"
                            x-on:click.stop="navigate(-1)"
                            class="absolute inset-y-4 left-0 z-20 flex w-11 items-center justify-center text-slate-400 transition hover:bg-slate-100/80 hover:text-slate-950 focus:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-slate-500 sm:inset-y-6 sm:w-16"
                        >
                            <span aria-hidden="true" class="text-3xl leading-none">‹</span>
                        </button>

                        <button
                            type="button"
                            aria-label="Next message"
                            title="Next message"
                            x-on:click.stop="navigate(1)"
                            class="absolute inset-y-4 right-0 z-20 flex w-11 items-center justify-center text-slate-400 transition hover:bg-slate-100/80 hover:text-slate-950 focus:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-slate-500 sm:inset-y-6 sm:w-16"
                        >
                            <span aria-hidden="true" class="text-3xl leading-none">›</span>
                        </button>
                    @endif
                    @foreach($messages as $messageIndex => $message)
                        @php
                            $messageId = (string) ($message['id'] ?? $messageIndex);
                            $payload = is_array($message['payload'] ?? null)
                                ? $message['payload']
                                : [];
                            $editPayload = is_array($message['edit_payload'] ?? null)
                                ? $message['edit_payload']
                                : $payload;
                            $failedThisMessage = $failedEditId !== '' && $failedEditId === $messageId;
                            $cta = is_array($payload['cta'] ?? null) ? $payload['cta'] : [];
                            $secondaryLink = is_array($payload['secondary_link'] ?? null) ? $payload['secondary_link'] : [];
                            $ctas = is_array($payload['ctas'] ?? null) && array_is_list($payload['ctas'])
                                ? array_values(array_filter($payload['ctas'], fn (mixed $item): bool => is_array($item)))
                                : [];
                            $editCtas = is_array($editPayload['ctas'] ?? null) && array_is_list($editPayload['ctas'])
                                ? array_values(array_filter($editPayload['ctas'], fn (mixed $item): bool => is_array($item)))
                                : [];
                            $updateAction = is_string($message['update_action'] ?? null)
                                ? trim($message['update_action'])
                                : '';
                            $canEdit = $editable && $updateAction !== '';
                        @endphp

                        <article
                            x-show="indexes[@js($channelKey)] === {{ $messageIndex }}"
                            x-cloak
                            data-message-editor-message
                            data-message-editor-message-id="{{ $messageId }}"
                            class="mx-auto max-w-4xl overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm"
                        >
                            <header class="border-b border-slate-200 bg-white px-4 py-4 sm:px-6">
                                <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                                    <div class="min-w-0">
                                        @if(filled($message['area_label'] ?? null))
                                            <p class="text-xs font-extrabold uppercase tracking-[0.14em] text-slate-500">
                                                {{ $message['area_label'] }}
                                            </p>
                                        @endif

                                        <h3 class="mt-1 text-lg font-black tracking-tight text-slate-950">
                                            {{ $message['step_name'] ?? $message['template_name'] ?? 'Message' }}
                                        </h3>

                                        @if(filled($message['timing'] ?? null))
                                            <p class="mt-1 text-sm font-semibold leading-5 text-slate-600">
                                                {{ $message['timing'] }}
                                            </p>
                                        @endif
                                    </div>

                                    <div class="flex shrink-0 flex-wrap items-center gap-2">
                                        @if(is_string($message['details_url'] ?? null) && trim($message['details_url']) !== '')
                                            <a
                                                href="{{ $message['details_url'] }}"
                                                class="inline-flex min-h-10 items-center justify-center rounded-full border border-slate-300 bg-white px-4 text-xs font-extrabold text-slate-700 hover:bg-slate-50"
                                            >
                                                Details
                                            </a>
                                        @endif

                                        @if(is_string($message['template_edit_url'] ?? null) && trim($message['template_edit_url']) !== '')
                                            <a
                                                data-message-template-edit-link
                                                href="{{ $message['template_edit_url'] }}"
                                                class="inline-flex min-h-10 items-center justify-center rounded-full border border-slate-300 bg-white px-4 text-xs font-extrabold text-slate-700 hover:bg-slate-50"
                                            >
                                                Message Templates
                                            </a>
                                        @endif

                                        @if($canEdit)
                                            <button
                                                type="button"
                                                x-show="editingId !== @js($messageId)"
                                                x-on:click="startEdit(@js($messageId))"
                                                class="inline-flex min-h-10 items-center justify-center rounded-full bg-slate-950 px-4 text-xs font-extrabold text-white hover:bg-slate-800"
                                            >
                                                Edit
                                            </button>
                                        @endif
                                    </div>
                                </div>
                            </header>

                            <div
                                x-show="editingId !== @js($messageId)"
                                data-message-editor-published-preview
                                class="p-4 sm:p-6"
                            >
                                @if($channelKey === 'email')
                                    <div class="space-y-5">
                                        <div class="rounded-xl border border-slate-200 bg-slate-50 px-4 py-3">
                                            <p class="text-xs font-bold uppercase tracking-wide text-slate-500">Subject</p>
                                            <p class="mt-1 text-sm font-black text-slate-950">{{ $payload['subject'] ?? 'No subject' }}</p>
                                        </div>

                                        <div class="whitespace-pre-line text-sm leading-6 text-slate-800">{{ $payload['body'] ?? '' }}</div>

                                        @if($cta !== [] && filled($cta['label'] ?? null))
                                            <div>
                                                <span class="inline-flex min-h-10 items-center justify-center rounded-lg bg-slate-950 px-4 text-sm font-extrabold text-white">
                                                    {{ $cta['label'] }}
                                                </span>
                                                @if(filled($cta['url'] ?? null))
                                                    <p class="mt-2 break-all text-xs text-slate-500">{{ $cta['url'] }}</p>
                                                @endif
                                            </div>
                                        @endif

                                        @foreach($ctas as $listCta)
                                            @if(filled($listCta['label'] ?? null))
                                                <div>
                                                    <span class="inline-flex min-h-10 items-center justify-center rounded-lg bg-slate-950 px-4 text-sm font-extrabold text-white">
                                                        {{ $listCta['label'] }}
                                                    </span>
                                                    @if(filled($listCta['url'] ?? null))
                                                        <p class="mt-2 break-all text-xs text-slate-500">{{ $listCta['url'] }}</p>
                                                    @endif
                                                </div>
                                            @endif
                                        @endforeach

                                        @if($secondaryLink !== [] && filled($secondaryLink['label'] ?? null))
                                            <div class="text-sm">
                                                <span class="font-bold text-slate-800">{{ $secondaryLink['label'] }}</span>
                                                @if(filled($secondaryLink['url'] ?? null))
                                                    <span class="ml-2 break-all text-xs text-slate-500">{{ $secondaryLink['url'] }}</span>
                                                @endif
                                            </div>
                                        @endif

                                        @if(filled($payload['footer'] ?? null))
                                            <div class="border-t border-slate-200 pt-4 whitespace-pre-line text-xs leading-5 text-slate-500">{{ $payload['footer'] }}</div>
                                        @endif
                                    </div>
                                @elseif($channelKey === 'sms')
                                    <div class="mx-auto max-w-2xl rounded-2xl bg-slate-950 p-5 text-sm leading-6 text-white shadow-sm sm:p-6">
                                        <div class="whitespace-pre-line">{{ $payload['message'] ?? '' }}</div>
                                    </div>
                                @else
                                    <pre class="overflow-x-auto whitespace-pre-wrap rounded-xl bg-slate-950 p-4 text-xs text-slate-100">{{ json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
                                @endif

                                <p class="mt-5 text-xs leading-5 text-slate-500">
                                    Exact published preview. Dynamic tokens stay visible here and resolve when the message is prepared for a recipient.
                                </p>
                            </div>

                            @if($canEdit)
                                <form
                                    x-show="editingId === @js($messageId)"
                                    x-cloak
                                    method="POST"
                                    action="{{ $updateAction }}"
                                    x-on:input="dirty = true"
                                    x-on:change="dirty = true"
                                    data-message-editor-form
                                    class="space-y-5 p-4 sm:p-6"
                                >
                                    @csrf
                                    @method('PATCH')

                                    <input type="hidden" name="_editing_message_id" value="{{ $messageId }}">

                                    @foreach($formContext as $contextKey => $contextValue)
                                        @if(is_scalar($contextValue) || $contextValue === null)
                                            <input type="hidden" name="{{ $contextKey }}" value="{{ $contextValue }}">
                                        @endif
                                    @endforeach

                                    @if($channelKey === 'email')
                                        <div>
                                            <label class="mb-1.5 block text-sm font-extrabold text-slate-800">Subject</label>
                                            <input
                                                name="payload[subject]"
                                                value="{{ $failedThisMessage ? old('payload.subject', $editPayload['subject'] ?? '') : ($editPayload['subject'] ?? '') }}"
                                                class="block w-full rounded-xl border border-slate-300 bg-white px-4 py-2.5 text-sm text-slate-900 shadow-sm focus:border-slate-500 focus:outline-none focus:ring-0"
                                            >
                                            @if($failedThisMessage) @error('payload.subject')<p class="mt-2 text-sm font-semibold text-red-600">{{ $message }}</p>@enderror @endif
                                        </div>

                                        <div>
                                            <label class="mb-1.5 block text-sm font-extrabold text-slate-800">Body</label>
                                            <textarea
                                                name="payload[body]"
                                                rows="12"
                                                class="block w-full rounded-xl border border-slate-300 bg-white px-4 py-3 text-sm leading-6 text-slate-900 shadow-sm focus:border-slate-500 focus:outline-none focus:ring-0"
                                            >{{ $failedThisMessage ? old('payload.body', $editPayload['body'] ?? '') : ($editPayload['body'] ?? '') }}</textarea>
                                            @if($failedThisMessage) @error('payload.body')<p class="mt-2 text-sm font-semibold text-red-600">{{ $message }}</p>@enderror @endif
                                        </div>

                                        <div class="grid gap-4 sm:grid-cols-2">
                                            <div>
                                                <label class="mb-1.5 block text-sm font-extrabold text-slate-800">Primary button label</label>
                                                <input
                                                    name="payload[cta][label]"
                                                    value="{{ $failedThisMessage ? old('payload.cta.label', data_get($editPayload, 'cta.label', '')) : data_get($editPayload, 'cta.label', '') }}"
                                                    class="block w-full rounded-xl border border-slate-300 bg-white px-4 py-2.5 text-sm text-slate-900"
                                                >
                                            </div>
                                            <div>
                                                <label class="mb-1.5 block text-sm font-extrabold text-slate-800">Primary button URL</label>
                                                <input
                                                    name="payload[cta][url]"
                                                    value="{{ $failedThisMessage ? old('payload.cta.url', data_get($editPayload, 'cta.url', '')) : data_get($editPayload, 'cta.url', '') }}"
                                                    class="block w-full rounded-xl border border-slate-300 bg-white px-4 py-2.5 text-sm text-slate-900"
                                                >
                                            </div>
                                        </div>

                                        @foreach($editCtas as $ctaIndex => $editCta)
                                            <div class="grid gap-4 sm:grid-cols-2">
                                                <div>
                                                    <label class="mb-1.5 block text-sm font-extrabold text-slate-800">Additional button {{ $ctaIndex + 1 }} label</label>
                                                    <input
                                                        name="payload[ctas][{{ $ctaIndex }}][label]"
                                                        value="{{ $failedThisMessage ? old('payload.ctas.'.$ctaIndex.'.label', $editCta['label'] ?? '') : ($editCta['label'] ?? '') }}"
                                                        class="block w-full rounded-xl border border-slate-300 bg-white px-4 py-2.5 text-sm text-slate-900"
                                                    >
                                                </div>
                                                <div>
                                                    <label class="mb-1.5 block text-sm font-extrabold text-slate-800">Additional button {{ $ctaIndex + 1 }} URL</label>
                                                    <input
                                                        name="payload[ctas][{{ $ctaIndex }}][url]"
                                                        value="{{ $failedThisMessage ? old('payload.ctas.'.$ctaIndex.'.url', $editCta['url'] ?? '') : ($editCta['url'] ?? '') }}"
                                                        class="block w-full rounded-xl border border-slate-300 bg-white px-4 py-2.5 text-sm text-slate-900"
                                                    >
                                                </div>
                                            </div>
                                        @endforeach

                                        <div class="grid gap-4 sm:grid-cols-2">
                                            <div>
                                                <label class="mb-1.5 block text-sm font-extrabold text-slate-800">Secondary link label</label>
                                                <input
                                                    name="payload[secondary_link][label]"
                                                    value="{{ $failedThisMessage ? old('payload.secondary_link.label', data_get($editPayload, 'secondary_link.label', '')) : data_get($editPayload, 'secondary_link.label', '') }}"
                                                    class="block w-full rounded-xl border border-slate-300 bg-white px-4 py-2.5 text-sm text-slate-900"
                                                >
                                            </div>
                                            <div>
                                                <label class="mb-1.5 block text-sm font-extrabold text-slate-800">Secondary link URL</label>
                                                <input
                                                    name="payload[secondary_link][url]"
                                                    value="{{ $failedThisMessage ? old('payload.secondary_link.url', data_get($editPayload, 'secondary_link.url', '')) : data_get($editPayload, 'secondary_link.url', '') }}"
                                                    class="block w-full rounded-xl border border-slate-300 bg-white px-4 py-2.5 text-sm text-slate-900"
                                                >
                                            </div>
                                        </div>

                                        <div>
                                            <label class="mb-1.5 block text-sm font-extrabold text-slate-800">Footer</label>
                                            <textarea
                                                name="payload[footer]"
                                                rows="4"
                                                class="block w-full rounded-xl border border-slate-300 bg-white px-4 py-3 text-sm leading-6 text-slate-900"
                                            >{{ $failedThisMessage ? old('payload.footer', $editPayload['footer'] ?? '') : ($editPayload['footer'] ?? '') }}</textarea>
                                        </div>
                                    @elseif($channelKey === 'sms')
                                        <div>
                                            <label class="mb-1.5 block text-sm font-extrabold text-slate-800">Message</label>
                                            <textarea
                                                name="payload[message]"
                                                rows="9"
                                                class="block w-full rounded-xl border border-slate-300 bg-white px-4 py-3 text-sm leading-6 text-slate-900 shadow-sm focus:border-slate-500 focus:outline-none focus:ring-0"
                                            >{{ $failedThisMessage ? old('payload.message', $editPayload['message'] ?? '') : ($editPayload['message'] ?? '') }}</textarea>
                                            @if($failedThisMessage) @error('payload.message')<p class="mt-2 text-sm font-semibold text-red-600">{{ $message }}</p>@enderror @endif
                                        </div>
                                    @endif

                                    @if(filled($message['edit_note'] ?? null))
                                        <p class="rounded-xl bg-slate-50 px-4 py-3 text-xs leading-5 text-slate-600">
                                            {{ $message['edit_note'] }}
                                        </p>
                                    @endif

                                    <div class="flex flex-col-reverse gap-2 border-t border-slate-200 pt-5 sm:flex-row sm:items-center sm:justify-end">
                                        <button
                                            type="button"
                                            x-on:click="cancelEdit()"
                                            class="inline-flex min-h-11 items-center justify-center rounded-full border border-slate-300 bg-white px-5 text-sm font-extrabold text-slate-700 hover:bg-slate-50"
                                        >
                                            Cancel
                                        </button>
                                        <button
                                            type="submit"
                                            class="inline-flex min-h-11 items-center justify-center rounded-full bg-slate-950 px-6 text-sm font-extrabold text-white hover:bg-slate-800"
                                        >
                                            Save &amp; publish
                                        </button>
                                    </div>
                                </form>
                            @endif
                        </article>
                    @endforeach
                </div>
            </div>
        @endforeach

        <div class="border-t border-slate-200 bg-slate-50 px-4 py-3 text-center text-xs leading-5 text-slate-500 sm:px-6">
            Use the left and right edges, the arrow controls, or swipe on touch screens to move through this message group.
        </div>
    </div>
@endif