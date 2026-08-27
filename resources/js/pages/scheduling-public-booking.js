const EVENT_VERSION = 1
const SURFACE = 'scheduling_public_booking'

function config() {
    const element = document.getElementById('scheduling-public-booking-config')

    if (!element) {
        return null
    }

    try {
        return JSON.parse(element.textContent || '{}')
    } catch {
        return null
    }
}

function record(page, eventKey, properties = {}, options = {}) {
    const client = window.EngageReporting

    if (!page?.reportingEnabled || !client || typeof client.record !== 'function') {
        return Promise.resolve({ status: 'disabled', eventId: options.eventId ?? null })
    }

    const commonProperties = {
        page_revision: page.pageRevision,
        state: page.state,
    }

    if (page.serviceKey) {
        commonProperties.service_key = page.serviceKey
    }

    return client.record({
        eventId: options.eventId ?? null,
        eventKey,
        eventVersion: EVENT_VERSION,
        surface: SURFACE,
        properties: { ...commonProperties, ...properties },
        keepalive: Boolean(options.keepalive),
    })
}

function createEventId() {
    const client = window.EngageReporting

    return client && typeof client.createEventId === 'function'
        ? client.createEventId()
        : null
}

function formatUsPhone(value) {
    let digits = String(value || '').replace(/\D+/g, '')

    if (digits.length > 10 && digits.startsWith('1')) {
        digits = digits.slice(1, 11)
    } else {
        digits = digits.slice(0, 10)
    }

    if (digits.length < 4) {
        return digits
    }

    if (digits.length < 7) {
        return `(${digits.slice(0, 3)}) ${digits.slice(3)}`
    }

    return `(${digits.slice(0, 3)}) ${digits.slice(3, 6)}-${digits.slice(6)}`
}

function enhancePhoneInputs() {
    document.querySelectorAll('[data-phone-mask]').forEach((input) => {
        const render = () => {
            input.value = formatUsPhone(input.value)
        }

        input.addEventListener('input', render)
        render()
    })
}

function enhanceVerificationChannel(page) {
    document.querySelectorAll('[data-verification-form]').forEach((form) => {
        const channel = form.querySelector('[name="channel"]')
        const destination = form.querySelector('[name="destination"]')
        const label = form.querySelector('[data-verification-input-label]')

        if (!channel || !destination || !label) {
            return
        }

        const render = () => {
            const sms = channel.value === 'sms'
            label.textContent = sms ? 'Mobile phone number' : 'Email address'
            destination.type = sms ? 'tel' : 'email'
            destination.inputMode = sms ? 'tel' : 'email'
            destination.autocomplete = sms ? 'tel' : 'email'
            destination.placeholder = sms ? '(555) 555-0123' : 'you@example.com'
            destination.toggleAttribute('data-phone-mask', sms)

            if (sms) {
                destination.value = formatUsPhone(destination.value)
            }
        }

        channel.addEventListener('change', render)
        destination.addEventListener('input', () => {
            if (channel.value === 'sms') {
                destination.value = formatUsPhone(destination.value)
            }
        })
        form.addEventListener('submit', () => {
            record(page, 'scheduling.booking.verification_requested', {
                channel: channel.value,
            }, { keepalive: true })
        })
        render()
    })
}

function enhanceTimeOptions(page) {
    document.querySelectorAll('[data-time-option]').forEach((option) => {
        option.addEventListener('toggle', () => {
            if (!option.open) {
                return
            }

            document.querySelectorAll('[data-time-option][open]').forEach((other) => {
                if (other !== option) {
                    other.open = false
                }
            })

            record(page, 'scheduling.booking.time_selected', {
                day_period: option.dataset.dayPeriod || 'morning',
            })
        })
    })
}

function enhanceReporting(page) {
    record(page, 'scheduling.booking.page_view')

    if (page.availabilityState) {
        record(page, 'scheduling.booking.availability_viewed', {
            availability_state: page.availabilityState,
        })
    }

    if (page.verificationCompletedChannel) {
        record(page, 'scheduling.booking.verification_completed', {
            channel: page.verificationCompletedChannel,
        })
    }

    if (Array.isArray(page.validationFields) && page.validationFields.length > 0) {
        record(page, 'scheduling.booking.validation_failed', {
            field_keys: page.validationFields,
        })
    }

    document.querySelectorAll('[data-report-service-selected]').forEach((link) => {
        link.addEventListener('click', () => {
            record(page, 'scheduling.booking.service_selected', {
                service_key: link.dataset.serviceKey || 'unknown',
            }, { keepalive: true })
        })
    })

    document.querySelectorAll('[data-booking-details-form]').forEach((form) => {
        let started = false

        form.addEventListener('input', () => {
            if (!started) {
                started = true
                record(page, 'scheduling.booking.details_started')
            }
        })

        form.addEventListener('submit', () => {
            const eventId = createEventId()
            const attempt = form.querySelector('[name="public_submission_attempt_id"]')

            if (attempt) {
                attempt.value = eventId || ''
            }

            record(page, 'scheduling.booking.submit_attempt', {}, {
                eventId,
                keepalive: true,
            })
        })
    })
}

export default function initializeSchedulingPublicBooking() {
    const page = config()

    if (!page) {
        return
    }

    enhancePhoneInputs()
    enhanceVerificationChannel(page)
    enhanceTimeOptions(page)
    enhanceReporting(page)
}