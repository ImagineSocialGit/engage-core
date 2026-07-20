#!/usr/bin/env bash

set -Eeuo pipefail

# Place in scripts/ under the Engage Core repository root.
# Produces: file_dumps/<CLIENT_KEY>_client_files_dump.txt

script_dir="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
project_root="$(cd -- "$script_dir/.." && pwd)"
clients_dir="$project_root/client"
output_dir="$project_root/file_dumps"

if [[ ! -f "$project_root/artisan" ]]; then
    echo "ERROR: artisan was not found at $project_root/artisan." >&2
    echo "Place this script in the Engage Core repository scripts/ directory." >&2
    exit 1
fi

if [[ ! -d "$clients_dir" ]]; then
    echo "ERROR: client/ was not found at $clients_dir." >&2
    exit 1
fi

mapfile -t clients < <(
    find "$clients_dir" \
        -mindepth 1 \
        -maxdepth 1 \
        -type d \
        -printf '%f\n' \
        | sort
)

if [[ ${#clients[@]} -eq 0 ]]; then
    echo "ERROR: No client directories were found under client/." >&2
    exit 1
fi

echo "Select a client to dump:"
echo

for index in "${!clients[@]}"; do
    printf '  %d) %s\n' "$((index + 1))" "${clients[$index]}"
done

quit_selection=$(( ${#clients[@]} + 1 ))
printf '  %d) Quit\n' "$quit_selection"
echo

while true; do
    read -r -p "Selection [1-$quit_selection]: " selection

    if [[ ! "$selection" =~ ^[0-9]+$ ]]; then
        echo "ERROR: Enter a number from 1 to $quit_selection." >&2
        continue
    fi

    if (( selection < 1 || selection > quit_selection )); then
        echo "ERROR: Enter a number from 1 to $quit_selection." >&2
        continue
    fi

    if (( selection == quit_selection )); then
        exit 0
    fi

    client_key="${clients[$((selection - 1))]}"
    break
done

client_dir="$clients_dir/$client_key"
output_file="$output_dir/${client_key}_client_files_dump.txt"

mkdir -p "$output_dir"

tmp_dir="$(mktemp -d)"
trap 'rm -rf "$tmp_dir"' EXIT

files_list="$tmp_dir/files.txt"
: > "$files_list"

add_file() {
    local file="$1"

    [[ -f "$file" ]] || return 0
    printf '%s\n' "$file" >> "$files_list"
}

add_tree() {
    local directory="$1"

    [[ -d "$directory" ]] || return 0

    find "$directory" \
        -type f \
        -print >> "$files_list"
}

add_tree "$client_dir/config"
add_tree "$client_dir/resources/views"
add_file "$client_dir/.env.example"

sort -u "$files_list" -o "$files_list"

file_count="$(wc -l < "$files_list" | tr -d ' ')"

if [[ "$file_count" -eq 0 ]]; then
    echo "ERROR: No client config, view, or .env.example files were found for [$client_key]." >&2
    exit 1
fi

generated_at="$(date -u +'%Y-%m-%dT%H:%M:%SZ')"

{
    echo "Engage Core Client Files Dump"
    echo "============================="
    echo
    echo "Client key: $client_key"
    echo "Generated: $generated_at"
    echo "Repository root: $project_root"
    echo "Included files: $file_count"
    echo
    echo "Collection scope:"
    echo "  - client/$client_key/config/**"
    echo "  - client/$client_key/resources/views/**"
    echo "  - client/$client_key/.env.example"
    echo
    echo "FILE INDEX"
    echo "=========="

    while IFS= read -r file; do
        echo "${file#$project_root/}"
    done < "$files_list"

    echo
    echo "FILE CONTENTS"
    echo "============="

    while IFS= read -r file; do
        relative_file="${file#$project_root/}"
        echo
        echo "===== $relative_file ====="
        echo

        if [[ -s "$file" ]]; then
            cat "$file"
            [[ "$(tail -c 1 "$file" 2>/dev/null || true)" == "" ]] || echo
        else
            echo "[EMPTY FILE]"
        fi
    done < "$files_list"
} > "$output_file"

echo
echo "Created: $output_file"
echo "Client: $client_key"
echo "Files included: $file_count"
