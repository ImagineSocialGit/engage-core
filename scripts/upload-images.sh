#!/usr/bin/env bash
set -e

if [ ! -f .env ]; then
  echo ".env file not found."
  exit 1
fi

set -a
source .env
set +a

LOCAL_DIR="public/images/processed/"
BUCKET="s3://$DO_SPACES_BUCKET/images/"

if [ -z "${DO_SPACES_BUCKET:-}" ]; then
  echo "DO_SPACES_BUCKET is not set."
  exit 1
fi

if [ ! -d "$LOCAL_DIR" ]; then
  echo "Local processed images directory not found: $LOCAL_DIR"
  exit 1
fi

s3cmd sync \
  --acl-public \
  --add-header="Cache-Control: public, max-age=31536000, immutable" \
  --exclude=".DS_Store" \
  "$LOCAL_DIR" "$BUCKET"