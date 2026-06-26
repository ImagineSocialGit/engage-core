#!/usr/bin/env bash

set -euo pipefail

CLIENT_KEY="${1:-}"

if [[ -z "$CLIENT_KEY" ]]; then
  echo "Usage: ./scripts/create-client.sh client-key"
  exit 1
fi

CORE_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
ROOT_DIR="$(cd "$CORE_DIR/.." && pwd)"
CLIENTS_DIR="$ROOT_DIR/engage-core-clients"
CLIENT_DIR="$CLIENTS_DIR/$CLIENT_KEY"

if [[ -e "$CLIENT_DIR" ]]; then
  echo "Client already exists: $CLIENT_DIR"
  exit 1
fi

CLIENT_NAME="$(echo "$CLIENT_KEY" | sed -E 's/-/ /g' | sed -E 's/\b(.)/\U\1/g')"

mkdir -p "$CLIENT_DIR/config/generated"
mkdir -p "$CLIENT_DIR/config/messaging/emails"
mkdir -p "$CLIENT_DIR/config/webinars"
mkdir -p "$CLIENT_DIR/resources/views/emails/webinars"

cp -R "$CORE_DIR/config/webinars/." "$CLIENT_DIR/config/webinars/"

if [[ -d "$CORE_DIR/resources/views/emails/webinars" ]]; then
  cp "$CORE_DIR/resources/views/emails/webinars/"*.blade.php "$CLIENT_DIR/resources/views/emails/webinars/"
fi

cat > "$CLIENT_DIR/config/client.php" <<EOF
<?php

return [
    'name' => '$CLIENT_NAME',
    'key' => '$CLIENT_KEY',
];
EOF

cat > "$CLIENT_DIR/config/brand.php" <<'EOF'
<?php

return [
    'favicons' => [
        'base_url' => config('client.env.CDN_BASE_URL'),
    ],
];
EOF

cat > "$CLIENT_DIR/config/generated/images.php" <<'EOF'
<?php

return [
    //
];
EOF

cat > "$CLIENT_DIR/config/messaging/emails/webinars.php" <<'EOF'
<?php

return [
    'registration_confirmation' => [
        'enabled' => true,
        'subject' => 'You’re registered',
    ],

    'reminders' => [
        'enabled' => true,
    ],

    'post_follow_up' => [
        'enabled' => true,
    ],

    'waitlist_scheduled' => [
        'enabled' => true,
        'subject' => 'Registration is now open',
    ],

    'transactional_opt_out' => [
        'enabled' => true,
        'text' => 'Don’t want webinar reminder or follow-up emails?',
        'link_text' => 'Opt out of transactional webinar emails',
    ],
];
EOF

cat > "$CLIENT_DIR/.gitignore" <<'EOF'
# Environment
.env
.env.*
!.env.example

# Images / local upload pipeline artifacts
public/images/processed/
resources/images/raw/**
resources/images/manifest.json
scripts/**

# Build output
build/

# OS
.DS_Store
Thumbs.db

# IDE
.idea/
.vscode/

# Logs
*.log
EOF

cat > "$CLIENT_DIR/.env.example" <<'EOF'
# Client-local environment values only.
# Do not commit real secrets.

CDN_BASE_URL=
EOF

cat > "$CLIENT_DIR/README.md" <<EOF
# $CLIENT_NAME Engage Core Client

Private client configuration, branding, content, views, and runtime config for Engage Core.
EOF

echo "Created client: $CLIENT_DIR"
echo
echo "Next:"
echo "  cd $CLIENT_DIR"
echo "  git init"
echo "  git add ."
echo "  git commit -m \"Initial client config\""
echo
echo "Then set in core .env:"
echo "  CLIENT_KEY=$CLIENT_KEY"
