# Engage Core Production Observability Runbook

## Scope

This batch improves operational traffic diagnosis. It is not Reporting storage and does not depend on Batch 0 webinar refactors.

It adds:

- a safe JSON Nginx access-log format;
- query-free, token-normalized paths;
- host, status, request timing, upstream timing, and request IDs;
- coarse client classification without retaining full user-agent strings;
- Nginx-to-Laravel `X-Request-ID` propagation;
- structured daily Laravel JSON logs;
- PHP-FPM slow logging;
- Horizon and slow-log rotation;
- guarded installation, verification, and rollback scripts.

## Privacy boundary

The Nginx operational log intentionally retains raw remote IP for a short operational retention period. It must not be imported into Reporting or treated as a visitor identity.

The access log does not store:

- query strings;
- full request targets;
- join/playback/preference tokens;
- signed URL parameters;
- cookies or authorization headers;
- form bodies;
- email or phone fields;
- full user-agent strings;
- full referrer URLs.

Referrers are reduced to origin. User agents are reduced to a coarse class.

## Repository changes

Copy these complete files into the repository:

```text
app/Http/Middleware/RequestCorrelation.php
bootstrap/app.php
config/logging.php
.env.example
tests/Feature/Observability/RequestCorrelationTest.php
tests/Feature/Observability/ObservabilityConfigurationContractTest.php
```

The middleware accepts Nginx’s request ID, places it in Laravel log context, and returns it in the response. When Nginx is not yet configured, Laravel creates a UUID itself.

## Server prerequisites

Required commands:

```text
bash
python3
nginx
php-fpm<version>
logrotate
systemctl
curl
```

The installer defaults to a dry run. `--apply` requires root and performs timestamped backups before modifying files.

## Resolve the real application path first

The supplied production evidence contains different historical Rob paths (`engage-core` and `leadflow-core`). Do not copy either value blindly.

Confirm the active Nginx document root:

```bash
sudo nginx -T 2>/dev/null | \
  sed -n '/server_name crm\.robthemortgagecoach\.com/,/^[[:space:]]*}/p' | \
  grep -m1 'root '
```

Remove `/public` from the resolved root and verify:

```bash
test -f /REAL/APP/PATH/artisan && echo OK
```

Use that exact path as `--app-path`.

## Deployment order

### 1. Validate repository files before production

Run the focused observability tests in local/staging/CI before deploying production:

```bash
php artisan test \
  tests/Feature/Observability/RequestCorrelationTest.php \
  tests/Feature/Observability/ObservabilityConfigurationContractTest.php
```

Do not make `php artisan test` a production step. The production install path uses `composer install --no-dev`, so development-only test tooling may not exist on the live host.

After pulling the approved current code on production, use runtime-safe commands only:

```bash
composer dump-autoload
php artisan optimize:clear
```

The Laravel request-correlation middleware is safe before Nginx changes: it generates its own request IDs until Nginx begins supplying them.

### 2. Configure the deployment’s root runtime logging

Logging is process-owned. Configure these values in the root application `.env`, not `client/<client-key>/.env`:

```env
LOG_CHANNEL=stack
LOG_STACK=daily_json
LOG_LEVEL=info
LOG_DAILY_DAYS=14
```

Then clear cached configuration:

```bash
php artisan optimize:clear
```

Verify effective config without printing the rest of the environment:

```bash
php artisan tinker --execute="dump([
    'default' => config('logging.default'),
    'stack_channels' => config('logging.channels.stack.channels'),
    'daily_json_level' => config('logging.channels.daily_json.level'),
    'daily_json_days' => config('logging.channels.daily_json.days'),
    'daily_json_path' => config('logging.channels.daily_json.path'),
]);"
```

Expected production shape:

```text
default = stack
stack channels = [daily_json]
daily_json level = info
daily_json days = 14
```

Current operational warning:

```text
scripts/operations/configure-client-logging.sh
```

may display unrelated environment values in its proposed diff. Do not run that helper against a secret-bearing production environment until its output is hardened to redact unrelated values. The safe production procedure is to edit only the four root logging keys above and verify only their effective Laravel config.

### 3. Dry-run the server installer

Example shape for Rob; replace `/REAL/APP/PATH` with the path verified above:

```bash
scripts/operations/install-observability.sh \
  --client-key rob-the-mortgage-coach \
  --app-path /REAL/APP/PATH \
  --nginx-site /etc/nginx/sites-enabled/crm.robthemortgagecoach.com \
  --access-log /var/log/nginx/robthemortgagecoach.com-access.log \
  --php-version 8.3 \
  --php-pool-config /etc/php/8.3/fpm/pool.d/www.conf \
  --php-pool-name www \
  --deploy-user slamdunkdeploy
```

