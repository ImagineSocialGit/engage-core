#!/usr/bin/env bash

set -euo pipefail

OUT="file_dumps/all-tests.txt"
DIR="tests"

mkdir -p "$(dirname "$OUT")"
: > "$OUT"

if [[ -d "$DIR" ]]; then
    while IFS= read -r -d '' file; do
        printf '\n\n===== %s =====\n\n' "$file" >> "$OUT"
        cat "$file" >> "$OUT"
        printf '\n' >> "$OUT"
    done < <(find "$DIR" -type f -name '*.php' -print0 | sort -z)
else
    printf '\n\n===== MISSING DIRECTORY: %s =====\n\n' "$DIR" >> "$OUT"
fi

printf 'Wrote %s\n' "$OUT"
