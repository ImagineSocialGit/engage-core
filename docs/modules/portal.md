# Portal Module

Portal is a current universal module.

Portal owns reusable external/customer account access that can be used by multiple verticals without pushing portal account state into Core contacts or reusing internal app users as customer accounts.

## Client-facing expectation

Portal should follow the Engage Core product barometer:

```text
A customer/client-facing portal action should be obvious and quick.
A business-client-facing portal setup workflow should not ask the client to design a portal from scratch.
```

Portal should provide the account/access shell that lets customers do simple useful things:

```text
Book an appointment.
Submit an existing form.
Upload a requested document.
View relevant account/order/appointment information.
Update basic profile or preference information.
```

The developer/operator should own complex setup work:

```text
Configure portal surfaces.
Decide which modules contribute portal panels/routes.
Define customer access rules.
Wire portal invitations and notifications.
Create vertical-specific portal experiences.
```

Portal should not become a blank-canvas website/client-portal builder that every client has to learn.

## Responsibility

Portal should answer:

```text
Who can access the external/customer portal, which Core contacts are they linked to, what are they allowed to access, and what is the lifecycle state of that external account access?
```

Portal should stay vertical-neutral.

It may support customer self-booking, intake form submission, document uploads, order/account visibility, account invitations, customer dashboards, and self-service profile or preference access without owning the domain meaning of those records.

## FOSS feature-shape assumptions

Before proposing schema, Portal was evaluated against common patterns in mature open-source and open-source-adjacent customer portal, helpdesk, ERP, self-service, file-sharing, and client-portal systems.

Those systems commonly support:

```text
- external/customer account identities
- account invitation and activation flows
- links between external accounts and customer/contact records
- login/authentication separate from internal users
- customer dashboard or self-service shell
- role/permission/access grants
- order, invoice, ticket, appointment, project, or document visibility
- file/document upload and sharing
- customer-submitted requests or forms
- account/profile/preferences management
- notifications for invitations and account events
- extension points for module-specific portal surfaces
```

Engage Core should use those products as feature-shape references, not as implementation sources.

The durable conclusion is that Portal should have a roomy, generic foundation for external identity, contact links, invitations, and access grants, while consuming other Engage Core modules for scheduling, forms, documents, commerce, messaging, and vertical-specific portal behavior.

## Owns

Portal owns:

```text
portal_users
portal_contact_links
portal_invitations
portal_access_grants
```

Portal should also own, when implemented:

```text
external/customer account lifecycle
portal authentication guard/configuration
invitation token lifecycle
customer-facing access permissions
generic portal dashboard shell
portal extension-point registries
portal-facing route/surface contribution contracts
```

Portal should not own Core contacts, internal app users, appointment records, form definitions, document lifecycle records, commerce orders, or vertical-specific customer profile fields.

## Does not own

Portal does not own:

```text
internal team users
Core Contact records
Messaging consent records
message delivery infrastructure
appointment scheduling rules
appointment lifecycle state
form definitions/submissions
document requests/uploads/reviews
commerce products/orders/payments
task assignment/lifecycle
vertical-specific customer profiles
pet/dog profiles
music purchase/fan strategy
mortgage application or LOS state
```

## Consumes

Portal may consume these modules through public seams when enabled:

```text
Core
Messaging
Scheduling
Forms
Documents
Commerce
Tasks
InternalNotifications
Location
Integrations/adapters
```

Expected usage:

```text
Core -> contact-linked portal accounts
Messaging -> portal invitations and account notifications
Scheduling -> customer self-booking and schedule visibility
Forms -> customer-submitted intake forms or questionnaires
Documents -> customer uploads and document request visibility
Commerce -> order/account/payment visibility
Tasks -> customer-facing manual requirements only if deliberately exposed
InternalNotifications -> team alerts about portal activity
Location -> service-area or location-aware portal behavior
Integrations -> external identity/provider adapters if added later
```

For the first foundation slice, Portal should depend only on Core. Messaging should remain an optional later integration for invitation delivery through public Messaging services.