The dry run prints the proposed Nginx and PHP-FPM diffs and makes no changes.

### 4. Apply server configuration

```bash
sudo scripts/operations/install-observability.sh \
  --client-key rob-the-mortgage-coach \
  --app-path /REAL/APP/PATH \
  --nginx-site /etc/nginx/sites-enabled/crm.robthemortgagecoach.com \
  --access-log /var/log/nginx/robthemortgagecoach.com-access.log \
  --php-version 8.3 \
  --php-pool-config /etc/php/8.3/fpm/pool.d/www.conf \
  --php-pool-name www \
  --deploy-user slamdunkdeploy \
  --apply
```

The installer:

1. resolves symlinks before modifying active config;
2. creates timestamped backups and a rollback manifest;
3. installs the global Nginx maps/log format;
4. installs the FastCGI request-ID snippet;
5. changes the client access log to `engage_core_json`;
6. adds the response request-ID header;
7. forwards the request ID to PHP;
8. enables PHP-FPM slow logging;
9. installs logrotate rules;
10. runs `nginx -t`, PHP-FPM config validation, and logrotate dry runs;
11. restores backups automatically if validation fails;
12. reloads Nginx and PHP-FPM only after successful validation.

Save the printed backup directory. It is required for one-command rollback.

### 5. Verify

Use a safe public page without a tokenized URL.

Run the verifier with `sudo`. It validates Nginx with `nginx -t`, and a non-root shell may be unable to read the active certificate files even when the running Nginx service itself is healthy.

```bash
sudo scripts/operations/verify-observability.sh \
  --app-path /REAL/APP/PATH \
  --public-url https://webinar.robthemortgagecoach.com/homebuyer-game-plan \
  --access-log /var/log/nginx/robthemortgagecoach.com-access.log \
  --php-version 8.3
```

Expected results:

- Nginx and PHP-FPM configurations validate;
- the response contains `X-Request-ID`;
- a matching JSON access-log record exists;
- the record includes host, method, safe path, status, timings, and client class;
- the safe path contains no query string;
- Laravel resolves `stack -> daily_json` with the requested retention.

## Apply to additional clients

The global Nginx format and PHP-FPM slow log are server-wide and idempotent. Repeat the installer for each client site so its server block receives:

- the JSON access-log format;
- request-ID response header;
- FastCGI request-ID forwarding;
- client-specific Horizon log rotation.

Configure the four root logging values for each client deployment. Do not use the current logging helper against a secret-bearing production environment until its diff output is hardened.

## Existing Nginx rotation

Ubuntu’s packaged `/etc/logrotate.d/nginx` normally rotates `/var/log/nginx/*.log`, including the per-client access/error files. Verify rather than duplicate it:

```bash
sudo cat /etc/logrotate.d/nginx
sudo logrotate -d /etc/logrotate.d/nginx
```

The Engage Core installer adds separate rules only for Horizon and the PHP-FPM slow log.

## Traffic investigation workflow

Locate one request by response ID:

```bash
REQUEST_ID='<value-from-response-or-user>'

grep -F "\"request_id\":\"$REQUEST_ID\"" \
  /var/log/nginx/robthemortgagecoach.com-access.log

grep -F "\"request_id\":\"$REQUEST_ID\"" \
  /REAL/APP/PATH/storage/logs/laravel.json-*.log
```

Review recent webinar POST outcomes at the edge:

```bash
python3 - <<'PY'
import json
from pathlib import Path

path = Path('/var/log/nginx/robthemortgagecoach.com-access.log')
for line in path.read_text(errors='replace').splitlines()[-5000:]:
    try:
        row = json.loads(line)
    except json.JSONDecodeError:
        continue
    if row.get('method') == 'POST' and 'homebuyer-game-plan' in row.get('path', ''):
        print(row)
PY
```

This identifies edge status/timing. Laravel validation reason codes will be added later with Reporting-owned HTTP instrumentation; Nginx alone cannot distinguish every redirect outcome.

## Rollback

Use the backup directory printed by the installer:

```bash
sudo scripts/operations/rollback-observability.sh \
  --backup-dir /var/backups/engage-core-observability/<client>/<timestamp> \
  --php-version 8.3
```

The rollback restores replaced files, removes files that were newly created, validates Nginx/PHP-FPM, and reloads both services.

To roll back the root logging environment, restore the prior root `.env` values or the operator-created root `.env` backup, then run:

```bash
php artisan optimize:clear
```

If a future hardened logging helper creates its own root `.env` backup, that backup may be used instead.

## Retention defaults

```text
Nginx access logs       14 rotations
Nginx error logs        packaged Nginx policy; verify locally
Laravel JSON logs       14 days
Horizon output          14 rotations
PHP-FPM slow log        14 rotations
```

These are operational logs, not Reporting observations.