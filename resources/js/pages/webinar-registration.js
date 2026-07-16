export default function webinarRegistrationPage(config = {}) {
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

        init() {
            this.initializeCountdown()
            this.initializeStickyCta()

            this.$watch('formOpen', (isOpen) => {
                this.handleRegistrationModalState(isOpen)
            })

            if (this.formOpen) {
                this.$nextTick(() => {
                    this.handleRegistrationModalState(true)
                })
            }
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