## Consumed by

Portal may be consumed by:

```text
Scheduling
Forms
Documents
Commerce
PetServices
Music
Mortgage
Reporting
```

Consumers should contribute portal-facing functionality through Portal extension points rather than directly modifying Portal internals.

Expected future examples:

```text
Scheduling contributes customer booking screens.
Forms contributes customer form submission screens.
Documents contributes customer upload/request screens.
Commerce contributes customer order/account screens.
PetServices contributes pet-service-specific customer dashboard panels.
Music contributes music/customer account panels.
Mortgage contributes mortgage-specific document or application visibility.
```

## Public seams to add later

The first foundation slice does not need full actions yet.

Likely future public seams:

```text
CreatePortalUserAction
LinkPortalUserToContactAction
CreatePortalInvitationAction
AcceptPortalInvitationAction
RevokePortalAccessAction
GrantPortalAccessAction
PortalUserReadService
PortalAccessGate
PortalDashboardPanelProvider
PortalDashboardPanelRegistry
PortalRouteProvider
PortalRouteRegistry
PortalNavigationProvider
PortalNavigationRegistry
```

Public actions and registries should exist before other modules directly create or mutate Portal records.

## Authentication and identity

Portal users are external/customer accounts.

Portal users are not internal app users.

Contacts are not portal accounts.

Core contacts answer:

```text
Who is this person, how can we reach them, and where did they come from?
```

Portal users answer:

```text
Who can log into the external/customer portal?
```

A Portal user may link to one or more Contacts.

A Contact may link to one or more Portal users.

Examples:

```text
One customer portal account manages their own contact record.
A parent/guardian portal account manages a child's pet-service contact.
A spouse or co-buyer has access to the same mortgage-related contact context.
A business customer has multiple authorized portal users linked to one company/contact context.
```

Do not add portal state directly to `contacts`.

Good:

```text
PortalUser -> portal_contact_links -> Contact
```

Bad:

```text
contacts.portal_user_id
contacts.portal_status
users table doubles as customer portal accounts
```

## Invitations and Messaging

Portal should own portal invitation records and account-access lifecycle.

Messaging should own delivery when invitations or account notifications are sent.

Good future direction:

```text
Portal creates PortalInvitation.
Portal calls Messaging public action/service to deliver invitation email/SMS.
Messaging creates ScheduledMessage.
ScheduledMessage recipient = Contact or PortalUser, depending on recipient support.
ScheduledMessage context = PortalInvitation.
```

Bad:

```text
Portal writes directly to scheduled_messages.
Messaging owns portal invitation lifecycle.
Portal reuses imported-contact permission invitation records for account access.
```

Portal invitations are distinct from Messaging imported-contact permission invitations.

Messaging-owned imported-contact permission invitations are about marketing consent preferences.

Portal-owned invitations are about external/customer account access.

Do not combine those lifecycles.

## Extension points

Portal should eventually provide extension points for module-contributed customer-facing surfaces.

Possible extension point categories:

```text
dashboard panels
portal routes
navigation items
access policies/gates
account menu items
activity summaries
```

Module-contributed portal surfaces should keep ownership with the contributing module.

Examples:

```text
Scheduling owns appointment booking and contributes a Portal booking route.
Forms owns form definitions/submissions and contributes a Portal form route.
Documents owns document requests/uploads and contributes a Portal document route.
Commerce owns orders and contributes a Portal order route.
```

Portal should provide the shell, account context, and access checks.

The contributing module should own the domain record and domain-specific behavior.

## Automation events

Portal should use the existing app-level automation event seam when portal outcomes become automation-worthy.

Current seam:

```text
App\Support\AutomationEvents\Data\AutomationEventData
App\Support\AutomationEvents\Events\AutomationEventRecorded
```

Likely future Portal automation events:

```text
portal.user_created
portal.invitation_sent
portal.invitation_accepted
portal.access_granted
portal.access_revoked
portal.user_login
```

Portal should emit automation events after it records its own domain state.

