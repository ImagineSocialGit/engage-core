# Portal account notification delivery

Portal owns customer-account state, invitation state, verification state, and password-reset intent. Messaging owns outbound delivery and provider execution.

The app-level Portal/Messaging bridge is active only when both modules are enabled. Portal itself continues to depend only on Core, and Messaging continues to depend only on Core.

## Delivery contract

Portal depends on `PortalAccountNotificationTransport`.

Without Messaging, Portal binds the contract to an unavailable implementation. The customer shell remains usable for already-active accounts, but it does not advertise password-reset delivery and cannot schedule account notifications.

With Portal and Messaging enabled, `IntegrationsModuleServiceProvider` binds the contract to `MessagingPortalAccountNotificationTransport` and registers Portal-specific Messaging recipient support.

Current account notifications are transactional Portal messages:

- Portal invitation: email or SMS according to the invitation record;
- email verification: email;
- password reset: email.

They use Messaging's normal resolved-dispatch, planning-gate, scheduled-message, queue, provider, suppression, and terminal-event path. Portal never inserts `scheduled_messages` directly and never calls Resend, Telnyx, or another provider.

## Invitation lifecycle

Creating a Portal invitation still creates only the Portal-owned invitation record and one-time raw token.

`DeliverPortalInvitationAction` explicitly asks the account-notification transport to deliver that issued token. The Messaging bridge schedules a `portal_invitation` transactional message whose recipient and context are the Portal invitation.

The invitation remains `pending` while the message is queued. Only a real `ScheduledMessageSent` terminal event causes `MarkPortalInvitationSentAction` to move it to `sent`. Failed or skipped delivery therefore cannot create an invitation that Portal will accept.

The send-time recipient gate rechecks that the invitation is still pending, unexpired, on the same channel/destination, and not suppressed.

## Secret persistence

Raw Portal invitation tokens and password-reset broker tokens must not be stored in ScheduledMessage payload JSON.

`PortalSecretLinkCodec` encrypts those raw secrets before they are placed into customer-facing URLs. The persisted message contains only the encrypted URL-safe envelope. Portal unwraps that envelope at the customer route and passes the original secret to the existing invitation/password-reset verifier.

Existing raw invitation links remain accepted for backwards compatibility with pre-integration tests/operator tooling. New Messaging-generated invitation links use the encrypted envelope.

Email-verification links use Laravel temporary signed URLs and do not create a separate raw secret.

## Consent and suppression

Portal account access is transactional account servicing, not marketing consent. Portal invitations must not reuse Messaging's imported-contact permission invitation lifecycle.

Because PortalInvitation and PortalUser are non-Contact recipients, the app-level `PortalMessagingRecipientGate` owns their send eligibility. It still honors Messaging destination suppressions, including provider/bounce/complaint safety.

## Deferred

This integration does not add open self-registration, marketing opt-ins, PetServices screens, Billing/payment delivery, or client-specific Portal configuration.