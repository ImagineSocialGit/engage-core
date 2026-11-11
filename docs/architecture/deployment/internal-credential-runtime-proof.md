# Internal credential runtime proof

The console-only `engage:credential-runtime:inspect` command supports two modes:

- `--inventory --expected-client=KEY --expected-environment=ENV` lists all
  registered enabled-package bindings. It never calls a credential consumer.
- `PURPOSE --source-platform=PLATFORM --caller-id=CALLER --challenge=NONCE
  --expected-client=KEY --expected-environment=ENV` returns a challenge-bound
  HMAC using the same credential resolver as the owning capability's runtime.

Owners implement `CoreCredentialRuntimeContributor` and register their class
under its `TAG` only while their module is enabled. Each binding includes its
owner, purpose, peer platform, caller identity, source origin, required
client-scoped secret key, and deferred runtime resolver. Core validates the
resolved deployment requirement, ownership, target scope, duplicate identities,
shared keys, and origin syntax. Inventory is deterministic and includes every
binding supplied by registered contributors; an empty list is not proof that
an unsupported integration needs no credentials.

Proof requires a valid 64-character generated credential in client scope, no
same-name root assignment, and equality with the package's actual resolver.
Consumer errors and command exceptions return fixed diagnostics, never secret
material or underlying exception messages. Commands are automatically available
through Core's service provider and expose no network endpoint.

## Wire protocol

The controller generates a fresh, unpredictable 32-byte challenge, encoded as
64 lowercase hexadecimal characters, for each verification attempt. The
canonical UTF-8 message consists of these fields separated by one LF, with no
trailing LF:

1. `engage-internal-runtime-v1`
2. Challenge
3. Responding platform (`core` or `seo`)
4. Responding selected-client key
5. Environment (`local`, `staging`, or `production`)
6. Package-owned purpose
7. Peer platform (`seo` for Core, `core` for SEO)
8. Caller identity
9. Source site origin
10. Core destination webhook origin

The response's `proof` is lowercase hexadecimal HMAC-SHA256, keyed by the
runtime credential. The challenge is public; a response must never be accepted
by status alone. The controller independently checks every identity field and
computes the expected MAC with its existing protected credential record using
constant-time comparison. Use separate challenge attempts and verify each
platform's context. Never log proofs, private runtime snapshots, or keys.

## Verification boundary

A valid proof establishes credential use in a newly booted console runtime.
It does not establish PHP-FPM or long-running worker configuration, web-server
routing, DNS ownership, authenticated HTTP traffic, or successful checkout.
`credential_equality_verified` remains false in the platform report because
only the controller can compare the peer reports with protected material.
`network_verified` and `writes_allowed` always remain false.

This operation does not rebuild configuration caches, edit environments,
generate or rotate keys, write database records, or call payment providers.
If a cached runtime disagrees with persisted configuration, verification must
fail and the normal deployment workflow must refresh that cache explicitly.