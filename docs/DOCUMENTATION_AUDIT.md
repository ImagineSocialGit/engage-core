# Engage Core Documentation Audit — Broadcasts, Messaging Consent, and Permission Invitations

This audit covers the documentation files currently in the uploaded source/reference set and the changes required by the latest implementation work.

## Files that should be updated

### `README.md`

Needs updates for:

- `transactional:permission_invitation` purpose/scope.
- `broadcast_send` and `imported_contact_permission_invitation` dispatch keys.
- `emails` queue.
- Core-owned `imported` contact filter shape.
- One-time imported-contact opt-in invitation rules.
- SMS UI toggleability.
- Pre-rollout migration replacement preference.

### `TOKEN_REFERENCE.md`

Needs updates for:

- Permission invitation runtime tokens.
- Dot-notation token form such as `{permission_invitation.url}`.
- CTA/secondary-link behavior for invitation emails.
- Reminder that URLs are generated at runtime and should not be guessed in client copy.

### `config-authoring-guide.md`

Needs updates for:

- Permission invitation config ownership.
- SMS UI toggleability.
- Permission invitation purpose/scope and dispatch key guidance.
- Review checklist items for permission invitations and SMS exposure.

### `client-request-intake-template.md`

Needs updates for:

- Permission invitation config template inclusion.
- Guidance that imported-contact opt-in invitations are distinct from normal Broadcasts.
- SMS visibility should be config-toggleable.
- New dispatch key and purpose/scope.

### `module-boundaries.md`

Needs updates for:

- `contact_permission_invitations` table ownership under Messaging.
- Messaging ownership of public consent/preference invitation routes and records.
- Core-owned contact filter resolver status.
- `imported` recipient filter shape.
- Broadcast opt-in invitation boundary: Broadcasts can initiate, Messaging owns enforcement.
- SMS code-present/UI-hidden rule.
- Pre-rollout migration replacement rule.

## New documentation that should be added

### `permission-invitations.md`

A dedicated, shorter implementation/operations guide should exist because the feature crosses Broadcasts, Messaging, Core contact filters, public pages, and client config.

It should explain:

- What the feature is.
- What it is not.
- Runtime flow.
- Required DB records.
- Config shape.
- Consent behavior.
- SMS opt-in behavior.
- Testing expectations.

## Files that do not require a content update right now

### `core-project-tree.txt`

This file should eventually be regenerated from the actual project tree, not hand-edited. It is stale after the new public controller, permission invitation model/service/request/views/tests, Core contact filter concern, and migration changes.

Recommended command from the project root:

```bash
find . -path './vendor' -prune -o -path './node_modules' -prune -o -print | sort
```

or whichever tree-generation command you used previously.

### Template PHP files

The uploaded PHP config templates do not all need immediate edits for this documentation pass. The one new template/config that matters is `config/messaging/permission_invitations.php`, which now acts as the shape reference for client overrides.
