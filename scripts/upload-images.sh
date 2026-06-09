#!/usr/bin/env bash
set -euo pipefail

CORE_ENV=".env"
CLIENT_ROOT="client"
LOCAL_DIR="public/images/processed/"
DEST_PREFIX="images"

if [ ! -f "$CORE_ENV" ]; then
  echo "Core .env file not found."
  exit 1
fi

set -a
source "$CORE_ENV"
set +a

if [ -z "${CLIENT_KEY:-}" ]; then
  echo "CLIENT_KEY is not set in core .env."
  exit 1
fi

CLIENT_ENV="$CLIENT_ROOT/$CLIENT_KEY/.env"

if [ ! -f "$CLIENT_ENV" ]; then
  echo "Client .env file not found: $CLIENT_ENV"
  exit 1
fi

set -a
source "$CLIENT_ENV"
set +a

required_vars=(
  DO_SPACES_KEY
  DO_SPACES_SECRET
  DO_SPACES_ENDPOINT
  DO_SPACES_REGION
  DO_SPACES_BUCKET
  CDN_BASE_URL
)

for var in "${required_vars[@]}"; do
  if [ -z "${!var:-}" ]; then
    echo "$var is not set in $CLIENT_ENV."
    exit 1
  fi
done

if [ ! -d "$LOCAL_DIR" ]; then
  echo "Local processed images directory not found: $LOCAL_DIR"
  exit 1
fi

CLIENT_PREFIX="$(echo "$CLIENT_KEY" | sed 's#^/*##; s#/*$##')"

DO_SPACES_HOST="${DO_SPACES_ENDPOINT#https://}"
DO_SPACES_HOST="${DO_SPACES_HOST#http://}"
DO_SPACES_HOST="${DO_SPACES_HOST%/}"

BUCKET_PATH="s3://$DO_SPACES_BUCKET/$CLIENT_PREFIX/$DEST_PREFIX/"
PUBLIC_URL="$(echo "$CDN_BASE_URL" | sed 's#/*$##')/$CLIENT_PREFIX/$DEST_PREFIX/"

echo ""
echo "Client:      $CLIENT_KEY"
echo "Client env:  $CLIENT_ENV"
echo "Bucket:      $BUCKET_PATH"
echo "Public URL:  $PUBLIC_URL"
echo "Endpoint:    $DO_SPACES_HOST"
echo ""
read -r -p "Upload processed images for this client? [y/N] " confirm

case "$confirm" in
  [yY]|[yY][eE][sS]) ;;
  *)
    echo "Upload cancelled."
    exit 0
    ;;
esac

s3cmd --config=/dev/null sync \
  --access_key="$DO_SPACES_KEY" \
  --secret_key="$DO_SPACES_SECRET" \
  --host="$DO_SPACES_HOST" \
  --host-bucket="%(bucket)s.$DO_SPACES_HOST" \
  --region="$DO_SPACES_REGION" \
  --acl-public \
  --add-header="Cache-Control: public, max-age=31536000, immutable" \
  --exclude=".DS_Store" \
  "$LOCAL_DIR" "$BUCKET_PATH"

echo ""
echo "Uploaded images to:"
echo "$PUBLIC_URL"