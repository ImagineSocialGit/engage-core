# Portal customer surface

Portal's standard customer-facing host is `portal.<ROOT_DOMAIN>`.

The runtime key is `PORTAL_APP_URL`. When it is omitted, Engage Core derives the Portal origin from the APP_URL scheme/port and ROOT_DOMAIN. Deployments may override the host without changing Portal routes.

Portal routes are registered on that host and are still protected by `module:portal`; installing Portal schema does not make the surface visible unless the module is enabled for the selected client.

The first customer shell supports invitation-driven activation, login/logout, an authenticated home surface, and basic name/phone profile editing. Login email is deliberately not editable yet because changing authentication identity requires a verified change-of-email lifecycle.

Portal account state and domain access remain distinct. An authenticated PortalUser does not automatically own a Core Contact just because emails match. Existing-customer access should be established by an explicit Portal invitation/link or another future verified claim flow.

The shared Portal shell exposes three extension seams:

- `PortalDashboardPanelProvider` for module-owned dashboard panels;
- `PortalNavigationProvider` for authenticated customer navigation;
- `PortalRouteContributor` for authenticated module-owned Portal routes.

Contributors are collected through the registry tags declared by Portal's registries. Providers may mark navigation or dashboard definitions as requiring a verified email. PetServices, Scheduling, Documents, Billing, Commerce, and other modules should contribute their own views/routes instead of adding their domain behavior to Portal.

Self-registration, email-verification delivery, password-reset request delivery, and Portal invitation delivery remain deferred to the Portal/Messaging integration. Portal owns those account lifecycle records/tokens; Messaging owns actual email/SMS delivery. Core Portal must not send directly through Resend or Telnyx.

Portal pages default to `noindex,nofollow`. Client-wide public branding comes from `public_surfaces.php`; sparse Portal-specific presentation overrides live in `config/portal.php` and may later be overridden per client through the ordinary client config merge.