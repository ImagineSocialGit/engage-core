#!/usr/bin/env bash

set -euo pipefail

CLIENT_KEY="${1:-}"

if [[ -z "$CLIENT_KEY" ]]; then
  echo "Usage: ./scripts/create-client.sh client-key"
  exit 1
fi

if [[ ! "$CLIENT_KEY" =~ ^[a-z0-9][a-z0-9_-]*$ ]]; then
  echo "Client key must start with a lowercase letter or number and contain only lowercase letters, numbers, hyphens, and underscores."
  exit 1
fi

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
CLIENTS_DIR="$ROOT_DIR/client"
CLIENT_DIR="$CLIENTS_DIR/$CLIENT_KEY"

if [[ -e "$CLIENT_DIR" ]]; then
  echo "Client already exists: $CLIENT_DIR"
  exit 1
fi

CLIENT_NAME="$(
  echo "$CLIENT_KEY"     | tr '-' ' '     | awk '{ for (i = 1; i <= NF; i++) $i = toupper(substr($i, 1, 1)) substr($i, 2) } 1'
)"

mkdir -p "$CLIENT_DIR/config"
mkdir -p "$CLIENT_DIR/resources/views"
mkdir -p "$CLIENT_DIR/resources/images/raw"

cat > "$CLIENT_DIR/config/client.php" <<EOF
<?php

return [
    'name' => '$CLIENT_NAME',
    'key' => '$CLIENT_KEY',

    'timezone' => 'UTC',

    'preset' => 'basic',
];
EOF

cat > "$CLIENT_DIR/config/modules.php" <<'EOF'
<?php

return [
    'enabled' => [
        'tasks',
        'workflow',
    ],
];
EOF

cat > "$CLIENT_DIR/resources/images/manifest.json" <<'EOF'
{}
EOF

cat > "$CLIENT_DIR/.env.example" <<'EOF'
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
WEBINAR_APP_URL=
CRM_APP_URL=

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

DO_SPACES_KEY=
DO_SPACES_SECRET=
DO_SPACES_ENDPOINT=https://nyc3.digitaloceanspaces.com
DO_SPACES_REGION=nyc3
DO_SPACES_BUCKET=

# This should be the exact public base for this client's assets.
#
# Example when one shared bucket/CDN uses client prefixes:
#   https://cdn.example.com/rob-the-mortgage-coach
#
# Runtime image URLs append /images/... to this value.
CDN_BASE_URL=

################################
# EMAIL / RESEND
################################

MAIL_MAILER=resend
EMAIL_PROVIDER=resend

MAIL_FROM_ADDRESS=
MAIL_FROM_NAME=

FROM_EMAIL_TRANSACTIONAL=
FROM_NAME_TRANSACTIONAL=

FROM_EMAIL_MARKETING=
FROM_NAME_MARKETING=

RESEND_API_KEY=
RESEND_WEBHOOK_SECRET=

# Optional provider-specific sender overrides.
# Leave commented to preserve normal FROM_* / MAIL_FROM_* fallbacks.
# RESEND_FROM_EMAIL_TRANSACTIONAL=
# RESEND_FROM_NAME_TRANSACTIONAL=
# RESEND_FROM_EMAIL_MARKETING=
# RESEND_FROM_NAME_MARKETING=

################################
# PERMISSION INVITATIONS
################################

PERMISSION_INVITATION_PUBLIC_URL=

################################
# INTERNAL NOTIFICATIONS / INBOUND REPLIES
################################

# Optional overrides.
# INTERNAL_NOTIFICATION_FROM_ADDRESS=
# INTERNAL_NOTIFICATION_FROM_NAME=
# INBOUND_REPLY_DEFAULT_TEAM_MEMBER_EMAIL=

################################
# SMS
################################

SMS_ENABLED=false
SMS_PROVIDER=telnyx

################################
# TELNYX
################################

TELNYX_API_KEY=
TELNYX_FROM_TRANSACTIONAL=
TELNYX_FROM_MARKETING=

# Optional notification sender.
# TELNYX_FROM_NOTIFICATIONS=

TELNYX_WEBHOOK_PUBLIC_KEY=

MESSAGING_SMS_MARKETING_PROFILE_ID=
MESSAGING_SMS_TRANSACTIONAL_PROFILE_ID=

# Optional generic Telnyx fallback.
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

WEBINAR_PROVIDER=zoom

ZOOM_ACCOUNT_ID=
ZOOM_CLIENT_ID=
ZOOM_CLIENT_SECRET=
ZOOM_WEBHOOK_SECRET=
EOF

cat > "$CLIENT_DIR/README.md" <<EOF
# $CLIENT_NAME

Engage Core client configuration, content, views, and deployment-specific runtime environment.

## Local setup

1. Copy `.env.example` to `.env` and populate client runtime values.
2. Set this single value in the Core root `.env`:

   CLIENT_KEY=$CLIENT_KEY

Stable product behavior belongs in `config/**`. Client deployment values belong in this client's `.env`.
EOF

echo "Created client: $CLIENT_DIR"
echo
echo "Next:"
echo "  cp client/$CLIENT_KEY/.env.example client/$CLIENT_KEY/.env"
echo "  # Populate client/$CLIENT_KEY/.env"
echo "  # Then set in root .env:"
echo "  CLIENT_KEY=$CLIENT_KEY"
