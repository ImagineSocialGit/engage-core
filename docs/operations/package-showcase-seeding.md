# Package showcase seeders

Showcase records are development fixtures, not installation presets, migrations, real payment events, or production reference data. They are version-controlled with each package and **never run automatically** by client bootstrap, `engage:install`, `modules:install`, `composer install`, or deployment.

## Contract for optional packages

An operator-facing package may publish a `showcase_seeder` entry in its module definition:

```php
'showcase_seeder' => \Vendor\Package\Database\Seeders\PackageShowcaseSeeder::class,
```

The class must extend `Illuminate\Database\Seeder`, must immediately reject any environment other than `local`/`testing` before performing database work, and must require the relevant enabled and migrated modules. It should create synthetic, clearly identified records, be safe to rerun, and never call payment providers, mail transports, webhooks, or queues. Prefer a stable marker and an explicit database transaction; do not truncate or reset existing data.

Run the generic aggregator for **enabled package modules only**:

```bash
php artisan db:seed --class=PackageShowcaseSeeder --no-ansi
```

The generic aggregator reads enabled module definitions at runtime. It imports no package classes. Each package may also document a direct seeder command for focused UI review.

The existing platform `SurfaceShowcaseSeeder` remains separate. To invoke all enabled package seeders from it in a future coordinated showcase run, it can call `PackageShowcaseSeeder::class` through the same Laravel seeding contract; it must not import individual packages.

## Safety

- Both the aggregator and each package seeder reject staging/production, including when manually invoked with `--force`.
- Review the active `CLIENT_KEY`, database, and `APP_ENV` before executing any showcase seeder.
- Showcase payments are synthetic local ledger records; they are not provider-verified settlements, may not be exported as financial records, and must never be copied to staging or production.
- Running `migrate` or ordinary deployment must not create showcase rows.
- Keep seeders and tests tracked in Git; do not add them to `.gitignore`. Distribution exclusion is optional and separate from source control.