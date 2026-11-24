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
