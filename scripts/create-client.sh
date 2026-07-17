#!/usr/bin/env bash

set -euo pipefail

CLIENT_KEY="${1:-}"
CLIENT_TIMEZONE="${2:-}"

if [[ -z "$CLIENT_KEY" || -z "$CLIENT_TIMEZONE" ]]; then
  echo "Usage: ./scripts/create-client.sh client-key timezone"
  echo "Example: ./scripts/create-client.sh example-client America/Chicago"
  exit 1
fi

if [[ ! "$CLIENT_KEY" =~ ^[a-z0-9][a-z0-9_-]*$ ]]; then
  echo "Client key must start with a lowercase letter or number and contain only lowercase letters, numbers, hyphens, and underscores."
  exit 1
fi

if ! command -v php >/dev/null 2>&1; then
  echo "PHP is required to validate the client timezone and generated configuration files."
  exit 1
fi

php -r '
$timezone = $argv[1] ?? "";

if (! in_array($timezone, timezone_identifiers_list(), true)) {
    fwrite(STDERR, "Invalid timezone: {$timezone}\n");
    exit(1);
}
' "$CLIENT_TIMEZONE"

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
CLIENTS_DIR="$ROOT_DIR/client"
CLIENT_DIR="$CLIENTS_DIR/$CLIENT_KEY"
TEMP_CLIENT_DIR=""

if [[ -e "$CLIENT_DIR" ]]; then
  echo "Client already exists: $CLIENT_DIR"
  exit 1
fi

mkdir -p "$CLIENTS_DIR"
TEMP_CLIENT_DIR="$(mktemp -d "$CLIENTS_DIR/.${CLIENT_KEY}.creating.XXXXXX")"

cleanup() {
  if [[ -n "$TEMP_CLIENT_DIR" && -d "$TEMP_CLIENT_DIR" ]]; then
    rm -rf "$TEMP_CLIENT_DIR"
  fi
}

trap cleanup EXIT

CLIENT_NAME="$(
  echo "$CLIENT_KEY" \
    | tr '_-' '  ' \
    | awk '{
        for (i = 1; i <= NF; i++) {
          $i = toupper(substr($i, 1, 1)) substr($i, 2)
        }
      } 1'
)"

mkdir -p "$TEMP_CLIENT_DIR/config"
mkdir -p "$TEMP_CLIENT_DIR/resources/views"
mkdir -p "$TEMP_CLIENT_DIR/resources/images/raw"

cat > "$TEMP_CLIENT_DIR/config/client.php" <<EOF
<?php

return [
    'name' => '$CLIENT_NAME',
    'key' => '$CLIENT_KEY',

    'timezone' => '$CLIENT_TIMEZONE',

    'preset' => 'basic',
];
EOF

cat > "$TEMP_CLIENT_DIR/config/modules.php" <<'EOF'
<?php

return [
    'enabled' => [
        'tasks',
        'workflow',
    ],
];
EOF

cat > "$TEMP_CLIENT_DIR/resources/images/manifest.json" <<'EOF'
{}
EOF

cat > "$TEMP_CLIENT_DIR/.env.example" <<'EOF'
# Engage Core client deployment environment
#
# This file contains runtime values that should follow the selected CLIENT_KEY.
# Do not commit real secrets.
#
# Root .env owns:
#   CLIENT_KEY
#   APP_ENV / APP_DEBUG / APP_KEY
#   logging
#   queue connection and queue names
#   Redis host/port/database indexes
#   worker/process tuning
#
# Client PHP config owns:
#   client name/key
#   selected preset
#   stable client timezone
#   enabled runtime modules
#   version-controlled product/business behavior

################################
# URLS / HOSTS
################################

ROOT_DOMAIN=
APP_URL=
CRM_APP_URL=

# Optional when the Webinars module is enabled.
# WEBINAR_APP_URL=

################################
# DATABASE IDENTITY / CREDENTIALS
################################

# DB_CONNECTION / DB_HOST / DB_PORT stay in root .env.
DB_DATABASE=
DB_USERNAME=
DB_PASSWORD=

################################
# CLIENT-SCOPED NAMESPACES
################################

# Keep these unique per client/environment when infrastructure is shared.
CACHE_PREFIX=
REDIS_PREFIX=
HORIZON_PREFIX=

# Set deliberately when the session cookie should span selected subdomains.
# SESSION_DOMAIN=.example.com

################################
# FILE STORAGE / DIGITALOCEAN SPACES
################################

FILESYSTEM_DISK=spaces
DO_SPACES_KEY=
DO_SPACES_SECRET=
DO_SPACES_ENDPOINT=https://nyc3.digitaloceanspaces.com
DO_SPACES_REGION=nyc3
DO_SPACES_BUCKET=

# Optional public asset base. Leave commented to preserve the configured fallback.
# CDN_BASE_URL=https://cdn.example.com/client-key

################################
# EMAIL / RESEND
################################

MAIL_MAILER=resend
EMAIL_PROVIDER=resend

MAIL_FROM_ADDRESS=
MAIL_FROM_NAME="${APP_NAME}"

