@props([
    'presentation' => [],
    'editable' => true,
    'emptyMessage' => 'No messages are configured for this group.',
    'initialMessageId' => null,
    'formContext' => [],
    'tokenFallbacksEditable' => false,
    'mediaAvailable' => false,
    'mediaAssets' => [],
    'mediaLibraryUrl' => null,
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
    $failedReplyProfileKey = (string) old('reply_editor_profile_key', '');
    $replyProfileOptions = is_array($presentation['reply_profile_options'] ?? null)
        ? $presentation['reply_profile_options']
        : [];
    $mediaAssets = is_array($mediaAssets)
        ? array_values(array_filter($mediaAssets, fn (mixed $asset): bool => is_array($asset)))
        : [];
    $mediaAssetUuids = array_values(array_filter(array_map(
        fn (array $asset): ?string => is_string($asset['uuid'] ?? null) ? trim($asset['uuid']) : null,
        $mediaAssets,
    )));
    $mediaImageAssets = array_values(array_filter(
        $mediaAssets,
        fn (array $asset): bool => ($asset['kind'] ?? null) === 'image',
    ));

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

    if ($failedReplyProfileKey !== '') {
        foreach ($channels as $channelKey => $channel) {
            foreach (array_values($channel['messages'] ?? []) as $messageIndex => $message) {
                if ((string) ($message['reply_profile_key'] ?? '') === $failedReplyProfileKey) {
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
            replyEditingKey: @js($failedReplyProfileKey !== '' ? $failedReplyProfileKey : null),
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
                this.replyEditingKey = null;
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
                this.replyEditingKey = null;
                this.dirty = false;
            },
            startEdit(id) {
                this.replyEditingKey = null;
                this.editingId = id;
                this.dirty = false;
            },
            openReply(key) {
                if (! this.canLeaveEdit()) {
                    return;
                }

                this.editingId = null;
                this.replyEditingKey = key;
                this.dirty = false;
            },
            closeReply() {
                this.replyEditingKey = null;
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
                        x-text="editingId ? 'Edit copy' : (replyEditingKey ? 'Edit replies' : 'Published copy')"
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
                            $media = is_array($payload['media'] ?? null) && ! array_is_list($payload['media']) ? $payload['media'] : [];
                            $editMedia = is_array($editPayload['media'] ?? null) && ! array_is_list($editPayload['media']) ? $editPayload['media'] : [];
                            $currentMediaUuid = is_string($editMedia['asset_uuid'] ?? null) ? trim($editMedia['asset_uuid']) : '';
                            $currentPosterUuid = is_string($editMedia['poster_asset_uuid'] ?? null) ? trim($editMedia['poster_asset_uuid']) : '';
                            $selectedMediaUuid = $failedThisMessage ? (string) old('payload.media_asset_uuid', $currentMediaUuid) : $currentMediaUuid;
                            $selectedPosterUuid = $failedThisMessage ? (string) old('payload.media_poster_asset_uuid', $currentPosterUuid) : $currentPosterUuid;
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
                            $replyHandling = is_array($message['reply_handling'] ?? null)
                                ? $message['reply_handling']
                                : null;
                            $replyProfileKey = is_string($message['reply_profile_key'] ?? null)
                                ? trim($message['reply_profile_key'])
                                : '';
                            $replyHandlingUpdateAction = is_string($message['reply_handling_update_action'] ?? null)
                                ? trim($message['reply_handling_update_action'])
                                : '';
                            $replyHandlingIndexUrl = is_string($message['reply_handling_index_url'] ?? null)
                                ? trim($message['reply_handling_index_url'])
                                : '';
                            $replyHandlingVersionId = (int) ($message['reply_handling_version_id'] ?? 0);
                            $canAssignReplyHandling = $editable
                                && $replyHandlingUpdateAction !== ''
                                && $replyHandlingVersionId > 0;
                            $replyHandlingUsages = is_array($message['reply_handling_usages'] ?? null)
                                ? array_values(array_filter(
                                    $message['reply_handling_usages'],
                                    fn (mixed $usage): bool => is_array($usage),
                                ))
                                : [];
                            $replyOutcomeDependencies = $replyHandling !== null
                                && is_array($replyHandling['dependencies'] ?? null)
                                    ? array_values(array_filter(
                                        $replyHandling['dependencies'],
                                        fn (mixed $dependency): bool =>
                                            is_array($dependency)
                                            && ($dependency['module_key'] ?? null) !== 'messaging',
                                    ))
                                    : [];
                            $tokenValidator = app(\App\Modules\Messaging\Services\MessageTemplateTokenValidator::class);
                            $tokenFallbackResolver = app(\App\Modules\Messaging\Services\MessageTokenFallbackResolver::class);
                            $submittedEditPayload = $failedThisMessage && is_array(old('payload'))
                                ? array_replace_recursive($editPayload, old('payload'))
                                : $editPayload;
                            $dynamicFields = $tokenValidator->resolvableTokensFromPayload($submittedEditPayload);
                            $fallbackPolicyPayload = $failedThisMessage
                                ? ['token_fallbacks' => old('payload.token_fallbacks', $editPayload['token_fallbacks'] ?? [])]
                                : $editPayload;
                            $fallbackPolicies = collect($tokenFallbackResolver->policies($fallbackPolicyPayload))
                                ->keyBy('token')
                                ->all();
                            $publishedDynamicFields = $tokenValidator->resolvableTokensFromPayload($payload);
                            $publishedFallbackPolicies = collect($tokenFallbackResolver->policies($payload))
                                ->keyBy('token')
                                ->all();
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

                                        @if($media !== [])
                                            <div data-message-media-preview class="rounded-2xl border border-violet-200 bg-violet-50 p-4">
                                                <div class="flex flex-wrap items-center justify-between gap-2">
                                                    <div>
                                                        <div class="text-xs font-extrabold uppercase tracking-wide text-violet-700">{{ ucfirst((string) ($media['kind'] ?? 'media')) }}</div>
                                                        <div class="mt-1 text-sm font-black text-slate-950">{{ $media['title'] ?? 'Media' }}</div>
                                                    </div>
                                                    @if(filled($media['url'] ?? null))
                                                        <a href="{{ $media['url'] }}" target="_blank" rel="noopener noreferrer" class="text-xs font-extrabold text-violet-800 underline decoration-violet-300 underline-offset-4">Open media</a>
                                                    @endif
                                                </div>
                                                @if(($media['kind'] ?? null) === 'video' && filled($media['poster_url'] ?? null))
                                                    <img src="{{ $media['poster_url'] }}" alt="" class="mt-3 max-h-56 w-full rounded-xl object-cover">
                                                @endif
                                            </div>
                                        @endif

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
                                    Exact published preview. Dynamic fields stay visible here and resolve when the message is prepared for a recipient.
                                </p>

                                @if($publishedDynamicFields !== [])
                                    <section data-message-token-fallback-summary class="mt-5 rounded-2xl border border-slate-200 bg-slate-50 p-4">
                                        <p class="text-xs font-extrabold uppercase tracking-[0.14em] text-slate-500">If a field is missing</p>
                                        <div class="mt-3 grid gap-2 sm:grid-cols-2">
                                            @foreach($publishedDynamicFields as $publishedToken)
                                                @php
                                                    $publishedPolicy = $publishedFallbackPolicies[$publishedToken] ?? [
                                                        'missing_behavior' => \App\Modules\Messaging\Services\MessageTokenFallbackResolver::BEHAVIOR_REQUIRED,
                                                    ];
                                                    $publishedBehavior = $publishedPolicy['missing_behavior'];
                                                    $publishedLabel = \Illuminate\Support\Str::headline(str_replace(['.', '_'], ' ', $publishedToken));
                                                @endphp
                                                <div data-message-token-fallback-summary-item="{{ $publishedToken }}" class="rounded-xl bg-white px-3 py-2.5 ring-1 ring-slate-200">
                                                    <p class="text-xs font-bold text-slate-900">{{ $publishedLabel }}</p>
                                                    @if($publishedBehavior === \App\Modules\Messaging\Services\MessageTokenFallbackResolver::BEHAVIOR_FALLBACK_VALUE)
                                                        <p class="mt-0.5 text-xs text-slate-600">Use “{{ $publishedPolicy['fallback'] ?? '' }}” instead.</p>
                                                    @elseif($publishedBehavior === \App\Modules\Messaging\Services\MessageTokenFallbackResolver::BEHAVIOR_REPLACE_SEGMENT)
                                                        <p class="mt-0.5 text-xs text-slate-600">Replace the configured phrase when this field is unavailable.</p>
                                                    @else
                                                        <p class="mt-0.5 text-xs text-slate-600">Do not send if this field is unavailable.</p>
                                                    @endif
                                                </div>
                                            @endforeach
                                        </div>
                                    </section>
                                @endif

                                @if($canAssignReplyHandling || $replyHandling !== null || $replyHandlingUsages !== [])
                                    <section data-message-reply-handling class="mt-6 rounded-2xl border border-indigo-200 bg-indigo-50/60 p-4 sm:p-5">
                                        <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                                            <div>
                                                <p class="text-xs font-extrabold uppercase tracking-[0.14em] text-indigo-700">When someone replies</p>

                                                @if($replyHandling !== null)
                                                    <h4 class="mt-1 font-black text-slate-950">{{ $replyHandling['label'] }}</h4>
                                                    @if(filled($replyHandling['description'] ?? null))
                                                        <p class="mt-1 text-sm leading-6 text-slate-600">{{ $replyHandling['description'] }}</p>
                                                    @endif
                                                @elseif($replyHandlingUsages !== [])
                                                    <p class="mt-1 text-sm font-semibold text-slate-800">Reply handling varies by where this reusable message is used.</p>
                                                @else
                                                    <p class="mt-1 text-sm font-semibold text-slate-800">No special reply handling is attached to this message.</p>
                                                @endif
                                            </div>

                                            @if($replyHandling !== null && $editable && is_string($replyHandling['update_action'] ?? null) && trim($replyHandling['update_action']) !== '')
                                                <button
                                                    type="button"
                                                    data-message-reply-editor-open
                                                    x-on:click="openReply(@js($replyProfileKey))"
                                                    class="inline-flex min-h-10 shrink-0 items-center justify-center rounded-full border border-indigo-300 bg-white px-4 text-xs font-extrabold text-indigo-900 hover:bg-indigo-50"
                                                >
                                                    Review &amp; edit
                                                </button>
                                            @elseif($replyHandling !== null && is_string($replyHandling['details_url'] ?? null) && trim($replyHandling['details_url']) !== '')
                                                <a
                                                    href="{{ $replyHandling['details_url'] }}"
                                                    class="inline-flex min-h-10 shrink-0 items-center justify-center rounded-full border border-indigo-300 bg-white px-4 text-xs font-extrabold text-indigo-900 hover:bg-indigo-50"
                                                >
                                                    View reply handling
                                                </a>
                                            @endif
                                        </div>

                                        @if($replyHandling !== null)
                                            <div class="mt-4 grid gap-3 md:grid-cols-2">
                                                <div class="rounded-xl bg-white p-3 ring-1 ring-indigo-100">
                                                    <p class="text-xs font-extrabold uppercase tracking-wide text-slate-500">Recognized replies</p>
                                                    <div class="mt-2 space-y-2">
                                                        @foreach(($replyHandling['intents'] ?? []) as $intent)
                                                            @continue(! ($intent['active'] ?? false))
                                                            <div class="text-sm leading-5">
                                                                <span class="font-bold text-slate-900">{{ $intent['label'] }}</span>
                                                                <span class="block text-xs text-slate-600">
                                                                    {{ implode(', ', array_values(array_unique(array_merge($intent['exact'] ?? [], $intent['keywords'] ?? [])))) }}
                                                                </span>
                                                            </div>
                                                        @endforeach
                                                    </div>
                                                </div>

                                                <div class="rounded-xl bg-white p-3 ring-1 ring-indigo-100">
                                                    <p class="text-xs font-extrabold uppercase tracking-wide text-slate-500">What happens</p>
                                                    @if($replyOutcomeDependencies !== [])
                                                        <div class="mt-2 space-y-2">
                                                            @foreach($replyOutcomeDependencies as $dependency)
                                                                <div class="text-sm leading-5">
                                                                    <div class="flex items-start justify-between gap-3">
                                                                        <div class="min-w-0">
                                                                            <span class="font-bold text-slate-900">{{ $dependency['label'] }}</span>
                                                                            @if(filled($dependency['detail'] ?? null))
                                                                                <span class="block text-xs text-slate-600">{{ $dependency['detail'] }}</span>
                                                                            @endif
                                                                        </div>
                                                                        @if(is_string($dependency['url'] ?? null) && trim($dependency['url']) !== '')
                                                                            <a
                                                                                data-message-reply-dependency-link
                                                                                href="{{ $dependency['url'] }}"
                                                                                class="shrink-0 text-xs font-extrabold text-indigo-800 underline decoration-indigo-300 underline-offset-4 hover:text-indigo-950"
                                                                            >
                                                                                Edit
                                                                            </a>
                                                                        @endif
                                                                    </div>
                                                                </div>
                                                            @endforeach
                                                        </div>
                                                    @else
                                                        <p class="mt-2 text-sm leading-5 text-slate-600">The reply is classified for reporting, but no follow-up automation currently uses it.</p>
                                                    @endif
                                                </div>
                                            </div>
                                        @endif

                                        @if($replyHandlingUsages !== [])
                                            <div data-message-reply-handling-usages class="mt-4 border-t border-indigo-200 pt-4">
                                                <p class="text-xs font-extrabold uppercase tracking-wide text-slate-500">Where this reply handling applies</p>

                                                @if($replyHandling !== null)
                                                    <div class="mt-2 grid gap-2 sm:grid-cols-2">
                                                        @foreach($replyHandlingUsages as $usage)
                                                            <div data-message-reply-handling-usage class="rounded-xl bg-white px-3 py-3 ring-1 ring-indigo-100">
                                                                <p class="text-sm font-bold text-slate-900">{{ $usage['context_label'] ?? $usage['module_label'] ?? 'Message usage' }}</p>
                                                                <p class="mt-0.5 text-xs text-slate-600">{{ $usage['item_label'] ?? '' }}</p>
                                                                @if(filled($usage['detail'] ?? null))
                                                                    <p class="mt-1 text-xs text-slate-500">{{ $usage['detail'] }}</p>
                                                                @endif
                                                                @if(is_string($usage['owner_url'] ?? null) && trim($usage['owner_url']) !== '')
                                                                    <a
                                                                        data-message-reply-usage-link
                                                                        href="{{ $usage['owner_url'] }}"
                                                                        class="mt-2 inline-flex text-xs font-extrabold text-indigo-800 underline decoration-indigo-300 underline-offset-4 hover:text-indigo-950"
                                                                    >
                                                                        Manage this usage
                                                                    </a>
                                                                @endif
                                                            </div>
                                                        @endforeach
                                                    </div>
                                                @else
                                                    <div class="mt-2 grid gap-3 md:grid-cols-2">
                                                        @foreach($replyHandlingUsages as $usage)
                                                            @php
                                                                $usageReplyHandling = is_array($usage['reply_handling'] ?? null)
                                                                    ? $usage['reply_handling']
                                                                    : null;
                                                                $usageDependencies = $usageReplyHandling !== null
                                                                    && is_array($usageReplyHandling['dependencies'] ?? null)
                                                                        ? array_values(array_filter(
                                                                            $usageReplyHandling['dependencies'],
                                                                            fn (mixed $dependency): bool =>
                                                                                is_array($dependency)
                                                                                && ($dependency['module_key'] ?? null) !== 'messaging',
                                                                        ))
                                                                        : [];
                                                            @endphp

                                                            <article
                                                                data-message-reply-handling-usage
                                                                data-message-reply-profile="{{ $usage['reply_profile_key'] ?? '' }}"
                                                                class="rounded-xl bg-white p-3 ring-1 ring-indigo-100"
                                                            >
                                                                <p class="text-xs font-extrabold uppercase tracking-wide text-slate-500">{{ $usage['context_label'] ?? $usage['module_label'] ?? 'Message usage' }}</p>
                                                                <p class="mt-1 text-sm font-black text-slate-950">
                                                                    {{ $usageReplyHandling['label'] ?? ($usage['reply_profile_key'] ?? 'Unavailable reply profile') }}
                                                                </p>
                                                                @if(filled($usage['item_label'] ?? null))
                                                                    <p class="mt-1 text-xs text-slate-600">{{ $usage['item_label'] }}</p>
                                                                @endif

                                                                @if($usageReplyHandling !== null)
                                                                    <div class="mt-3 space-y-2">
                                                                        @foreach(($usageReplyHandling['intents'] ?? []) as $intent)
                                                                            @continue(! ($intent['active'] ?? false))
                                                                            <div class="text-xs leading-5 text-slate-600">
                                                                                <span class="font-bold text-slate-900">{{ $intent['label'] }}</span>
                                                                                <span class="block">{{ implode(', ', array_values(array_unique(array_merge($intent['exact'] ?? [], $intent['keywords'] ?? [])))) }}</span>
                                                                            </div>
                                                                        @endforeach
                                                                    </div>

                                                                    @if($usageDependencies !== [])
                                                                        <div class="mt-3 space-y-2 border-t border-indigo-100 pt-3">
                                                                            @foreach($usageDependencies as $dependency)
                                                                                <div class="flex items-start justify-between gap-3 text-xs leading-5">
                                                                                    <div>
                                                                                        <span class="font-bold text-slate-900">{{ $dependency['label'] }}</span>
                                                                                        @if(filled($dependency['detail'] ?? null))
                                                                                            <span class="block text-slate-600">{{ $dependency['detail'] }}</span>
                                                                                        @endif
                                                                                    </div>
                                                                                    @if(is_string($dependency['url'] ?? null) && trim($dependency['url']) !== '')
                                                                                        <a data-message-reply-dependency-link href="{{ $dependency['url'] }}" class="shrink-0 font-extrabold text-indigo-800 underline decoration-indigo-300 underline-offset-4 hover:text-indigo-950">Edit</a>
                                                                                    @endif
                                                                                </div>
                                                                            @endforeach
                                                                        </div>
                                                                    @endif

                                                                    @if(is_string($usageReplyHandling['details_url'] ?? null) && trim($usageReplyHandling['details_url']) !== '')
                                                                        <a href="{{ $usageReplyHandling['details_url'] }}" class="mt-3 inline-flex text-xs font-extrabold text-indigo-800 underline decoration-indigo-300 underline-offset-4 hover:text-indigo-950">Open reply handling</a>
                                                                    @endif
                                                                @endif

                                                                @if(is_string($usage['owner_url'] ?? null) && trim($usage['owner_url']) !== '')
                                                                    <a data-message-reply-usage-link href="{{ $usage['owner_url'] }}" class="mt-3 ml-3 inline-flex text-xs font-extrabold text-slate-700 underline decoration-slate-300 underline-offset-4 hover:text-slate-950">Manage usage</a>
                                                                @endif
                                                            </article>
                                                        @endforeach
                                                    </div>
                                                @endif
                                            </div>
                                        @endif

                                        @if($canAssignReplyHandling)
                                            <form method="POST" action="{{ $replyHandlingUpdateAction }}" data-message-reply-profile-form class="mt-4 flex flex-col gap-2 border-t border-indigo-200 pt-4 sm:flex-row sm:items-end">
                                                @csrf
                                                @method('PATCH')
                                                <input type="hidden" name="message_chain_version_id" value="{{ $formContext['message_chain_version_id'] ?? $replyHandlingVersionId }}">
                                                <div class="min-w-0 flex-1">
                                                    <label class="text-xs font-extrabold uppercase tracking-wide text-slate-600">Reply rule for this message</label>
                                                    <select name="reply_profile_key" class="mt-1 block w-full rounded-xl border border-slate-300 bg-white px-3 py-2.5 text-sm text-slate-900">
                                                        <option value="">No special reply handling</option>
                                                        @foreach($replyProfileOptions as $option)
                                                            <option
                                                                value="{{ $option['value'] }}"
                                                                @selected($replyProfileKey === (string) $option['value'])
                                                                @disabled(! ($option['active'] ?? false) && $replyProfileKey !== (string) $option['value'])
                                                            >
                                                                {{ $option['label'] }}{{ ($option['active'] ?? false) ? '' : ' (inactive)' }}
                                                            </option>
                                                        @endforeach
                                                    </select>
                                                </div>
                                                <button type="submit" class="inline-flex min-h-10 items-center justify-center rounded-full bg-indigo-950 px-5 text-xs font-extrabold text-white hover:bg-indigo-900">
                                                    Save reply rule
                                                </button>
                                            </form>
                                        @endif
                                    </section>
                                @endif
                            </div>

                            @if($canEdit)
                                <form
                                    x-show="editingId === @js($messageId)"
                                    x-cloak
                                    method="POST"
                                    action="{{ $updateAction }}"
                                    enctype="multipart/form-data"
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
                                        <x-ui.message-editor
                                            :subject="[
                                                'label' => 'Subject',
                                                'name' => 'payload[subject]',
                                                'value' => $failedThisMessage ? old('payload.subject', $editPayload['subject'] ?? '') : ($editPayload['subject'] ?? ''),
                                                'error' => $failedThisMessage ? $errors->first('payload.subject') : null,
                                            ]"
                                            :body="[
                                                'label' => 'Body',
                                                'name' => 'payload[body]',
                                                'rows' => 12,
                                                'value' => $failedThisMessage ? old('payload.body', $editPayload['body'] ?? '') : ($editPayload['body'] ?? ''),
                                                'error' => $failedThisMessage ? $errors->first('payload.body') : null,
                                            ]"
                                        />

                                        <x-messaging.message-media-authoring
                                            :current-media="$editMedia"
                                            field-prefix="payload"
                                            :failed="$failedThisMessage"
                                        />

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
                                        <x-ui.message-editor
                                            :sms="[
                                                'label' => 'Message',
                                                'name' => 'payload[message]',
                                                'rows' => 9,
                                                'value' => $failedThisMessage ? old('payload.message', $editPayload['message'] ?? '') : ($editPayload['message'] ?? ''),
                                                'error' => $failedThisMessage ? $errors->first('payload.message') : null,
                                            ]"
                                        />
                                    @endif

                                    @if($tokenFallbacksEditable && ($dynamicFields !== [] || array_key_exists('token_fallbacks', $editPayload)))
                                        <section data-message-token-fallbacks class="rounded-2xl border border-slate-200 bg-slate-50 p-4 sm:p-5">
                                            <input type="hidden" name="payload[token_fallbacks_present]" value="1">

                                            <div>
                                                <h4 class="text-sm font-black text-slate-950">If a field is missing</h4>
                                                <p class="mt-1 text-xs leading-5 text-slate-600">
                                                    Dynamic fields are names, dates, links, or other details filled in for each recipient. Choose what this message should do when one is unavailable.
                                                </p>
                                            </div>

                                            @if($dynamicFields === [])
                                                <p class="mt-4 rounded-xl bg-white px-3 py-3 text-xs leading-5 text-slate-600 ring-1 ring-slate-200">
                                                    This edited copy no longer uses a dynamic field. Saving will remove its old missing-field rules.
                                                </p>
                                            @else
                                                <div class="mt-4 space-y-3">
                                                    @foreach($dynamicFields as $fallbackIndex => $dynamicField)
                                                        @php
                                                            $fallbackPolicy = $fallbackPolicies[$dynamicField] ?? [
                                                                'token' => $dynamicField,
                                                                'missing_behavior' => \App\Modules\Messaging\Services\MessageTokenFallbackResolver::BEHAVIOR_REQUIRED,
                                                                'fallback' => '',
                                                                'segment' => '',
                                                            ];
                                                            $fallbackBehavior = $fallbackPolicy['missing_behavior'] ?? \App\Modules\Messaging\Services\MessageTokenFallbackResolver::BEHAVIOR_REQUIRED;
                                                            $dynamicFieldLabel = \Illuminate\Support\Str::headline(str_replace(['.', '_'], ' ', $dynamicField));
                                                        @endphp

                                                        <div
                                                            data-message-token-fallback="{{ $dynamicField }}"
                                                            x-data="{ behavior: @js($fallbackBehavior) }"
                                                            class="rounded-xl border border-slate-200 bg-white p-3 sm:p-4"
                                                        >
                                                            <input type="hidden" name="payload[token_fallbacks][{{ $fallbackIndex }}][token]" value="{{ $dynamicField }}">

                                                            <div class="grid gap-3 lg:grid-cols-[minmax(0,1fr)_minmax(14rem,0.8fr)] lg:items-end">
                                                                <div>
                                                                    <p class="text-sm font-extrabold text-slate-900">{{ $dynamicFieldLabel }}</p>
                                                                    <p class="mt-0.5 font-mono text-[11px] text-slate-500">{{ '{'.$dynamicField.'}' }}</p>
                                                                </div>
                                                                <div>
                                                                    <label class="mb-1 block text-xs font-bold text-slate-700">When it’s missing</label>
                                                                    <select
                                                                        name="payload[token_fallbacks][{{ $fallbackIndex }}][missing_behavior]"
                                                                        x-model="behavior"
                                                                        class="block w-full rounded-xl border border-slate-300 bg-white px-3 py-2.5 text-sm text-slate-900"
                                                                    >
                                                                        <option value="required">Don’t send this message</option>
                                                                        <option value="fallback_value">Use another value</option>
                                                                        <option value="replace_segment">Replace this phrase</option>
                                                                    </select>
                                                                </div>
                                                            </div>

                                                            <div x-show="behavior === 'fallback_value'" x-cloak class="mt-3">
                                                                <label class="mb-1 block text-xs font-bold text-slate-700">Use this value instead</label>
                                                                <input
                                                                    name="payload[token_fallbacks][{{ $fallbackIndex }}][fallback]"
                                                                    x-bind:disabled="behavior !== 'fallback_value'"
                                                                    value="{{ $fallbackPolicy['fallback'] ?? '' }}"
                                                                    placeholder="For example: there"
                                                                    class="block w-full rounded-xl border border-slate-300 bg-white px-3 py-2.5 text-sm text-slate-900"
                                                                >
                                                                @if($failedThisMessage)
                                                                    @error('payload.token_fallbacks.'.$fallbackIndex.'.fallback')<p class="mt-2 text-sm font-semibold text-red-600">{{ $message }}</p>@enderror
                                                                @endif
                                                            </div>

                                                            <div x-show="behavior === 'replace_segment'" x-cloak class="mt-3 grid gap-3 sm:grid-cols-2">
                                                                <div>
                                                                    <label class="mb-1 block text-xs font-bold text-slate-700">Exact phrase to replace</label>
                                                                    <textarea
                                                                        name="payload[token_fallbacks][{{ $fallbackIndex }}][segment]"
                                                                        x-bind:disabled="behavior !== 'replace_segment'"
                                                                        rows="3"
                                                                        placeholder="Hey {{ '{'.$dynamicField.'}' }}, "
                                                                        class="block w-full rounded-xl border border-slate-300 bg-white px-3 py-2.5 text-sm leading-5 text-slate-900"
                                                                    >{{ $fallbackPolicy['segment'] ?? '' }}</textarea>
                                                                    @if($failedThisMessage)
                                                                        @error('payload.token_fallbacks.'.$fallbackIndex.'.segment')<p class="mt-2 text-sm font-semibold text-red-600">{{ $message }}</p>@enderror
                                                                    @endif
                                                                </div>
                                                                <div>
                                                                    <label class="mb-1 block text-xs font-bold text-slate-700">Replacement text</label>
                                                                    <textarea
                                                                        name="payload[token_fallbacks][{{ $fallbackIndex }}][fallback]"
                                                                        x-bind:disabled="behavior !== 'replace_segment'"
                                                                        rows="3"
                                                                        placeholder="Leave blank to remove the phrase"
                                                                        class="block w-full rounded-xl border border-slate-300 bg-white px-3 py-2.5 text-sm leading-5 text-slate-900"
                                                                    >{{ $fallbackPolicy['fallback'] ?? '' }}</textarea>
                                                                </div>
                                                            </div>
                                                        </div>
                                                    @endforeach
                                                </div>
                                            @endif
                                        </section>
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

                            @if($editable && $replyHandling !== null && is_string($replyHandling['update_action'] ?? null) && trim($replyHandling['update_action']) !== '')
                                <div
                                    x-show="replyEditingKey === @js($replyProfileKey)"
                                    x-cloak
                                    class="fixed inset-0 z-[80] flex items-center justify-center bg-slate-950/65 p-3 sm:p-6"
                                    role="dialog"
                                    aria-modal="true"
                                    aria-label="Edit reply handling"
                                >
                                    <div x-on:click.outside="closeReply()" class="max-h-[calc(100dvh-1.5rem)] w-full max-w-3xl overflow-y-auto rounded-3xl bg-white p-4 shadow-2xl sm:max-h-[92vh] sm:p-7">
                                        <div class="flex items-start justify-between gap-4">
                                            <div>
                                                <p class="text-xs font-extrabold uppercase tracking-[0.14em] text-indigo-700">Reply handling</p>
                                                <h3 class="mt-1 text-xl font-black text-slate-950">{{ $replyHandling['label'] }}</h3>
                                                <p class="mt-2 text-sm leading-6 text-slate-600">Edit the words this message recognizes. Existing replies are not reclassified.</p>
                                            </div>
                                            <button type="button" x-on:click="closeReply()" class="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-full border border-slate-300 bg-white text-xl text-slate-700" aria-label="Close reply editor">×</button>
                                        </div>

                                        <form method="POST" action="{{ $replyHandling['update_action'] }}" data-message-reply-editor-form class="mt-6 space-y-5">
                                            @csrf
                                            @method('PATCH')
                                            <input type="hidden" name="key" value="{{ $replyHandling['key'] }}">
                                            <input type="hidden" name="label" value="{{ $replyHandling['label'] }}">
                                            <input type="hidden" name="description" value="{{ $replyHandling['description'] ?? '' }}">
                                            <input type="hidden" name="reply_editor_profile_key" value="{{ $replyProfileKey }}">

                                            @foreach($formContext as $contextKey => $contextValue)
                                                @if(is_scalar($contextValue) || $contextValue === null)
                                                    <input type="hidden" name="{{ $contextKey }}" value="{{ $contextValue }}">
                                                @endif
                                            @endforeach

                                            @foreach(($replyHandling['intents'] ?? []) as $intentIndex => $intent)
                                                <section class="rounded-2xl border border-slate-200 bg-slate-50 p-4">
                                                    <input type="hidden" name="intents[{{ $intentIndex }}][key]" value="{{ $intent['key'] }}">
                                                    <input type="hidden" name="intents[{{ $intentIndex }}][label]" value="{{ $intent['label'] }}">
                                                    <input type="hidden" name="intents[{{ $intentIndex }}][description]" value="{{ $intent['description'] ?? '' }}">
                                                    <input type="hidden" name="intents[{{ $intentIndex }}][is_active]" value="{{ ($intent['active'] ?? false) ? '1' : '0' }}">

                                                    <h4 class="font-black text-slate-950">{{ $intent['label'] }}</h4>
                                                    @if(filled($intent['description'] ?? null))
                                                        <p class="mt-1 text-sm leading-5 text-slate-600">{{ $intent['description'] }}</p>
                                                    @endif

                                                    <div class="mt-4 grid gap-4 sm:grid-cols-2">
                                                        <div>
                                                            <label class="text-sm font-extrabold text-slate-800">Exact replies</label>
                                                            <textarea name="intents[{{ $intentIndex }}][exact]" rows="5" class="mt-1 block w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm leading-6 text-slate-900">{{ $failedReplyProfileKey === $replyProfileKey ? old('intents.'.$intentIndex.'.exact', $intent['exact_text'] ?? '') : ($intent['exact_text'] ?? '') }}</textarea>
                                                        </div>
                                                        <div>
                                                            <label class="text-sm font-extrabold text-slate-800">Keywords or phrases</label>
                                                            <textarea name="intents[{{ $intentIndex }}][keywords]" rows="5" class="mt-1 block w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm leading-6 text-slate-900">{{ $failedReplyProfileKey === $replyProfileKey ? old('intents.'.$intentIndex.'.keywords', $intent['keywords_text'] ?? '') : ($intent['keywords_text'] ?? '') }}</textarea>
                                                        </div>
                                                    </div>
                                                </section>
                                            @endforeach

                                            <div class="flex flex-col-reverse gap-2 border-t border-slate-200 pt-5 sm:flex-row sm:items-center sm:justify-between">
                                                @if($replyHandlingIndexUrl !== '')
                                                    <a href="{{ $replyHandlingIndexUrl }}?profile={{ urlencode($replyProfileKey) }}" class="inline-flex min-h-11 items-center justify-center rounded-full border border-slate-300 bg-white px-5 text-sm font-extrabold text-slate-700 hover:bg-slate-50">
                                                        Open full Reply Handling
                                                    </a>
                                                @endif
                                                <div class="flex flex-col-reverse gap-2 sm:flex-row">
                                                    <button type="button" x-on:click="closeReply()" class="inline-flex min-h-11 items-center justify-center rounded-full border border-slate-300 bg-white px-5 text-sm font-extrabold text-slate-700">Cancel</button>
                                                    <button type="submit" class="inline-flex min-h-11 items-center justify-center rounded-full bg-indigo-950 px-6 text-sm font-extrabold text-white hover:bg-indigo-900">Save reply handling</button>
                                                </div>
                                            </div>
                                        </form>
                                    </div>
                                </div>
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