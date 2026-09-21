@props([
    'presentation' => [],
    'fieldPrefix' => '',
    'namePrefixBind' => null,
    'visibleBind' => null,
    'selectedAssetUuid' => '',
    'selectedPosterAssetUuid' => '',
    'selectedTitle' => '',
    'selectedSize' => 'full',
    'assetModel' => null,
    'posterModel' => null,
    'titleModel' => null,
    'sizeModel' => null,
])

@if(($presentation['available'] ?? false) === true)
    <section
        {{ $attributes->class(['rounded-2xl border border-violet-200 bg-violet-50/70 p-4 sm:p-5']) }}
        @if(filled($visibleBind)) x-show="{{ $visibleBind }}" x-cloak @endif
        data-message-media-authoring
        data-media-video-processing="0"
    >
        <input
            type="hidden"
            @if(filled($namePrefixBind))
                x-bind:name="{{ $namePrefixBind }} + '[media_present]'"
            @else
                name="{{ $fieldPrefix !== '' ? $fieldPrefix.'[media_present]' : 'media_present' }}"
            @endif
            value="1"
        >

        <div class="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
            <div>
                <h4 class="text-sm font-black text-slate-950">Media</h4>
                <p class="mt-1 text-xs leading-5 text-slate-600">
                    Choose a reusable asset or upload a new one. Put <code>{media}</code> in the email body to choose its exact position; otherwise it appears after the body copy.
                </p>
            </div>

            @if(filled($presentation['library_url'] ?? null))
                <a
                    href="{{ $presentation['library_url'] }}"
                    target="_blank"
                    rel="noopener noreferrer"
                    class="shrink-0 text-xs font-extrabold text-violet-800 underline decoration-violet-300 underline-offset-4"
                >
                    Open Media Library
                </a>
            @endif
        </div>

        <div class="mt-4 grid gap-4 lg:grid-cols-2">
            <div>
                <label class="mb-1.5 block text-xs font-extrabold uppercase tracking-wide text-slate-600">Choose existing</label>
                <select
                    @if(filled($namePrefixBind))
                        x-bind:name="{{ $namePrefixBind }} + '[media_asset_uuid]'"
                    @else
                        name="{{ $fieldPrefix !== '' ? $fieldPrefix.'[media_asset_uuid]' : 'media_asset_uuid' }}"
                    @endif
                    @if(filled($assetModel)) x-model="{{ $assetModel }}" @endif
                    data-message-media-asset-select
                    class="block w-full rounded-xl border border-slate-300 bg-white px-3 py-2.5 text-sm text-slate-900"
                >
                    <option value="">No media</option>
                    @foreach(($presentation['assets'] ?? []) as $asset)
                        <option
                            value="{{ $asset['uuid'] }}"
                            @selected(! filled($assetModel) && (string) $selectedAssetUuid === (string) $asset['uuid'])
                        >
                            {{ ucfirst((string) ($asset['kind'] ?? 'media')) }} — {{ $asset['title'] ?? $asset['uuid'] }}{{ ($asset['archived'] ?? false) ? ' (current archived asset)' : '' }}
                        </option>
                    @endforeach
                </select>
                @error($fieldPrefix !== '' ? $fieldPrefix.'.media_asset_uuid' : 'media_asset_uuid')
                    <p class="mt-2 text-sm font-semibold text-red-600">{{ $message }}</p>
                @enderror
            </div>

            <div>
                <label class="mb-1.5 block text-xs font-extrabold uppercase tracking-wide text-slate-600">Or upload new</label>
                <input
                    type="file"
                    @if(filled($namePrefixBind))
                        x-bind:name="{{ $namePrefixBind }} + '[media_upload]'"
                    @else
                        name="{{ $fieldPrefix !== '' ? $fieldPrefix.'[media_upload]' : 'media_upload' }}"
                    @endif
                    data-message-media-upload
                    @if(filled($presentation['authoring_upload_url'] ?? null))
                        data-media-authoring-upload-url="{{ $presentation['authoring_upload_url'] }}"
                    @endif
                    class="block w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm text-slate-700 file:mr-3 file:rounded-full file:border-0 file:bg-slate-900 file:px-3 file:py-1.5 file:text-xs file:font-bold file:text-white"
                >
                <p class="mt-2 text-xs leading-5 text-slate-500">
                    Video uploads begin Media ingestion immediately here. Keep this editor open until processing finishes; the ready video is selected automatically.
                </p>
                <input
                    @if(filled($namePrefixBind))
                        x-bind:name="{{ $namePrefixBind }} + '[media_title]'"
                    @else
                        name="{{ $fieldPrefix !== '' ? $fieldPrefix.'[media_title]' : 'media_title' }}"
                    @endif
                    value="{{ $selectedTitle }}"
                    @if(filled($titleModel)) x-model="{{ $titleModel }}" @endif
                    data-message-media-title
                    placeholder="Optional title for new upload"
                    class="mt-2 block w-full rounded-xl border border-slate-300 bg-white px-3 py-2.5 text-sm text-slate-900"
                >
                <p class="mt-2 text-xs leading-5 text-slate-500">A new upload takes precedence over the existing-asset selection.</p>
                <p
                    class="mt-2 hidden rounded-xl border border-violet-200 bg-white px-3 py-2 text-xs font-semibold text-slate-700"
                    data-message-media-upload-status
                    role="status"
                    aria-live="polite"
                ></p>
                @error($fieldPrefix !== '' ? $fieldPrefix.'.media_upload' : 'media_upload')
                    <p class="mt-2 text-sm font-semibold text-red-600">{{ $message }}</p>
                @enderror
            </div>
        </div>

        <div class="mt-4">
            <label class="mb-1.5 block text-xs font-extrabold uppercase tracking-wide text-slate-600">
                Video poster image <span class="normal-case font-semibold text-slate-400">(optional)</span>
            </label>
            <select
                @if(filled($namePrefixBind))
                    x-bind:name="{{ $namePrefixBind }} + '[media_poster_asset_uuid]'"
                @else
                    name="{{ $fieldPrefix !== '' ? $fieldPrefix.'[media_poster_asset_uuid]' : 'media_poster_asset_uuid' }}"
                @endif
                @if(filled($posterModel)) x-model="{{ $posterModel }}" @endif
                class="block w-full rounded-xl border border-slate-300 bg-white px-3 py-2.5 text-sm text-slate-900"
            >
                <option value="">Generate a poster from the video</option>
                @foreach(($presentation['image_assets'] ?? []) as $asset)
                    <option
                        value="{{ $asset['uuid'] }}"
                        @selected(! filled($posterModel) && (string) $selectedPosterAssetUuid === (string) $asset['uuid'])
                    >
                        {{ $asset['title'] ?? $asset['uuid'] }}{{ ($asset['archived'] ?? false) ? ' (current archived poster)' : '' }}
                    </option>
                @endforeach
            </select>
            <p class="mt-2 text-xs leading-5 text-slate-500">
                Poster images apply only to video. Without one, a poster is generated during Media ingestion. The email card links to the hosted video player.
            </p>
            @error($fieldPrefix !== '' ? $fieldPrefix.'.media_poster_asset_uuid' : 'media_poster_asset_uuid')
                <p class="mt-2 text-sm font-semibold text-red-600">{{ $message }}</p>
            @enderror
        </div>

        <div class="mt-4">
            <label class="mb-1.5 block text-xs font-extrabold uppercase tracking-wide text-slate-600">Media display size</label>
            <select
                @if(filled($namePrefixBind))
                    x-bind:name="{{ $namePrefixBind }} + '[media_size]'"
                @else
                    name="{{ $fieldPrefix !== '' ? $fieldPrefix.'[media_size]' : 'media_size' }}"
                @endif
                @if(filled($sizeModel)) x-model="{{ $sizeModel }}" @endif
                class="block w-full rounded-xl border border-slate-300 bg-white px-3 py-2.5 text-sm text-slate-900"
            >
                @foreach(['small' => 'Small', 'medium' => 'Medium', 'large' => 'Large', 'full' => 'Full width'] as $size => $label)
                    <option value="{{ $size }}" @selected(! filled($sizeModel) && $selectedSize === $size)>{{ $label }}</option>
                @endforeach
            </select>
            <p class="mt-2 text-xs leading-5 text-slate-500">Applies to images and video cards in email. Other files keep their regular link.</p>
            @error($fieldPrefix !== '' ? $fieldPrefix.'.media_size' : 'media_size')
                <p class="mt-2 text-sm font-semibold text-red-600">{{ $message }}</p>
            @enderror
        </div>
    </section>

    @once
        <script>
            (() => {
                const states = new WeakMap();

                const mediaSection = (element) => element.closest('[data-message-media-authoring]');

                const isVideo = (file) => {
                    if (typeof file?.type === 'string' && file.type.toLowerCase().startsWith('video/')) {
                        return true;
                    }

                    return typeof file?.name === 'string'
                        && /\.(mp4|mov|m4v|webm)$/i.test(file.name);
                };

                const statusElement = (section) => section?.querySelector('[data-message-media-upload-status]');

                const setStatus = (section, message) => {
                    const element = statusElement(section);

                    if (! element) {
                        return;
                    }

                    if (! message) {
                        element.textContent = '';
                        element.classList.add('hidden');
                        return;
                    }

                    element.textContent = message;
                    element.classList.remove('hidden');
                };

                const setProcessing = (section, processing) => {
                    if (section) {
                        section.dataset.mediaVideoProcessing = processing ? '1' : '0';
                    }
                };

                const invalidate = (section) => {
                    const current = states.get(section);

                    if (current?.controller instanceof AbortController) {
                        current.controller.abort();
                    }

                    states.delete(section);
                    setProcessing(section, false);
                };

                const errorMessage = (payload, fallback) => {
                    if (typeof payload?.message === 'string' && payload.message.trim() !== '') {
                        return payload.message.trim();
                    }

                    if (payload?.errors && typeof payload.errors === 'object') {
                        for (const messages of Object.values(payload.errors)) {
                            if (Array.isArray(messages) && typeof messages[0] === 'string') {
                                return messages[0];
                            }
                        }
                    }

                    return fallback;
                };

                const selectReadyAsset = (section, payload) => {
                    const select = section.querySelector('[data-message-media-asset-select]');
                    const uuid = typeof payload?.asset_uuid === 'string' ? payload.asset_uuid : '';

                    if (! select || uuid === '') {
                        throw new Error('The processed Media asset could not be selected.');
                    }

                    let option = Array.from(select.options).find((candidate) => candidate.value === uuid);

                    if (! option) {
                        const kind = typeof payload?.kind === 'string' && payload.kind !== ''
                            ? payload.kind.charAt(0).toUpperCase() + payload.kind.slice(1)
                            : 'Media';
                        const title = typeof payload?.title === 'string' && payload.title !== ''
                            ? payload.title
                            : uuid;

                        option = new Option(`${kind} — ${title}`, uuid);
                        select.add(option);
                    }

                    select.value = uuid;
                    select.dispatchEvent(new Event('input', { bubbles: true }));
                    select.dispatchEvent(new Event('change', { bubbles: true }));
                };

                const wait = (milliseconds) => new Promise((resolve) => window.setTimeout(resolve, milliseconds));

                const pollUntilReady = async (section, state, statusUrl) => {
                    while (states.get(section) === state) {
                        await wait(2500);

                        if (states.get(section) !== state) {
                            return;
                        }

                        const response = await fetch(statusUrl, {
                            method: 'GET',
                            credentials: 'same-origin',
                            headers: {
                                Accept: 'application/json',
                            },
                            signal: state.controller.signal,
                        });
                        const payload = await response.json().catch(() => ({}));

                        if (! response.ok) {
                            throw new Error(errorMessage(payload, 'Video processing status could not be checked.'));
                        }

                        if (payload.failed === true || payload.ingestion_status === 'failed') {
                            throw new Error(errorMessage(payload, payload.error || 'Video processing failed.'));
                        }

                        if (payload.ready === true || payload.ingestion_status === 'ready') {
                            selectReadyAsset(section, payload);
                            states.delete(section);
                            setProcessing(section, false);
                            setStatus(section, 'Video ready and selected.');
                            return;
                        }

                        setStatus(section, 'Preparing a web-safe video and poster…');
                    }
                };

                document.addEventListener('change', async (event) => {
                    const upload = event.target.closest?.('[data-message-media-upload]');

                    if (upload) {
                        const section = mediaSection(upload);
                        const file = upload.files?.[0];

                        if (! section || ! file) {
                            return;
                        }

                        invalidate(section);

                        if (! isVideo(file)) {
                            setStatus(section, null);
                            return;
                        }

                        const uploadUrl = upload.dataset.mediaAuthoringUploadUrl || '';

                        if (uploadUrl === '') {
                            setStatus(section, 'Video processing is unavailable on this authoring surface.');
                            return;
                        }

                        const controller = new AbortController();
                        const state = { controller };
                        states.set(section, state);
                        setProcessing(section, true);
                        setStatus(section, 'Uploading video to Media…');
                        upload.disabled = true;

                        try {
                            const formData = new FormData();
                            formData.append('file', file, file.name);

                            const title = section.querySelector('[data-message-media-title]')?.value;
                            if (typeof title === 'string' && title.trim() !== '') {
                                formData.append('title', title.trim());
                            }

                            const parentForm = section.closest('form');
                            const csrfToken = parentForm?.querySelector('input[name="_token"]')?.value
                                || document.querySelector('meta[name="csrf-token"]')?.content
                                || '';

                            const response = await fetch(uploadUrl, {
                                method: 'POST',
                                credentials: 'same-origin',
                                headers: {
                                    Accept: 'application/json',
                                    ...(csrfToken !== '' ? { 'X-CSRF-TOKEN': csrfToken } : {}),
                                },
                                body: formData,
                                signal: controller.signal,
                            });
                            const payload = await response.json().catch(() => ({}));

                            if (! response.ok) {
                                throw new Error(errorMessage(payload, 'The video could not be uploaded to Media.'));
                            }

                            if (states.get(section) !== state) {
                                return;
                            }

                            upload.value = '';
                            upload.disabled = false;

                            if (payload.failed === true || payload.ingestion_status === 'failed') {
                                throw new Error(errorMessage(payload, payload.error || 'Video processing failed.'));
                            }

                            if (payload.ready === true || payload.ingestion_status === 'ready') {
                                selectReadyAsset(section, payload);
                                states.delete(section);
                                setProcessing(section, false);
                                setStatus(section, 'Video ready and selected.');
                                return;
                            }

                            if (typeof payload.status_url !== 'string' || payload.status_url === '') {
                                throw new Error('Media did not provide a video processing status URL.');
                            }

                            setStatus(section, 'Preparing a web-safe video and poster…');
                            await pollUntilReady(section, state, payload.status_url);
                        } catch (error) {
                            if (error?.name === 'AbortError') {
                                return;
                            }

                            if (states.get(section) === state) {
                                states.delete(section);
                                setProcessing(section, false);
                                upload.disabled = false;
                                setStatus(
                                    section,
                                    error instanceof Error && error.message !== ''
                                        ? error.message
                                        : 'Video processing failed.',
                                );
                            }
                        }

                        return;
                    }

                    const select = event.target.closest?.('[data-message-media-asset-select]');

                    if (select && event.isTrusted) {
                        const section = mediaSection(select);

                        if (section) {
                            invalidate(section);
                            setStatus(section, null);
                        }
                    }
                });

                document.addEventListener('submit', (event) => {
                    const form = event.target;

                    if (! (form instanceof HTMLFormElement)) {
                        return;
                    }

                    const processing = form.querySelector(
                        '[data-message-media-authoring][data-media-video-processing="1"]',
                    );

                    if (! processing) {
                        return;
                    }

                    event.preventDefault();
                    event.stopImmediatePropagation();
                    setStatus(processing, 'Wait for video processing to finish before saving or scheduling this message.');
                    processing.scrollIntoView({ behavior: 'smooth', block: 'center' });
                }, true);
            })();
        </script>
    @endonce
@endif