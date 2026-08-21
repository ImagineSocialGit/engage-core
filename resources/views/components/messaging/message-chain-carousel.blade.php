@props([
    'presentation' => [],
    'editable' => false,
    'emptyMessage' => 'No messages are configured for this sequence.',
])

@php
    $channels = is_array($presentation['channels'] ?? null)
        ? $presentation['channels']
        : [];
    $channelKeys = array_keys($channels);
    $initialChannel = $channelKeys[0] ?? null;
    $indexes = collect($channelKeys)
        ->mapWithKeys(fn (string $key): array => [$key => 0])
        ->all();
@endphp

@if($initialChannel === null)
    <div
        data-message-chain-carousel-empty
        class="rounded-2xl border border-dashed border-slate-300 bg-slate-50 px-5 py-8 text-center"
    >
        <p class="font-bold text-slate-900">{{ $emptyMessage }}</p>
    </div>
@else
    <div
        data-message-chain-carousel
        x-data="{
            channel: @js($initialChannel),
            indexes: @js($indexes),
        }"
        class="space-y-4"
    >
        <div class="flex flex-wrap gap-2" aria-label="Message channels">
            @foreach($channels as $channelKey => $channel)
                <button
                    type="button"
                    data-message-chain-channel="{{ $channelKey }}"
                    x-on:click="channel = @js($channelKey)"
                    x-bind:class="channel === @js($channelKey)
                        ? 'bg-slate-950 text-white ring-slate-950'
                        : 'bg-white text-slate-700 ring-slate-200 hover:bg-slate-50'"
                    class="inline-flex min-h-10 items-center justify-center rounded-full px-4 text-sm font-extrabold ring-1 transition"
                >
                    {{ $channel['label'] }}
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

        @foreach($channels as $channelKey => $channel)
            @php
                $messages = is_array($channel['messages'] ?? null)
                    ? $channel['messages']
                    : [];
                $messageCount = count($messages);
            @endphp

            <div
                x-show="channel === @js($channelKey)"
                x-cloak
                data-message-chain-channel-panel="{{ $channelKey }}"
                class="space-y-4"
            >
                @foreach($messages as $messageIndex => $message)
                    @php
                        $payload = is_array($message['payload'] ?? null)
                            ? $message['payload']
                            : [];
                        $cta = is_array($payload['cta'] ?? null)
                            ? $payload['cta']
                            : null;
                        $secondaryLink = is_array($payload['secondary_link'] ?? null)
                            ? $payload['secondary_link']
                            : null;
                        $ctas = is_array($payload['ctas'] ?? null)
                            ? array_values(array_filter(
                                $payload['ctas'],
                                fn (mixed $item): bool => is_array($item),
                            ))
                            : [];
                        $canEditInline = $editable
                            && is_string($message['update_action'] ?? null)
                            && trim($message['update_action']) !== '';
                    @endphp

                    <article
                        x-show="indexes[@js($channelKey)] === {{ $messageIndex }}"
                        x-cloak
                        data-message-chain-message
                        data-message-chain-message-index="{{ $messageIndex }}"
                        class="overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-sm"
                    >
                        <header class="border-b border-slate-200 bg-slate-50 px-4 py-4 sm:px-6">
                            <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                                <div>
                                    @if(filled($message['area_label'] ?? null))
                                        <p class="text-xs font-extrabold uppercase tracking-[0.14em] text-slate-500">
                                            {{ $message['area_label'] }}
                                        </p>
                                    @endif

                                    <h3 class="mt-1 text-lg font-black tracking-tight text-slate-950">
                                        {{ $message['step_name'] ?? $message['template_name'] ?? 'Message' }}
                                    </h3>

                                    <p class="mt-1 text-sm font-semibold text-slate-600">
                                        {{ $message['timing'] ?? 'Configured timing' }}
                                    </p>
                                </div>

                                <div class="flex flex-wrap items-center gap-2">
                                    <span class="rounded-full bg-white px-2.5 py-1 text-xs font-extrabold text-slate-700 ring-1 ring-slate-200">
                                        {{ $message['channel_label'] ?? strtoupper((string) $channelKey) }}
                                    </span>

                                    @if(filled($message['message_type_label'] ?? null))
                                        <span class="rounded-full bg-white px-2.5 py-1 text-xs font-bold text-slate-500 ring-1 ring-slate-200">
                                            {{ $message['message_type_label'] }}
                                        </span>
                                    @endif
                                </div>
                            </div>
                        </header>

                        <div class="grid gap-0 lg:grid-cols-[minmax(0,1.1fr)_minmax(20rem,0.9fr)]">
                            <div class="border-b border-slate-200 p-4 sm:p-6 lg:border-b-0 lg:border-r">
                                <div class="mb-4 flex flex-wrap items-center justify-between gap-2">
                                    <p class="text-xs font-extrabold uppercase tracking-[0.14em] text-slate-500">
                                        Published copy preview
                                    </p>

                                    @if(is_string($message['template_edit_url'] ?? null) && trim($message['template_edit_url']) !== '')
                                        <a
                                            data-message-template-edit-link
                                            href="{{ $message['template_edit_url'] }}"
                                            class="text-xs font-extrabold text-slate-700 underline decoration-slate-300 underline-offset-4 hover:text-slate-950"
                                        >
                                            Open in Message Templates
                                        </a>
                                    @endif
                                </div>

                                @if($channelKey === 'email')
                                    <div class="mx-auto max-w-2xl overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                                        <div class="border-b border-slate-200 bg-slate-50 px-4 py-3">
                                            <p class="text-xs font-bold uppercase tracking-wide text-slate-500">Subject</p>
                                            <p class="mt-1 text-sm font-black text-slate-950">
                                                {{ $payload['subject'] ?? 'No subject' }}
                                            </p>
                                        </div>

                                        <div class="space-y-5 px-4 py-5 sm:px-6 sm:py-6">
                                            <div class="whitespace-pre-line text-sm leading-6 text-slate-800">{{ $payload['body'] ?? '' }}</div>

                                            @if($cta && filled($cta['label'] ?? null))
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

                                            @if($secondaryLink && filled($secondaryLink['label'] ?? null))
                                                <div class="text-sm">
                                                    <span class="font-bold text-slate-700 underline underline-offset-4">
                                                        {{ $secondaryLink['label'] }}
                                                    </span>
                                                    @if(filled($secondaryLink['url'] ?? null))
                                                        <p class="mt-1 break-all text-xs text-slate-500">{{ $secondaryLink['url'] }}</p>
                                                    @endif
                                                </div>
                                            @endif

                                            @if(array_key_exists('footer', $payload) && filled($payload['footer']))
                                                <div class="border-t border-slate-200 pt-4 text-xs leading-5 text-slate-500 whitespace-pre-line">{{ $payload['footer'] }}</div>
                                            @endif
                                        </div>
                                    </div>
                                @else
                                    <div class="mx-auto max-w-md rounded-[2rem] border border-slate-300 bg-slate-100 p-4 shadow-inner sm:p-5">
                                        <div class="rounded-2xl bg-white p-4 shadow-sm">
                                            <p class="whitespace-pre-line text-sm leading-6 text-slate-900">{{ $payload['message'] ?? '' }}</p>
                                        </div>
                                    </div>
                                @endif

                                <p class="mt-4 text-xs leading-5 text-slate-500">
                                    Personal and event tokens stay visible here. They resolve for the actual recipient when the message is prepared for delivery.
                                </p>
                            </div>

                            <div class="p-4 sm:p-6">
                                @if($canEditInline)
                                    <p class="text-xs font-extrabold uppercase tracking-[0.14em] text-slate-500">
                                        Edit this message
                                    </p>

                                    <form
                                        method="POST"
                                        action="{{ $message['update_action'] }}"
                                        data-message-chain-edit-form
                                        class="mt-4 space-y-4"
                                    >
                                        @csrf
                                        @method('PATCH')

                                        @if($channelKey === 'email')
                                            <div>
                                                <label class="block text-sm font-bold text-slate-900">Subject</label>
                                                <input
                                                    type="text"
                                                    name="payload[subject]"
                                                    value="{{ $payload['subject'] ?? '' }}"
                                                    maxlength="255"
                                                    required
                                                    class="mt-1 block w-full rounded-xl border border-slate-300 bg-white px-4 py-3 text-sm text-slate-900 shadow-sm focus:border-slate-500 focus:outline-none focus:ring-0"
                                                >
                                            </div>

                                            <div>
                                                <label class="block text-sm font-bold text-slate-900">Body</label>
                                                <textarea
                                                    name="payload[body]"
                                                    rows="10"
                                                    maxlength="10000"
                                                    required
                                                    class="mt-1 block w-full rounded-xl border border-slate-300 bg-white px-4 py-3 text-sm leading-6 text-slate-900 shadow-sm focus:border-slate-500 focus:outline-none focus:ring-0"
                                                >{{ $payload['body'] ?? '' }}</textarea>
                                            </div>
                                        @else
                                            <div>
                                                <label class="block text-sm font-bold text-slate-900">SMS message</label>
                                                <textarea
                                                    name="payload[message]"
                                                    rows="7"
                                                    maxlength="1600"
                                                    required
                                                    class="mt-1 block w-full rounded-xl border border-slate-300 bg-white px-4 py-3 text-sm leading-6 text-slate-900 shadow-sm focus:border-slate-500 focus:outline-none focus:ring-0"
                                                >{{ $payload['message'] ?? '' }}</textarea>
                                            </div>
                                        @endif

                                        @if(array_key_exists('footer', $payload))
                                            <div>
                                                <label class="block text-sm font-bold text-slate-900">Footer</label>
                                                <textarea
                                                    name="payload[footer]"
                                                    rows="3"
                                                    maxlength="2000"
                                                    class="mt-1 block w-full rounded-xl border border-slate-300 bg-white px-4 py-3 text-sm leading-6 text-slate-900 shadow-sm focus:border-slate-500 focus:outline-none focus:ring-0"
                                                >{{ $payload['footer'] ?? '' }}</textarea>
                                            </div>
                                        @endif

                                        @foreach(['cta' => 'Primary link', 'secondary_link' => 'Secondary link'] as $linkKey => $linkLabel)
                                            @if(is_array($payload[$linkKey] ?? null))
                                                <div class="grid gap-3 sm:grid-cols-2">
                                                    <div>
                                                        <label class="block text-sm font-bold text-slate-900">
                                                            {{ $linkLabel }} label
                                                        </label>
                                                        <input
                                                            type="text"
                                                            name="payload[{{ $linkKey }}][label]"
                                                            value="{{ $payload[$linkKey]['label'] ?? '' }}"
                                                            maxlength="255"
                                                            class="mt-1 block w-full rounded-xl border border-slate-300 bg-white px-4 py-3 text-sm text-slate-900 shadow-sm focus:border-slate-500 focus:outline-none focus:ring-0"
                                                        >
                                                    </div>

                                                    <div>
                                                        <label class="block text-sm font-bold text-slate-900">
                                                            {{ $linkLabel }} URL
                                                        </label>
                                                        <input
                                                            type="text"
                                                            name="payload[{{ $linkKey }}][url]"
                                                            value="{{ $payload[$linkKey]['url'] ?? '' }}"
                                                            maxlength="1000"
                                                            class="mt-1 block w-full rounded-xl border border-slate-300 bg-white px-4 py-3 text-sm text-slate-900 shadow-sm focus:border-slate-500 focus:outline-none focus:ring-0"
                                                        >
                                                    </div>
                                                </div>
                                            @endif
                                        @endforeach

                                        <button
                                            type="submit"
                                            class="inline-flex min-h-10 w-full items-center justify-center rounded-full bg-slate-950 px-4 text-center text-sm font-extrabold text-white transition hover:bg-slate-800 sm:w-auto"
                                        >
                                            Publish updated copy
                                        </button>
                                    </form>
                                @else
                                    <div class="rounded-2xl border border-slate-200 bg-slate-50 p-5">
                                        <p class="font-black text-slate-950">Review mode</p>
                                        <p class="mt-2 text-sm leading-6 text-slate-600">
                                            This view shows the current published copy. Use the available edit link when you want to change the shared template, or create series-specific messages before changing only this webinar series.
                                        </p>
                                    </div>
                                @endif
                            </div>
                        </div>

                        <footer class="flex flex-col gap-3 border-t border-slate-200 bg-slate-50 px-4 py-4 sm:flex-row sm:items-center sm:justify-between sm:px-6">
                            <div class="text-sm font-bold text-slate-700">
                                Message {{ $messageIndex + 1 }} of {{ $messageCount }}
                            </div>

                            @if($messageCount > 1)
                                <div class="flex items-center gap-2">
                                    <button
                                        type="button"
                                        aria-label="Previous message"
                                        x-on:click="indexes[@js($channelKey)] = indexes[@js($channelKey)] <= 0
                                            ? {{ $messageCount - 1 }}
                                            : indexes[@js($channelKey)] - 1"
                                        class="inline-flex min-h-10 items-center justify-center rounded-full border border-slate-300 bg-white px-4 text-sm font-extrabold text-slate-700 hover:bg-slate-100"
                                    >
                                        ← Previous
                                    </button>
                                    <button
                                        type="button"
                                        aria-label="Next message"
                                        x-on:click="indexes[@js($channelKey)] = indexes[@js($channelKey)] >= {{ $messageCount - 1 }}
                                            ? 0
                                            : indexes[@js($channelKey)] + 1"
                                        class="inline-flex min-h-10 items-center justify-center rounded-full bg-slate-950 px-4 text-sm font-extrabold text-white hover:bg-slate-800"
                                    >
                                        Next →
                                    </button>
                                </div>
                            @endif
                        </footer>
                    </article>
                @endforeach
            </div>
        @endforeach
    </div>
@endif