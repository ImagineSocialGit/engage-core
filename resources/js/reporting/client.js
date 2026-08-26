const DEFAULT_ENDPOINT = '/_reporting/observations'
const SESSION_STORAGE_KEY = 'engage.reporting.session.v1'
const DEFAULT_QUERY_KEYS = [
    'utm_source',
    'utm_medium',
    'utm_campaign',
    'utm_content',
    'utm_term',
    'fbclid',
    'engage_platform',
    'engage_campaign_id',
    'engage_group_id',
    'engage_creative_id',
    'engage_placement',
]

function browserUuid() {
    if (typeof crypto === 'undefined') {
        return null
    }

    if (typeof crypto.randomUUID === 'function') {
        return crypto.randomUUID()
    }

    if (typeof crypto.getRandomValues !== 'function') {
        return null
    }

    const bytes = new Uint8Array(16)
    crypto.getRandomValues(bytes)
    bytes[6] = (bytes[6] & 0x0f) | 0x40
    bytes[8] = (bytes[8] & 0x3f) | 0x80

    const hex = Array.from(bytes, (byte) => byte.toString(16).padStart(2, '0')).join('')

    return [
        hex.slice(0, 8),
        hex.slice(8, 12),
        hex.slice(12, 16),
        hex.slice(16, 20),
        hex.slice(20),
    ].join('-')
}

function reportingSessionToken() {
    try {
        const existing = window.sessionStorage.getItem(SESSION_STORAGE_KEY)

        if (existing) {
            return existing
        }

        const token = browserUuid()

        if (!token) {
            return null
        }

        window.sessionStorage.setItem(SESSION_STORAGE_KEY, token)

        return token
    } catch {
        return null
    }
}

function currentReferrerHost() {
    if (!document.referrer) {
        return null
    }

    try {
        return new URL(document.referrer).hostname || null
    } catch {
        return null
    }
}

function currentQuery(queryKeys) {
    const parameters = new URLSearchParams(window.location.search)
    const query = {}

    for (const key of queryKeys) {
        const value = parameters.get(key)

        if (value !== null && value !== '') {
            query[key] = value
        }
    }

    return query
}

export function createReportingClient(options = {}) {
    const endpoint = options.endpoint ?? DEFAULT_ENDPOINT
    const queryKeys = Array.isArray(options.queryKeys)
        ? Array.from(new Set(options.queryKeys.filter((key) => typeof key === 'string' && key !== '')))
        : DEFAULT_QUERY_KEYS

    return {
        createEventId() {
            return browserUuid()
        },

        async record(event = {}) {
            if (event.enabled === false) {
                return {
                    status: 'disabled',
                    eventId: event.eventId ?? null,
                }
            }

            const eventId = event.eventId ?? browserUuid()

            if (!eventId || !event.eventKey || !event.eventVersion || !event.surface) {
                return {
                    status: 'unavailable',
                    eventId: eventId ?? null,
                }
            }

            const payload = {
                event_id: eventId,
                event_key: event.eventKey,
                event_version: event.eventVersion,
                occurred_at: event.occurredAt ?? new Date().toISOString(),
                surface: event.surface,
                path: window.location.pathname,
                properties: event.properties ?? {},
                session_token: event.session === false ? null : reportingSessionToken(),
                referrer_host: currentReferrerHost(),
                query: currentQuery(Array.isArray(event.queryKeys) ? event.queryKeys : queryKeys),
            }

            try {
                const response = await fetch(endpoint, {
                    method: 'POST',
                    headers: {
                        Accept: 'application/json',
                        'Content-Type': 'application/json',
                    },
                    credentials: 'same-origin',
                    keepalive: Boolean(event.keepalive),
                    body: JSON.stringify(payload),
                })

                return {
                    status: response.ok ? 'accepted' : 'rejected',
                    eventId,
                }
            } catch {
                return {
                    status: 'unavailable',
                    eventId,
                }
            }
        },
    }
}