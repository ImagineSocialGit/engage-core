# Portal Authentication

Portal authentication is the external/customer identity system for Engage Core.

It is separate from internal CRM authentication.

## Identity boundary

The two account systems remain independent:

```text
App\Models\User
    internal team / CRM identity
    auth guard: web

App\Modules\Portal\Models\PortalUser
    external/customer identity
    auth guard: portal
```

The same human may legitimately have records in both systems, including the same email address. Their passwords, sessions, reset tokens, status, and lifecycle remain separate.

A Contact is still not an authentication record:

```text
PortalUser
    -> portal_contact_links
        -> Contact
```

Portal account creation never infers or silently claims a Contact by matching email. A Contact link must be supplied explicitly or established through an accepted Portal invitation.

## Portal login identity

Email is the Portal login identity.

New Portal mutations canonicalize email by trimming whitespace and lowercasing it. The authentication migration canonicalizes existing non-empty Portal emails and changes the existing email index to a unique index.

Nullable legacy/invitation-shell email values remain possible at the database level for compatibility, but a Portal account cannot authenticate until it has:

```text
status = active
a valid email
a password
```

The Portal authentication provider fails closed for `invited`, `suspended`, `disabled`, deleted, passwordless, or missing-email accounts.

## Module-owned authentication configuration

Portal contributes its authentication configuration only while the Portal module provider is loaded:

```text
guard: portal
provider: portal_users
password broker: portal_users
password reset table: portal_password_reset_tokens
```

The internal `web` guard and its defaults are not changed.

Portal has a dedicated password-reset table because internal users and Portal users may use the same email address without sharing reset-token lifecycle.

## Public action seams

The authentication foundation exposes transport-neutral actions:

```text
CreatePortalUserAction
LinkPortalUserToContactAction
CreatePortalInvitationAction
MarkPortalInvitationSentAction
AcceptPortalInvitationAction
AuthenticatePortalUserAction
PortalAuthContext
```

Controllers and future module integrations should use these actions instead of writing Portal account/link/invitation records directly.

### Direct account creation

`CreatePortalUserAction` creates an active Portal account with a hashed password.

It may link a supplied Contact explicitly. It never looks up a Contact by email.

Direct registration does not mark email verified by default. Email verification belongs to the customer-facing auth flow added on top of this contract.

### Invitations

`CreatePortalInvitationAction` creates the Portal-owned invitation lifecycle record and returns the one-time raw token to its caller.

Only the hash is stored.

The invitation begins as `pending`. Delivery remains outside this action. A later Messaging integration can deliver the raw token and then invoke `MarkPortalInvitationSentAction`.

Only `sent` invitations are acceptable.

Acceptance:

```text
validates the one-time token
rejects expired/revoked/already-used invitations
creates or activates the invited Portal account
sets the password
marks email verified when an email-delivered invitation proves that exact email
marks phone verified when an SMS-delivered invitation proves that exact phone
links the explicitly referenced Contact
marks the invitation accepted
```

An invitation is not a marketing-consent record.

## Authentication context

`PortalAuthContext` exposes only the authenticated Portal user and their active linked Contact IDs.

Verticals and optional modules can later consume that generic identity context without importing CRM `User` behavior into customer surfaces.

## Deferred to the customer-facing shell batch

This foundation intentionally does not add browser routes or views.

A later Core Portal batch owns:

```text
registration page
login/logout routes
forgot/reset password screens
email verification screens
account dashboard/profile shell
portal route/navigation/dashboard extension registries
CSRF/session UX and redirect behavior
```

Pet-specific customer surfaces remain in the PetServices package.

Billing/payment surfaces remain in Billing.

Scheduling booking remains in Scheduling.