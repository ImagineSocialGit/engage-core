#!/usr/bin/env bash

set -Eeuo pipefail

# Place in scripts/ under the Engage Core repository root.
# Produces: file_dumps/<INTEGRATION>_integration_files_dump.txt
#
# Collection scope:
#   - complete app/Integrations/<INTEGRATION>/**
#   - project files outside that tree that directly reference:
#       App\Integrations\<INTEGRATION>
#
# The external-reference scan is intentionally evidence-only. It does not
# recursively pull dependencies from those files, so this remains a focused
# integration dump rather than a dependency cone.

script_dir="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
project_root="$(cd -- "$script_dir/.." && pwd)"
integrations_dir="$project_root/app/Integrations"
output_dir="$project_root/file_dumps"

if [[ ! -f "$project_root/artisan" ]]; then
    echo "ERROR: artisan was not found at $project_root/artisan." >&2
    echo "Place this script in the Engage Core repository scripts/ directory." >&2
    exit 1
fi

if [[ ! -d "$integrations_dir" ]]; then
    echo "ERROR: app/Integrations/ was not found at $integrations_dir." >&2
    exit 1
fi

mapfile -t integrations < <(
    find "$integrations_dir" \
        -mindepth 1 \
        -maxdepth 1 \
        -type d \
        -printf '%f\n' \
        | sort
)

if [[ ${#integrations[@]} -eq 0 ]]; then
    echo "ERROR: No integration directories were found under app/Integrations/." >&2
    exit 1
fi

echo "Select an integration to dump:"
echo

for index in "${!integrations[@]}"; do
    printf '  %d) %s\n' "$((index + 1))" "${integrations[$index]}"
done

all_selection=$(( ${#integrations[@]} + 1 ))
quit_selection=$(( ${#integrations[@]} + 2 ))
printf '  %d) All\n' "$all_selection"
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

    if (( selection == all_selection )); then
        selected_integrations=("${integrations[@]}")
    else
        selected_integrations=("${integrations[$((selection - 1))]}")
    fi

    break
done

if [[ ${#selected_integrations[@]} -eq 1 ]]; then
    integration="${selected_integrations[0]}"
    output_file="$output_dir/${integration}_integration_files_dump.txt"
else
    integration="All"
    output_file="$output_dir/All_integration_files_dump.txt"
fi

mkdir -p "$output_dir"

tmp_dir="$(mktemp -d)"
trap 'rm -rf "$tmp_dir"' EXIT

seed_files="$tmp_dir/seed_files.txt"
reference_files="$tmp_dir/reference_files.txt"
files_list="$tmp_dir/files.txt"

: > "$seed_files"
: > "$reference_files"
: > "$files_list"

add_file() {
    local file="$1"
    local destination="$2"

    [[ -f "$file" ]] || return 0
    printf '%s\n' "$file" >> "$destination"
}

add_tree() {
    local directory="$1"
    local destination="$2"

    [[ -d "$directory" ]] || return 0

    find "$directory" \
        -type f \
        -print >> "$destination"
}

# First-class integration files and direct external references.
for selected_integration in "${selected_integrations[@]}"; do
    selected_dir="$integrations_dir/$selected_integration"
    selected_namespace="App\\Integrations\\$selected_integration"

    add_tree "$selected_dir" "$seed_files"

    search_roots=(
        "$project_root/app"
        "$project_root/bootstrap"
        "$project_root/config"
        "$project_root/database"
        "$project_root/docs"
        "$project_root/resources"
        "$project_root/routes"
        "$project_root/tests"
    )

    for root in "${search_roots[@]}"; do
        [[ -d "$root" ]] || continue

        while IFS= read -r -d '' file; do
            [[ "$file" == "$selected_dir/"* ]] && continue

            if grep -Fq -- "$selected_namespace" "$file" 2>/dev/null; then
                printf '%s\n' "$file" >> "$reference_files"
            fi
        done < <(
            find "$root" \
                -type f \
                ! -path "$project_root/vendor/*" \
                ! -path "$project_root/storage/*" \
                ! -path "$project_root/bootstrap/cache/*" \
                ! -path "$project_root/file_dumps/*" \
                ! -path "$project_root/.git/*" \
                -print0
        )
    done
done

sort -u "$seed_files" -o "$seed_files"
sort -u "$reference_files" -o "$reference_files"

cat "$seed_files" "$reference_files" | sort -u > "$files_list"

seed_count="$(wc -l < "$seed_files" | tr -d ' ')"
reference_count="$(wc -l < "$reference_files" | tr -d ' ')"
file_count="$(wc -l < "$files_list" | tr -d ' ')"

if [[ "$seed_count" -eq 0 ]]; then
    echo "ERROR: No integration files were found for the selected scope." >&2
    exit 1
fi

generated_at="$(date -u +'%Y-%m-%dT%H:%M:%SZ')"

{
    echo "Engage Core Integration Files Dump"
    echo "=================================="
    echo
    echo "Integration: $integration"
    if [[ ${#selected_integrations[@]} -eq 1 ]]; then
        echo "Namespace: App\\Integrations\\${selected_integrations[0]}"
    else
        echo "Namespaces:"
        for selected_integration in "${selected_integrations[@]}"; do
            echo "  - App\\Integrations\\$selected_integration"
        done
    fi
    echo "Generated: $generated_at"
    echo "Repository root: $project_root"
    echo "Included files: $file_count"
    echo "First-class integration files: $seed_count"
    echo "Direct external reference files: $reference_count"
    echo
    echo "Collection scope:"
    for selected_integration in "${selected_integrations[@]}"; do
        echo "  - complete app/Integrations/$selected_integration/**"
        echo "  - direct project references to App\\Integrations\\$selected_integration"
    done
    echo "  - external references are evidence-only; no recursive dependency traversal"
    echo "  - vendor/, storage/, bootstrap/cache/, file_dumps/, and .git/ excluded"
    echo
    echo "FIRST-CLASS INTEGRATION FILES"
    echo "============================="

    while IFS= read -r file; do
        [[ -n "$file" ]] || continue
        echo "${file#$project_root/}"
    done < "$seed_files"

    echo
    echo "DIRECT EXTERNAL REFERENCES"
    echo "=========================="

    if [[ "$reference_count" -eq 0 ]]; then
        echo "[NONE]"
    else
        while IFS= read -r file; do
            [[ -n "$file" ]] || continue
            echo "${file#$project_root/}"
        done < "$reference_files"
    fi

    echo
    echo "FILE INDEX"
    echo "=========="

    while IFS= read -r file; do
        [[ -n "$file" ]] || continue
        echo "${file#$project_root/}"
    done < "$files_list"

    echo
    echo "FILE CONTENTS"
    echo "============="

    while IFS= read -r file; do
        [[ -n "$file" ]] || continue

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
echo "Integration: $integration"
echo "First-class files: $seed_count"
echo "Direct external references: $reference_count"
echo "Files included: $file_count"
