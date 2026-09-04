function isImage(file) {
    return file instanceof File && String(file.type || '').toLowerCase().startsWith('image/')
}

function element(form, selector) {
    return form.closest('[data-media-upload-workspace]')?.querySelector(selector) || null
}

function setBusy(form, busy) {
    const submit = form.querySelector('[data-media-upload-submit]')

    if (submit instanceof HTMLButtonElement) {
        submit.disabled = busy
        submit.toggleAttribute('aria-busy', busy)
    }
}

function clearReview(form) {
    const review = element(form, '[data-media-similarity-review]')
    const candidates = element(form, '[data-media-similarity-candidates]')

    if (review instanceof HTMLElement) {
        review.classList.add('hidden')
    }

    if (candidates instanceof HTMLElement) {
        candidates.replaceChildren()
    }
}

function candidateCard(candidate, onUseExisting) {
    const card = document.createElement('article')
    card.className = 'flex flex-col gap-3 rounded-xl border border-amber-200 bg-white p-3 sm:flex-row sm:items-center'
    card.dataset.mediaSimilarityCandidate = String(candidate.id || '')

    if (candidate.public_url) {
        const image = document.createElement('img')
        image.src = candidate.public_url
        image.alt = candidate.title || 'Existing Media image'
        image.className = 'h-20 w-28 shrink-0 rounded-lg bg-slate-100 object-cover'
        card.appendChild(image)
    }

    const body = document.createElement('div')
    body.className = 'min-w-0 flex-1'

    const title = document.createElement('div')
    title.className = 'truncate text-sm font-semibold text-slate-950'
    title.textContent = candidate.title || 'Existing Media image'
    body.appendChild(title)

    const detail = document.createElement('div')
    detail.className = 'mt-1 text-xs text-slate-500'
    detail.textContent = `Similarity distance: ${Number(candidate.distance ?? 0)}`
    body.appendChild(detail)

    const actions = document.createElement('div')
    actions.className = 'mt-2 flex flex-wrap gap-3'

    const useExisting = document.createElement('button')
    useExisting.type = 'button'
    useExisting.className = 'inline-flex min-h-9 items-center justify-center rounded-lg bg-slate-950 px-3 py-1.5 text-xs font-semibold text-white hover:bg-slate-800'
    useExisting.textContent = 'Use existing'
    useExisting.addEventListener('click', () => onUseExisting(candidate))
    actions.appendChild(useExisting)

    if (candidate.public_url) {
        const open = document.createElement('a')
        open.href = candidate.public_url
        open.target = '_blank'
        open.rel = 'noopener'
        open.className = 'text-xs font-semibold text-slate-600 underline'
        open.textContent = 'Open asset'
        actions.appendChild(open)
    }

    body.appendChild(actions)
    card.appendChild(body)

    return card
}

function showNearDuplicateReview(form, candidates) {
    const review = element(form, '[data-media-similarity-review]')
    const list = element(form, '[data-media-similarity-candidates]')
    const message = element(form, '[data-media-similarity-message]')
    const localStatus = element(form, '[data-media-upload-local-status]')

    if (!(review instanceof HTMLElement) || !(list instanceof HTMLElement)) {
        form.dataset.mediaSimilarityBypass = '1'
        form.requestSubmit()
        return
    }

    list.replaceChildren()

    for (const candidate of candidates) {
        list.appendChild(candidateCard(candidate, (selected) => {
            form.reset()
            clearReview(form)

            if (localStatus instanceof HTMLElement) {
                localStatus.textContent = `No new upload was created. Reuse “${selected.title || 'the existing asset'}” from Media.`
                localStatus.classList.remove('hidden')
            }
        }))
    }

    if (message instanceof HTMLElement) {
        message.textContent = 'This image looks very similar to existing Media. Reuse an existing asset when it is the same content, or upload anyway when this is an intentional variant.'
    }

    review.classList.remove('hidden')
    review.scrollIntoView({ block: 'nearest', behavior: 'smooth' })
}

async function inspect(form, file) {
    const url = form.dataset.mediaSimilarityPreflightUrl

    if (!url) {
        return null
    }

    const payload = new FormData()
    payload.append('file', file)

    const token = form.querySelector('input[name="_token"]')

    if (token instanceof HTMLInputElement && token.value) {
        payload.append('_token', token.value)
    }

    const response = await fetch(url, {
        method: 'POST',
        body: payload,
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
        },
    })

    if (!response.ok) {
        return null
    }

    return response.json()
}

function initializeForm(form) {
    if (!(form instanceof HTMLFormElement) || form.dataset.mediaSimilarityInitialized === '1') {
        return
    }

    form.dataset.mediaSimilarityInitialized = '1'

    const fileInput = form.querySelector('input[type="file"][name="file"]')
    const uploadAnyway = element(form, '[data-media-upload-anyway]')
    const cancel = element(form, '[data-media-cancel-upload]')

    fileInput?.addEventListener('change', () => {
        form.dataset.mediaSimilarityBypass = '0'
        clearReview(form)

        const localStatus = element(form, '[data-media-upload-local-status]')
        localStatus?.classList.add('hidden')
    })

    uploadAnyway?.addEventListener('click', () => {
        form.dataset.mediaSimilarityBypass = '1'
        form.requestSubmit()
    })

    cancel?.addEventListener('click', () => {
        form.reset()
        form.dataset.mediaSimilarityBypass = '0'
        clearReview(form)
    })

    form.addEventListener('submit', async (event) => {
        if (form.dataset.mediaSimilarityBypass === '1') {
            return
        }

        const file = fileInput instanceof HTMLInputElement
            ? fileInput.files?.[0]
            : null

        if (!isImage(file)) {
            return
        }

        event.preventDefault()
        clearReview(form)
        setBusy(form, true)

        try {
            const result = await inspect(form, file)

            if (result?.status === 'near_duplicate' && Array.isArray(result.candidates) && result.candidates.length > 0) {
                showNearDuplicateReview(form, result.candidates)
                return
            }

            form.dataset.mediaSimilarityBypass = '1'
            form.requestSubmit()
        } catch {
            form.dataset.mediaSimilarityBypass = '1'
            form.requestSubmit()
        } finally {
            setBusy(form, false)
        }
    })
}

export default function initializeMediaLibrary() {
    document.querySelectorAll('[data-media-upload-form]').forEach(initializeForm)
}