FROM_EMAIL_TRANSACTIONAL=
FROM_NAME_TRANSACTIONAL=

FROM_EMAIL_MARKETING=
FROM_NAME_MARKETING=

RESEND_API_KEY=
RESEND_WEBHOOK_SECRET=
RESEND_WEBHOOK_TIMESTAMP_DRIFT_SECONDS=300

# Optional provider-specific sender overrides.
# Leave commented to preserve normal FROM_* / MAIL_FROM_* fallbacks.
# RESEND_FROM_EMAIL_TRANSACTIONAL=
# RESEND_FROM_NAME_TRANSACTIONAL=
# RESEND_FROM_EMAIL_MARKETING=
# RESEND_FROM_NAME_MARKETING=

################################
# PERMISSION INVITATIONS
################################

# Optional override. Leave commented to preserve the APP_URL fallback.
# PERMISSION_INVITATION_PUBLIC_URL=

################################
# INTERNAL NOTIFICATIONS / INBOUND REPLIES
################################

# Optional overrides.
# INTERNAL_NOTIFICATION_FROM_ADDRESS=
# INTERNAL_NOTIFICATION_FROM_NAME=
# INBOUND_REPLY_DEFAULT_TEAM_MEMBER_EMAIL=

################################
# SMS / TELNYX
################################

# Enable and populate only when Messaging SMS is part of the client package.
SMS_ENABLED=false
SMS_PROVIDER=telnyx

# TELNYX_API_KEY=
# TELNYX_FROM_TRANSACTIONAL=
# TELNYX_FROM_MARKETING=
# TELNYX_FROM_NOTIFICATIONS=
# TELNYX_WEBHOOK_PUBLIC_KEY=
# MESSAGING_SMS_MARKETING_PROFILE_ID=
# MESSAGING_SMS_TRANSACTIONAL_PROFILE_ID=
# TELNYX_FROM=

################################
# TWILIO ALTERNATE PROVIDER
################################

# TWILIO_SID=
# TWILIO_AUTH_TOKEN=
# TWILIO_FROM=
# TWILIO_FROM_TRANSACTIONAL=
# TWILIO_FROM_MARKETING=
# TWILIO_VIRTUAL_PHONE=

################################
# WEBINARS / ZOOM
################################

# Enable and populate only when the Webinars module is part of the client package.
# WEBINAR_PROVIDER=zoom
# ZOOM_ACCOUNT_ID=
# ZOOM_CLIENT_ID=
# ZOOM_CLIENT_SECRET=
# ZOOM_WEBHOOK_SECRET=
EOF

cat > "$TEMP_CLIENT_DIR/README.md" <<EOF
# $CLIENT_NAME

Engage Core client configuration, content, views, and deployment-specific runtime environment.

This scaffold intentionally starts with the \`basic\` preset and the Tasks and Workflow modules. Add client-specific config contributions only when the client actually needs them.

## Review before use

1. Review \`config/client.php\`:
   - client name
   - client key
   - timezone
   - selected preset
2. Review \`config/modules.php\` and enable only the modules the client needs.
3. Decide which provider-backed features are required before adding credentials.

## Local setup

1. Copy the client environment example:

   \`\`\`bash
   cp client/$CLIENT_KEY/.env.example client/$CLIENT_KEY/.env
   \`\`\`

2. Populate \`client/$CLIENT_KEY/.env\` with client deployment values and secrets.
3. Set this single value in the Core root \`.env\`:

   \`\`\`env
   CLIENT_KEY=$CLIENT_KEY
   \`\`\`

4. Clear cached configuration and validate the selected package:

   \`\`\`bash
   php artisan optimize:clear
   php artisan presets:sync
   php artisan setup:validate
   \`\`\`

## Configuration ownership

\`config/**\`
: Stable client product behavior and version-controlled overrides.

Client \`.env\`
: Client-specific deployment values, provider credentials, and secrets.

Root \`.env\`
: Application/process infrastructure and the active \`CLIENT_KEY\`.
EOF

php -l "$TEMP_CLIENT_DIR/config/client.php" >/dev/null
php -l "$TEMP_CLIENT_DIR/config/modules.php" >/dev/null

php -r '
$json = file_get_contents($argv[1]);
json_decode($json, true, 512, JSON_THROW_ON_ERROR);
' "$TEMP_CLIENT_DIR/resources/images/manifest.json"

mv "$TEMP_CLIENT_DIR" "$CLIENT_DIR"
TEMP_CLIENT_DIR=""
trap - EXIT

cat <<EOF
Created client: $CLIENT_DIR
Name: $CLIENT_NAME
Timezone: $CLIENT_TIMEZONE
Preset: basic
Modules: tasks, workflow

Next:
  cp client/$CLIENT_KEY/.env.example client/$CLIENT_KEY/.env
  # Populate client/$CLIENT_KEY/.env
  # Set CLIENT_KEY=$CLIENT_KEY in the root .env
  php artisan optimize:clear
  php artisan presets:sync
  php artisan setup:validate
EOF