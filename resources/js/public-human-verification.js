const ROOT_SELECTOR = '[data-public-human-verification]'
const CONFIG_SELECTOR = '[data-public-human-verification-config]'
const WIDGET_SELECTOR = '[data-public-human-verification-widget]'
const ERROR_SELECTOR = '[data-public-human-verification-error]'
const CANCEL_SELECTOR = '[data-public-human-verification-cancel]'

function parseConfig(element) {
    if (!element) {
        return null
    }

    try {
        return JSON.parse(element.textContent || '{}')
    } catch {
        return null
    }
}

function normalizePath(value) {
    try {
        return new URL(value, window.location.origin).pathname
    } catch {
        return ''
    }
}

function isProtectedForm(form, config) {
    if (!(form instanceof HTMLFormElement)) {
        return false
    }

    if (String(form.method || 'GET').toUpperCase() !== 'POST') {
        return false
    }

    let action

    try {
        action = new URL(form.action || window.location.href, window.location.href)
    } catch {
        return false
    }

    if (action.origin !== window.location.origin) {
        return false
    }

    const excluded = Array.isArray(config?.excludedPathPrefixes)
        ? config.excludedPathPrefixes
        : []

    return !excluded.some((prefix) => {
        const normalized = normalizePath(prefix)

        return normalized !== '' && action.pathname.startsWith(normalized)
    })
}

function hiddenInput(form, name) {
    let input = Array.from(form.elements).find((element) => (
        element instanceof HTMLInputElement
        && element.type === 'hidden'
        && element.name === name
    ))

    if (input) {
        return input
    }

    input = document.createElement('input')
    input.type = 'hidden'
    input.name = name
    form.appendChild(input)

    return input
}

function preserveSubmitter(form, submitter) {
    if (!(submitter instanceof HTMLButtonElement || submitter instanceof HTMLInputElement)) {
        return
    }

    const name = String(submitter.name || '').trim()

    if (!name) {
        return
    }

    const existing = Array.from(form.elements).find((element) => (
        element instanceof HTMLInputElement
        && element.type === 'hidden'
        && element.dataset.publicHumanVerificationSubmitter === 'true'
        && element.name === name
    ))

    const input = existing || document.createElement('input')
    input.type = 'hidden'
    input.name = name
    input.value = submitter.value || ''
    input.dataset.publicHumanVerificationSubmitter = 'true'

    if (!existing) {
        form.appendChild(input)
    }
}

function initializePublicHumanVerification() {
    const root = document.querySelector(ROOT_SELECTOR)
    const config = parseConfig(document.querySelector(CONFIG_SELECTOR))

    if (!root || !config) {
        return
    }

    const widgetContainer = root.querySelector(WIDGET_SELECTOR)
    const errorElement = root.querySelector(ERROR_SELECTOR)
    const cancelButton = root.querySelector(CANCEL_SELECTOR)
    const widget = config.widget || null
    const bootstrap = window.__publicHumanVerificationBootstrap || null
    const nativeSubmit = bootstrap?.nativeSubmit || HTMLFormElement.prototype.submit
    let pendingForm = null
    let pendingSubmitter = null
    let widgetId = null
    let scriptPromise = null

    const showError = (message) => {
        if (!errorElement) {
            return
        }

        errorElement.textContent = message
        errorElement.classList.remove('hidden')
    }

    const clearError = () => {
        if (!errorElement) {
            return
        }

        errorElement.textContent = ''
        errorElement.classList.add('hidden')
    }

    const open = () => {
        clearError()
        root.classList.remove('hidden')
        root.classList.add('flex')
        root.setAttribute('aria-hidden', 'false')
    }

    const close = () => {
        root.classList.add('hidden')
        root.classList.remove('flex')
        root.setAttribute('aria-hidden', 'true')
        pendingForm = null
        pendingSubmitter = null
    }

    const loadScript = () => {
        if (window.turnstile && typeof window.turnstile.render === 'function') {
            return Promise.resolve()
        }

        if (scriptPromise) {
            return scriptPromise
        }

        scriptPromise = new Promise((resolve, reject) => {
            const script = document.createElement('script')
            script.src = widget?.script_url || ''
            script.async = true
            script.defer = true
            script.addEventListener('load', resolve, { once: true })
            script.addEventListener('error', reject, { once: true })
            document.head.appendChild(script)
        })

        return scriptPromise
    }

    const submitVerifiedForm = (token) => {
        const form = pendingForm

        if (!(form instanceof HTMLFormElement)) {
            return
        }

        hiddenInput(form, config.responseField || 'cf-turnstile-response').value = token
        preserveSubmitter(form, pendingSubmitter)
        nativeSubmit.call(form)
    }

    const renderWidget = async () => {
        if (!config.available || !widget || !widgetContainer) {
            showError('The security check is temporarily unavailable. Please try again shortly.')
            return
        }

        try {
            await loadScript()
        } catch {
            showError('The security check could not load. Please try again shortly.')
            return
        }

        if (!window.turnstile || typeof window.turnstile.render !== 'function') {
            showError('The security check could not load. Please try again shortly.')
            return
        }

        if (widgetId !== null) {
            window.turnstile.reset(widgetId)
            return
        }

        widgetId = window.turnstile.render(widgetContainer, {
            sitekey: widget.site_key,
            action: widget.action,
            theme: 'auto',
            callback: (token) => {
                if (!token) {
                    showError('Please complete the security check before continuing.')
                    return
                }

                submitVerifiedForm(token)
            },
            'error-callback': () => {
                showError('The security check could not be completed. Please try again.')
                return true
            },
            'expired-callback': () => {
                showError('The security check expired. Please try again.')
            },
            'timeout-callback': () => {
                showError('The security check timed out. Please try again.')
            },
        })
    }

    const requestVerification = (form, submitter = null) => {
        pendingForm = form
        pendingSubmitter = submitter
        open()
        renderWidget()
    }

    document.addEventListener('submit', (event) => {
        const form = event.target

        if (!isProtectedForm(form, config)) {
            return
        }

        event.preventDefault()
        requestVerification(form, event.submitter || null)
    })

    // Some established public flows intentionally use form.submit() for
    // automatic continuation. Native submit() does not dispatch a submit event,
    // so route those protected programmatic posts through the same challenge.
    HTMLFormElement.prototype.submit = function publicHumanVerificationSubmit() {
        if (!isProtectedForm(this, config)) {
            nativeSubmit.call(this)
            return
        }

        requestVerification(this)
    }

    if (bootstrap) {
        bootstrap.ready = true

        const pendingForms = Array.isArray(bootstrap.pendingForms)
            ? bootstrap.pendingForms.splice(0)
            : []

        pendingForms.forEach((form) => {
            if (!isProtectedForm(form, config)) {
                nativeSubmit.call(form)
                return
            }

            requestVerification(form)
        })
    }

    cancelButton?.addEventListener('click', close)
    root.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') {
            close()
        }
    })
}

export default initializePublicHumanVerification