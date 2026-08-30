# Messaging public host

Messaging owns public consent-revocation and email-preference routes on:

```text
messaging.[ROOT_DOMAIN]
```

New marketing unsubscribe and transactional opt-out links are generated on this host. Webinar registration cancellation, join, playback, registration, and thank-you routes remain on `webinar.[ROOT_DOMAIN]`.

The legacy unsubscribe and transactional opt-out paths remain registered on the Webinar host so already-delivered signed links can finish safely. Those legacy GET requests generate their confirmation POST on the canonical Messaging host. Do not remove the legacy aliases until every previously generated signed link has exceeded the longest configured expiration period.

For each deployment environment:

- create DNS for `messaging.[ROOT_DOMAIN]`;
- include the hostname in the TLS certificate;
- route the hostname to Engage Core's `public/` directory using the same PHP-FPM application pool as the other Core public hosts;
- deploy the code before sending new messages;
- clear cached routes/configuration and restart long-running queue workers after deployment;
- confirm `php artisan route:list --name=messaging.email` shows canonical routes on the Messaging host and legacy aliases on the Webinar host.

No new environment key is required. The host is derived from the selected client's existing `ROOT_DOMAIN` contract.