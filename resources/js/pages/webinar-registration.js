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

        init() {
            this.initializeCountdown()
            this.initializeStickyCta()
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

        closeModals() {
            this.formOpen = false
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
    }
}
