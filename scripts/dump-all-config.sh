#!/usr/bin/env bash

set -euo pipefail

OUT="file_dumps/full-core-and-client-config-dump.txt"

mkdir -p "$(dirname "$OUT")"
: > "$OUT"

dump_directory() {
    local dir="$1"

    if [[ -d "$dir" ]]; then
        while IFS= read -r -d '' file; do
            printf '\n\n===== %s =====\n\n' "$file" >> "$OUT"
            cat "$file" >> "$OUT"
        done < <(find "$dir" -type f -print0 | sort -z)
    else
        printf '\n\n===== MISSING DIRECTORY: %s =====\n\n' "$dir" >> "$OUT"
    fi
}

dump_directory "config"

if [[ -d "client" ]]; then
    while IFS= read -r -d '' dir; do
        dump_directory "$dir"
    done < <(
        find client \
            -mindepth 2 \
            -maxdepth 2 \
            -type d \
            -name config \
            -print0 |
            sort -z
    )
else
    printf '\n\n===== MISSING DIRECTORY: client =====\n\n' >> "$OUT"
fi

printf 'Wrote %s\n' "$OUT"