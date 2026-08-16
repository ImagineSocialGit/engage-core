import Alpine from 'alpinejs'

import flowRouteEditor from './pages/flow-route-editor'
import webinarRegistrationPage from './pages/webinar-registration'
import { createReportingClient } from './reporting/client'

window.Alpine = Alpine
window.EngageReporting = createReportingClient()

Alpine.data('flowRouteEditor', flowRouteEditor)
Alpine.data('webinarRegistrationPage', webinarRegistrationPage)

Alpine.start()