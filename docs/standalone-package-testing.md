# Standalone Engage package tests (local and CI)

Run package-owned tests through Core's existing `scripts/test-package.sh`, not
through `CLIENT_KEY=<real client> php artisan test <package test>`. The latter
mixes deliberately isolated test bootstrapping with client-local Composer
configuration and is not a supported package test procedure.

```bash
cd /var/www/engage-core
./scripts/test-package.sh /var/www/engage-donations \
  --platform core --test tests/Feature/DonationCheckoutIntentTest.php \
  -- --no-ansi
./scripts/test-package.sh /var/www/engage-integration-stripe \
  --platform core --test tests/Unit/StripeCheckoutSubmitTypeTest.php \
  -- --no-ansi
```

The command uses the host's PHPUnit configuration, test database and Composer
framework dependencies. It forces `APP_ENV=testing`, clears `CLIENT_KEY`, and
refuses a cached host configuration. It adds source checkout PSR-4 mappings
and optional package-local Composer dependency autoload, without loading a
selected client's vendor or environment. If package dependencies are not
available in the host, install the package's own development dependencies.

For Core packages with an `engage-package.json` descriptor, the test bootstrap
registers the package's declared module definition, source migration scopes,
and integration service provider with `ClientPackageRuntime`. Only modules
owned by the package being tested become enabled in that test process. This is
not client activation: it does not install or enable the package for any client.
Package feature tests that use `RefreshDatabase` must continue to explicitly
name their optional migration keys via `additionalTestMigrationModuleKeys()`.
The host's PHPUnit database must be an isolated, disposable MySQL test database.

The runner does not prove a selected-client Composer installation, generated
client manifest, client module activation, live routes, credentials, or remote
deployment. Validate those separately. For source/package integration problems,
include host-route and middleware tests in the appropriate package suite.

Composer versioning remains owned by the selected client's `composer.json`,
`composer.lock`, and Composer-installed `vendor`. Development symlinks are
local-only; before deploying, install and verify the locked revision in a
nonlinked client tree. Never edit PHP inside a client `vendor` directory.