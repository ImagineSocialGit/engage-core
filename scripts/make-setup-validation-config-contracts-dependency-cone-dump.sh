#!/usr/bin/env bash

set -Eeuo pipefail

SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="${1:-$(cd -- "${SCRIPT_DIR}/.." && pwd)}"

if ! ROOT_DIR="$(cd -- "${ROOT_DIR}" 2>/dev/null && pwd)"; then
    printf 'Error: project root does not exist.\n' >&2
    printf 'Usage: %s [project-root]\n' "$0" >&2
    exit 1
fi

if [[ ! -f "${ROOT_DIR}/artisan" || ! -f "${ROOT_DIR}/composer.json" ]]; then
    printf 'Error: %s does not look like the Laravel project root.\n' "${ROOT_DIR}" >&2
    printf 'Usage: %s [project-root]\n' "$0" >&2
    exit 1
fi

OUTPUT_DIR="${ROOT_DIR}/file_dumps"
OUTPUT_FILE="${OUTPUT_DIR}/SetupValidation_ConfigContracts_dependency_cone_dump.txt"

mkdir -p "${OUTPUT_DIR}"

TMP_FILE="$(mktemp)"
trap 'rm -f "${TMP_FILE}"' EXIT

cd "${ROOT_DIR}"

add_file() {
    local file="$1"

    [[ -f "${file}" ]] || return 0

    case "${file}" in
        .env|.env.*)
            return 0
            ;;
        vendor/*|node_modules/*|storage/*|bootstrap/cache/*|public/build/*)
            return 0
            ;;
        *.pem|*.key|*.p12|*.pfx)
            return 0
            ;;
    esac

    printf '%s\n' "${file#./}" >> "${TMP_FILE}"
}

add_find_results() {
    local file

    while IFS= read -r file; do
        [[ -n "${file}" ]] && add_file "${file}"
    done
}

#
# Core/shared contract infrastructure.
#
for dir in \
    app/Support/ConfigContracts \
    app/Support/TokenContracts \
    app/Support/AutomationCapabilities \
    app/Support/Setup \
    app/Support/Validation
do
    if [[ -d "${dir}" ]]; then
        add_find_results < <(
            find "${dir}" -type f \
                \( -name '*.php' -o -name '*.md' \) \
                -print
        )
    fi
done

#
# Module-owned contracts and setup-validation contributors.
#
if [[ -d app/Modules ]]; then
    add_find_results < <(
        find app/Modules -type f \
            \( \
                -path '*/ConfigContracts/*' -o \
                -path '*/TokenContracts/*' -o \
                -path '*/AutomationCapabilities/*' -o \
                -path '*/Setup/*' -o \
                -path '*/Validation/*' \
            \) \
            -name '*.php' \
            -print
    )
fi

#
# Commands involved in setup validation, install/bootstrap, presets,
# and module registration.
#
if [[ -d app/Console/Commands ]]; then
    add_find_results < <(
        find app/Console/Commands -type f -name '*.php' \
            \( \
                -iname '*Setup*' -o \
                -iname '*Validate*' -o \
                -iname '*Install*' -o \
                -iname '*Module*' -o \
                -iname '*Preset*' \
            \) \
            -print
    )
fi

#
# Relevant config files.
#
if [[ -d config ]]; then
    add_find_results < <(
        find config -maxdepth 1 -type f -name '*.php' \
            \( \
                -iname '*module*' -o \
                -iname '*preset*' -o \
                -iname '*setup*' -o \
                -iname '*automation*' -o \
                -iname '*messaging*' \
            \) \
            -print
    )
fi

#
# Relevant service providers / bootstrap registration.
#
if [[ -d app/Providers ]]; then
    add_find_results < <(
        find app/Providers -type f -name '*.php' -print
    )
fi

[[ -f bootstrap/providers.php ]] && add_file bootstrap/providers.php

#
# Tests explicitly named for these contracts or setup behavior.
#
if [[ -d tests ]]; then
    add_find_results < <(
        find tests -type f -name '*.php' \
            \( \
                -iname '*ConfigContract*' -o \
                -iname '*TokenContract*' -o \
                -iname '*Setup*' -o \
                -iname '*Validate*' -o \
                -iname '*Install*' -o \
                -iname '*Preset*' -o \
                -iname '*Module*' -o \
                -iname '*AutomationCapability*' \
            \) \
            -print
    )
fi

#
# Dependency discovery:
# pick up consumers/registrars whose filenames alone do not reveal that
# they participate in setup validation or the config-contract system.
#
if command -v rg >/dev/null 2>&1; then
    SEARCH_PATHS=()

    for path in app config bootstrap routes tests; do
        [[ -e "${path}" ]] && SEARCH_PATHS+=("${path}")
    done

    if (( ${#SEARCH_PATHS[@]} > 0 )); then
        while IFS= read -r file; do
            [[ -n "${file}" ]] && add_file "${file}"
        done < <(
            rg -l \
                --glob '*.php' \
                --glob '!vendor/**' \
                --glob '!node_modules/**' \
                --glob '!storage/**' \
                --glob '!bootstrap/cache/**' \
                --glob '!public/build/**' \
                'ConfigContract|ConfigContractRegistry|TokenContract|TokenContractRegistry|setup:validate|SetupValidation|SetupValidator|AutomationCapabilit|preset:sync' \
                "${SEARCH_PATHS[@]}" \
                2>/dev/null || true
        )
    fi
else
    printf 'Warning: rg not found; content-based dependency discovery skipped.\n' >&2
fi

sort -u "${TMP_FILE}" -o "${TMP_FILE}"

FILE_COUNT="$(wc -l < "${TMP_FILE}" | tr -d ' ')"

: > "${OUTPUT_FILE}"

{
    printf 'ENGAGE CORE — SETUP VALIDATION / CONFIG CONTRACTS DEPENDENCY CONE\n'
    printf 'Generated: %s\n' "$(date '+%Y-%m-%d %H:%M:%S %z')"
    printf 'Project root: %s\n' "${ROOT_DIR}"
    printf 'Files: %s\n' "${FILE_COUNT}"
    printf '\n'

    if command -v git >/dev/null 2>&1 && git rev-parse --is-inside-work-tree >/dev/null 2>&1; then
        printf '===== GIT STATE =====\n'
        printf 'Branch: '
        git branch --show-current 2>/dev/null || true

        printf 'Commit: '
        git rev-parse HEAD 2>/dev/null || true

        printf '\nStatus:\n'
        git status --short 2>/dev/null || true
        printf '===== END GIT STATE =====\n\n'
    fi

    printf '===== FILE MANIFEST =====\n'
    cat "${TMP_FILE}"
    printf '===== END FILE MANIFEST =====\n\n'

    while IFS= read -r file; do
        [[ -f "${file}" ]] || continue

        printf '===== BEGIN FILE: %s =====\n' "${file}"
        cat "${file}"

        # Ensure END marker starts on its own line even if source file has no
        # trailing newline.
        printf '\n===== END FILE: %s =====\n\n' "${file}"
    done < "${TMP_FILE}"
} >> "${OUTPUT_FILE}"

printf 'Created: %s\n' "${OUTPUT_FILE}"
printf 'Files dumped: %s\n' "${FILE_COUNT}"