FlowRoutes should listen to `AutomationEventRecorded`, not Portal-specific events.

Good:

```text
Portal records invitation acceptance.
Portal emits AutomationEventRecorded(portal.invitation_accepted).
FlowRoutes reacts through the generic automation event seam.
```

Bad:

```text
Portal imports FlowRoutes.
FlowRoutes adds a Portal-specific listener.
Producer module calls FlowRouteExternalEvent directly.
```

Automation events should be contact-aware, not contact-required.

A portal account event may have:

```text
contact_id nullable
subject_type = PortalInvitation or PortalUser
subject_id = related Portal record
```

## Schema foundation

The first Portal foundation should add these tables:

```text
portal_users
portal_contact_links
portal_invitations
portal_access_grants
```

These tables are intentionally roomy but generic.

They include generic fields such as:

```text
status
source
provider
external_id
meta json
timestamps
soft deletes
```

They avoid vertical-specific columns, UI-specific assumptions, provider-specific assumptions, and domain-record ownership that belongs to other modules.

## Table notes

### portal_users

Represents an external/customer account identity.

Important fields:

```text
uuid
name
email
phone
password
remember_token
status
email_verified_at
phone_verified_at
last_login_at
invited_at
accepted_at
disabled_at
source
provider
external_id
meta
```

Notes:

```text
password is nullable for the foundation slice.
Portal auth screens are deferred.
email should be indexed, but global uniqueness can wait until auth rules are finalized.
status should remain generic: invited, active, suspended, disabled.
```

### portal_contact_links

Represents links between Portal users and Core contacts.

Important fields:

```text
portal_user_id
contact_id
relationship
status
is_primary
linked_at
verified_at
revoked_at
source
meta
```

Notes:

```text
relationship stays generic.
Examples: self, parent, spouse, company_contact, guardian.
A many-to-many link is more durable than forcing one portal user per contact.
```

### portal_invitations

Represents an invitation to create, claim, or access a Portal account.

Important fields:

```text
portal_user_id
contact_id
email
phone
token_hash
status
channel
purpose
expires_at
sent_at
accepted_at
revoked_at
accepted_ip
accepted_user_agent
source
provider
external_id
meta
```

Notes:

```text
Store token_hash, not the raw token.
purpose is Portal-owned account-access purpose, not Messaging purpose/scope.
Examples: account_access, self_booking, document_upload.
Delivery is a future Messaging integration.
```

### portal_access_grants

Represents generic permission for a Portal user to access a portal-facing record or capability.

Important fields:

```text
portal_user_id
contact_id
grantable_type / grantable_id
capability
status
starts_at
expires_at
granted_by_type / granted_by_id
revoked_at
source
meta
```

Examples:

```text
grantable = Appointment, capability = view
grantable = BookableService, capability = book
grantable = FormDefinition, capability = submit
grantable = DocumentRequest, capability = upload
grantable = CommerceOrder, capability = view
```

Notes:

```text
Portal owns the access grant.
The target module owns the grantable record and domain rules.
```

## Deferred work

Deferred until needed:

```text
portal auth guard and login screens
customer-facing dashboard UI
portal route/navigation extension registries
Messaging delivery for Portal invitations
portal invitation acceptance controller
password reset / email verification flow
customer profile/preference screens
Scheduling self-booking integration
Forms customer submission integration
Documents customer upload integration
Commerce order/account visibility integration
Portal-specific policies/gates
Portal notification templates
external identity provider adapters
reporting dashboards
vertical-specific portal panels
```

## Open questions

```text
Should Portal use its own auth guard immediately, or wait until customer-facing screens exist?
Should portal_users.email be globally unique from the first migration, or only indexed until account rules are finalized?
Should portal invitations support Contact-only invitations before a PortalUser exists?
Should portal_access_grants be required for every portal surface, or only restricted/non-default surfaces?
Should Portal account notifications use transactional:portal as a new Messaging scope later?
Should PortalUser become a supported Messaging recipient through Messaging recipient extension points?
```