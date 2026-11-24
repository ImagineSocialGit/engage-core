# External runtime configuration — application bootstrap (EC-02A)

**Platform:** core. **Status:** opt-in root bootstrap only; no live migration authorized.

Infrastructure supplies `ENGAGE_RUNTIME_CONFIG_DIR` as an absolute path to a
private, immutable configuration generation under
`/srv/engage/instances/<instance>/shared/runtime/config/<revision>`, and
`APP_CONFIG_CACHE` as an independent cache path under the same instance's
`shared/runtime/cache/<cache-revision>/config.php` tree.

If `ENGAGE_RUNTIME_CONFIG_DIR` is absent, Laravel's existing local `.env`
bootstrap behavior is preserved unchanged. If it is present, the application
checks the real directory, a private `root.env` regular file and the private
cache location **before** binding Laravel's environment directory and file.
Missing configuration, symlinks, hard links, insecure permissions or a cache
outside the same instance fail closed. Secrets are never printed.

This opt-in is intentionally incomplete: Core/SEO selected-client `.env`
loaders and credential readers have **not yet** been migrated to `client.env`.
Do not set `ENGAGE_RUNTIME_CONFIG_DIR` for real staging or production services
until EC-02B and the Infrastructure remote installer have been implemented,
independently tested, and explicitly approved. Artist has no selected-client
scope; it must still wait for Infrastructure provisioners and process/cache
verification before enabling the mode.

Run the focused tests on the development checkout:

```bash
php artisan test tests/Unit/Runtime/ExternalRootEnvironmentTest.php
```

## Selected-client external configuration (EC-02B)

For Core and SEO only, when `ENGAGE_RUNTIME_CONFIG_DIR` is explicitly set,
the selected-client loader reads `client.env` in **the same protected generation**
as `root.env`, independently of source-release `.env` files. A missing, symlinked,
hardlinked, world-accessible or otherwise invalid external `client.env` is a
fatal configuration error. It must not fall back to `client/<CLIENT_KEY>/.env`.
The selected client's source directory and PHP package definitions still come
from the selected release; only secrets/environment settings are external.

Core's deployment environment inspection and SEO's credential runtime/setup
inspection use the same external root/client paths. Core's application-side
environment synchronizer refuses all writes in external mode: only an approved
Infrastructure writer may create a new immutable generation. With external mode
unset, the existing development and deployed paths are unchanged.

**Do not enable external mode on staging or production yet.** The approved
Infrastructure writer, isolated cache generation, independent remote proofs,
service bindings, rotation and cutover operations are still to be completed.
The EC-02B tests verify consumer behavior, not live deployment readiness.
