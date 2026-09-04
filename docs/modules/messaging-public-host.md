# Messaging public host

Messaging owns public message-interaction, consent-revocation, and email-preference routes on:

```text
messaging.[ROOT_DOMAIN]
```

Canonical public Messaging URLs include:

```text
CTA engagement redirects
marketing unsubscribe links
transactional opt-out links
```

New tracked CTA links are generated on the Messaging host. The canonical tracking path is:

```text
https://messaging.[ROOT_DOMAIN]/messaging/click/{scheduled_message}/{tracking_key}
```

The historical CRM-host CTA path remains registered as a legacy alias:

```text
https://crm.[ROOT_DOMAIN]/messaging/click/{scheduled_message}/{tracking_key}
```

Do not use the legacy alias for newly generated email. It exists only so signed links in already-delivered messages keep redirecting safely.

New marketing unsubscribe and transactional opt-out links are also generated on the Messaging host. Webinar registration cancellation, join, playback, registration, and thank-you routes remain on `webinar.[ROOT_DOMAIN]`.

Marketing email uses the canonical signed Messaging unsubscribe URL in the message body and in the `List-Unsubscribe` header. Contact-backed marketing email also emits:

```text
List-Unsubscribe-Post: List-Unsubscribe=One-Click
```

Mailbox providers may POST `List-Unsubscribe=One-Click` directly to that signed unsubscribe URL. The unsubscribe path is therefore intentionally exempt from CSRF validation; the temporary URL signature remains mandatory and is the authorization boundary for the public action. Browser GET requests continue to show the confirmation screen before a human unsubscribe submission.

Transactional email does not emit marketing `List-Unsubscribe` / `List-Unsubscribe-Post` headers.

The legacy unsubscribe and transactional opt-out paths remain registered on the Webinar host so already-delivered signed links can finish safely. Those legacy GET requests generate their confirmation POST on the canonical Messaging host. Do not remove the legacy aliases until every previously generated signed link has exceeded the longest configured expiration period.

For each deployment environment:

- create DNS for `messaging.[ROOT_DOMAIN]`;
- include the hostname in the TLS certificate;
- route the hostname to Engage Core's `public/` directory using the same PHP-FPM application pool as the other Core public hosts;
- deploy the code before sending new messages;
- clear cached routes/configuration and restart long-running queue workers after deployment;
- confirm `php artisan route:list --name=messaging` shows canonical CTA/unsubscribe routes on the Messaging host and the intended legacy aliases on their historical hosts;
- send a real marketing test and inspect the raw message to confirm both list-unsubscribe headers are present and the HTTPS URL uses `messaging.[ROOT_DOMAIN]`.

No new environment key is required. The host is derived from the selected client's existing `ROOT_DOMAIN` contract.