import Alpine from 'alpinejs'

import flowRouteEditor from './pages/flow-route-editor'
import webinarRegistrationPage from './pages/webinar-registration'

window.Alpine = Alpine

Alpine.data('flowRouteEditor', flowRouteEditor)
Alpine.data('webinarRegistrationPage', webinarRegistrationPage)

Alpine.start()
