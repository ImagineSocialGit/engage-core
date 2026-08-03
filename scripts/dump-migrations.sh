#!/usr/bin/env bash

set -euo pipefail

OUT="file_dumps/all-migrations.txt"
DIR="database/migrations"

mkdir -p file_dumps
: > "$OUT"

if [ -d "$DIR" ]; then
  find "$DIR" -type f -name '*.php' -print0 |
    sort -z |
    while IFS= read -r -d '' file; do
      printf '\n\n===== %s =====\n\n' "$file" >> "$OUT"
      cat "$file" >> "$OUT"
    done
else
  printf '\n\n===== MISSING DIRECTORY: %s =====\n\n' "$DIR" >> "$OUT"
fi

echo "Wrote $OUT"
