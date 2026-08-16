const REPORTING_SURFACE = 'webinar_registration'
const REPORTING_EVENT_VERSION = 1

function normalizedString(value, fallback = null) {
    return typeof value === 'string' && value.trim() !== ''
        ? value.trim()
        : fallback
}

function normalizedStringList(values) {
    if (!Array.isArray(values)) {
        return []
    }

    return Array.from(new Set(
        values
            .filter((value) => typeof value === 'string' && value.trim() !== '')
            .map((value) => value.trim()),
    ))
}

export default function webinarRegistrationPage(config = {}) {
    const reporting = typeof config.reporting === 'object' && config.reporting !== null
        ? config.reporting
        : {}

    return {
        formOpen: Boolean(config.formOpen),
        showStickyCta: false,
        transactionalSmsConsent: Boolean(config.transactionalSmsConsent),
        marketingSmsConsent: Boolean(config.marketingSmsConsent),

        countdownTarget: config.countdownTarget ?? null,
        remaining: 0,
        countdownInterval: null,
        stickyObserver: null,

        registrationModalPreviouslyFocusedElement: null,
        registrationModalPreviousBodyOverflow: null,

        reportingEnabled: reporting.enabled !== false,
        reportingPageRevision: normalizedString(reporting.pageRevision, 'webinar-register-v1'),
        reportingPresentation: normalizedString(reporting.presentation, 'modal'),
        reportingValidationFields: normalizedStringList(reporting.validationFields),
        reportingThrottleReason: normalizedString(reporting.throttleReason),
        reportingBotProtectionOutcome: normalizedString(reporting.botProtectionOutcome),
        reportingFormStarted: false,
        reportingLastCtaLocation: null,

        init() {
            this.initializeCountdown()
            this.initializeStickyCta()
            this.recordReportingEvent('webinar.page.view')

            if (this.reportingValidationFields.length > 0) {
                this.recordRegistrationValidationFailure(this.reportingValidationFields)
            }

            if (this.reportingThrottleReason) {
                this.recordReportingEvent('webinar.request.throttled', {
                    reason: this.reportingThrottleReason,
                })
            }

            if (this.reportingBotProtectionOutcome) {
                this.recordBotProtectionResult(this.reportingBotProtectionOutcome)
            }

            this.$watch('formOpen', (isOpen) => {
                this.handleRegistrationModalState(isOpen)

                if (isOpen) {
                    this.recordRegistrationModalOpen(
                        this.reportingLastCtaLocation ?? 'unknown',
                    )
                }
            })

            if (this.formOpen) {
                this.$nextTick(() => {
                    this.handleRegistrationModalState(true)
                    this.recordRegistrationModalOpen('validation_return')
                })
            }
        },

        reportingProperties(properties = {}) {
            return {
                page_revision: this.reportingPageRevision,
                presentation: this.reportingPresentation,
                ...properties,
            }
        },

        recordReportingEvent(eventKey, properties = {}, options = {}) {
            const client = window.EngageReporting

            if (!this.reportingEnabled || !client || typeof client.record !== 'function') {
                return Promise.resolve({
                    status: 'disabled',
                    eventId: options.eventId ?? null,
                })
            }

            return client.record({
                enabled: true,
                eventId: options.eventId ?? null,
                eventKey,
                eventVersion: REPORTING_EVENT_VERSION,
                surface: REPORTING_SURFACE,
                properties: this.reportingProperties(properties),
                keepalive: Boolean(options.keepalive),
            })
        },

        createReportingEventId() {
            if (!this.reportingEnabled) {
                return null
            }

            const client = window.EngageReporting

            return client && typeof client.createEventId === 'function'
                ? client.createEventId()
                : null
        },

        openRegistrationForm(location) {
            this.reportingLastCtaLocation = normalizedString(location, 'unknown')

            this.recordReportingEvent('webinar.cta.click', {
                cta_location: this.reportingLastCtaLocation,
            })

            this.formOpen = true
        },

        recordRegistrationModalOpen(openReason) {
            if (this.reportingPresentation !== 'modal') {
                return
            }

            this.recordReportingEvent('webinar.modal.open', {
                open_reason: normalizedString(openReason, 'unknown'),
            })
        },

        recordRegistrationFormStart() {
            if (this.reportingFormStarted) {
                return
            }

            this.reportingFormStarted = true
            this.recordReportingEvent('webinar.form.start')
        },

        prepareRegistrationSubmitAttempt(botReady, botInteracted) {
            const eventId = this.createReportingEventId()

            this.recordReportingEvent(
                'webinar.form.submit_attempt',
                {
                    bot_ready: Boolean(botReady),
                    bot_interacted: Boolean(botInteracted),
                },
                {
                    eventId,
                    keepalive: true,
                },
            )

            return eventId
        },

        recordRegistrationValidationFailure(fields) {
            const fieldKeys = normalizedStringList(fields)

            if (fieldKeys.length === 0) {
                return
            }

            this.recordReportingEvent('webinar.form.validation_failed', {
                field_keys: fieldKeys,
            })
        },

        recordBotProtectionResult(outcome) {
            const normalizedOutcome = normalizedString(outcome)

            if (!normalizedOutcome) {
                return
            }

            this.recordReportingEvent(
                'webinar.bot_protection.result',
                {
                    outcome: normalizedOutcome,
                },
                {
                    keepalive: true,
                },
            )
        },

        initializeCountdown() {
            if (!this.countdownTarget) {
                return
            }

            this.tickCountdown()

            this.countdownInterval = setInterval(() => {
                this.tickCountdown()
            }, 1000)
        },

        tickCountdown() {
            const target = new Date(this.countdownTarget).getTime()

            if (!Number.isFinite(target)) {
                this.remaining = 0
                return
            }

            this.remaining = Math.max(0, target - Date.now())
        },

        initializeStickyCta() {
            this.stickyObserver = new IntersectionObserver(([entry]) => {
                this.showStickyCta = !entry.isIntersecting
            }, {
                threshold: 0,
            })

            this.$nextTick(() => {
                if (this.$refs.heroSection) {
                    this.stickyObserver.observe(this.$refs.heroSection)
                }
            })
        },

        handleRegistrationModalState(isOpen) {
            if (isOpen) {
                this.registrationModalPreviouslyFocusedElement = document.activeElement instanceof HTMLElement
                    ? document.activeElement
                    : null

                this.registrationModalPreviousBodyOverflow = document.body.style.overflow
                document.body.style.overflow = 'hidden'

                this.$nextTick(() => {
                    this.$refs.registrationModalClose?.focus()
                })

                return
            }

            this.restoreRegistrationModalDocumentState()
        },

        restoreRegistrationModalDocumentState() {
            document.body.style.overflow = this.registrationModalPreviousBodyOverflow ?? ''
            this.registrationModalPreviousBodyOverflow = null

            if (
                this.registrationModalPreviouslyFocusedElement instanceof HTMLElement
                && this.registrationModalPreviouslyFocusedElement.isConnected
            ) {
                this.registrationModalPreviouslyFocusedElement.focus()
            }

            this.registrationModalPreviouslyFocusedElement = null
        },

        registrationModalFocusableElements() {
            const modal = this.$refs.registrationModal

            if (!modal) {
                return []
            }

            const selector = [
                'a[href]',
                'button:not([disabled])',
                'input:not([disabled]):not([type="hidden"])',
                'select:not([disabled])',
                'textarea:not([disabled])',
                '[tabindex]:not([tabindex="-1"])',
            ].join(',')

            return Array.from(modal.querySelectorAll(selector))
                .filter((element) => element instanceof HTMLElement && element.offsetParent !== null)
        },

        trapRegistrationModalFocus(event) {
            if (!this.formOpen || event.key !== 'Tab') {
                return
            }

            const focusableElements = this.registrationModalFocusableElements()

            if (focusableElements.length === 0) {
                event.preventDefault()
                return
            }

            const firstElement = focusableElements[0]
            const lastElement = focusableElements[focusableElements.length - 1]
            const activeElement = document.activeElement
            const activeElementIsInsideModal = this.$refs.registrationModal?.contains(activeElement)

            if (event.shiftKey && (activeElement === firstElement || !activeElementIsInsideModal)) {
                event.preventDefault()
                lastElement.focus()
                return
            }

            if (!event.shiftKey && activeElement === lastElement) {
                event.preventDefault()
                firstElement.focus()
            }
        },

        closeRegistrationModal() {
            this.formOpen = false
        },

        closeModals() {
            this.closeRegistrationModal()
        },

        days() {
            return Math.floor(this.remaining / 86400000)
        },

        hours() {
            return Math.floor((this.remaining % 86400000) / 3600000)
        },

        minutes() {
            return Math.floor((this.remaining % 3600000) / 60000)
        },

        seconds() {
            return Math.floor((this.remaining % 60000) / 1000)
        },

        destroy() {
            if (this.countdownInterval) {
                clearInterval(this.countdownInterval)
            }

            this.stickyObserver?.disconnect()

            if (this.formOpen) {
                this.restoreRegistrationModalDocumentState()
            }
        },
    }
}