
import Alpine from 'alpinejs'

import flowRouteEditor from './pages/flow-route-editor'
import initializeMediaLibrary from './pages/media-library'
import initializeSchedulingPublicBooking from './pages/scheduling-public-booking'
import webinarRegistrationPage from './pages/webinar-registration'
import { createReportingClient } from './reporting/client'

window.Alpine = Alpine
window.EngageReporting = createReportingClient()

Alpine.data('flowRouteEditor', flowRouteEditor)
Alpine.data('webinarRegistrationPage', webinarRegistrationPage)

Alpine.start()

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initializeSchedulingPublicBooking)
    document.addEventListener('DOMContentLoaded', initializeMediaLibrary)
} else {
    initializeSchedulingPublicBooking()
    initializeMediaLibrary()
}