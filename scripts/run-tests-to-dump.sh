#!/usr/bin/env bash

set -Eeuo pipefail

script_dir="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
project_root="$(cd -- "$script_dir/.." && pwd)"
php_binary="${PHP_BINARY:-php}"
feature_tests_dir="$project_root/tests/Feature"
dump_dir="${TEST_DUMP_DIR:-$project_root/file_dumps/error_output}"
test_target=()

if [[ ! -f "$project_root/artisan" ]]; then
    echo "ERROR: artisan was not found at $project_root/artisan." >&2
    echo "Place this script in the Engage Core repository scripts/ directory." >&2
    exit 1
fi

if [[ ! -d "$feature_tests_dir" ]]; then
    echo "ERROR: tests/Feature was not found at $feature_tests_dir." >&2
    exit 1
fi

if ! command -v "$php_binary" >/dev/null 2>&1; then
    echo "ERROR: PHP binary [$php_binary] was not found." >&2
    exit 1
fi

if [[ "$dump_dir" != /* ]]; then
    dump_dir="$project_root/$dump_dir"
fi

umask 077
mkdir -p "$dump_dir"

mapfile -t test_directories < <(
    find "$feature_tests_dir" \
        -mindepth 1 \
        -maxdepth 1 \
        -type d \
        -printf '%f\n' \
        | sort
)

if [[ ${#test_directories[@]} -eq 0 ]]; then
    echo "ERROR: No test directories were found under tests/Feature." >&2
    exit 1
fi

echo "Select test scope:"
echo
echo "  1) All"

for index in "${!test_directories[@]}"; do
    printf '  %d) %s\n' "$((index + 2))" "${test_directories[$index]}"
done

echo "  $(( ${#test_directories[@]} + 2 ))) Quit"
echo

max_selection=$(( ${#test_directories[@]} + 2 ))

while true; do
    read -r -p "Selection [1-$max_selection]: " selection

    if [[ ! "$selection" =~ ^[0-9]+$ ]]; then
        echo "ERROR: Enter a number from 1 to $max_selection." >&2
        continue
    fi

    if (( selection < 1 || selection > max_selection )); then
        echo "ERROR: Enter a number from 1 to $max_selection." >&2
        continue
    fi

    if (( selection == 1 )); then
        scope_label="All tests"
        filename_scope="all"
        break
    fi

    if (( selection == max_selection )); then
        exit 0
    fi

    selected_directory="${test_directories[$((selection - 2))]}"
    relative_directory="tests/Feature/$selected_directory"
    test_target=("$relative_directory")
    scope_label="Feature test directory: $relative_directory"
    filename_scope="$(printf '%s' "$selected_directory" | tr '[:upper:]' '[:lower:]' | tr -cs '[:alnum:]' '-')"
    filename_scope="${filename_scope%-}"
    break
done

generated_at="$(date -u +'%Y-%m-%dT%H:%M:%SZ')"
filename_timestamp="$(date -u +'%Y%m%dT%H%M%SZ')"
dump_file="$dump_dir/test-dump-${filename_scope}-${filename_timestamp}-$$.txt"

command=("$php_binary" artisan test --no-ansi "${test_target[@]}" "$@")
printf -v command_display '%q ' "${command[@]}"
command_display="${command_display% }"

git_branch="unavailable"
git_commit="unavailable"

if command -v git >/dev/null 2>&1 && git -C "$project_root" rev-parse --is-inside-work-tree >/dev/null 2>&1; then
    git_branch="$(git -C "$project_root" branch --show-current 2>/dev/null || true)"
    git_commit="$(git -C "$project_root" rev-parse --short HEAD 2>/dev/null || true)"
    git_branch="${git_branch:-detached}"
    git_commit="${git_commit:-unavailable}"
fi

{
    echo "Engage Core Test Dump"
    echo "====================="
    echo "Generated: $generated_at"
    echo "Git branch: $git_branch"
    echo "Git commit: $git_commit"
    echo "Scope: $scope_label"
    echo "Command: $command_display"
    echo
    echo "TEST OUTPUT"
    echo "==========="
} | tee "$dump_file"

cd "$project_root"

set +e
"${command[@]}" 2>&1 \
    | awk '
        /^[[:space:]]*$/ {
            if (!blank) {
                print
                blank = 1
            }
            next
        }

        {
            blank = 0
            print
        }
    ' \
    | tee -a "$dump_file"

pipeline_status=("${PIPESTATUS[@]}")
set -e

test_status="${pipeline_status[0]}"
awk_status="${pipeline_status[1]}"
tee_status="${pipeline_status[2]}"

if (( test_status == 0 )); then
    result="PASSED"
else
    result="FAILED"
fi

{
    echo
    echo "RESULT"
    echo "======"
    echo "Status: $result"
    echo "Exit code: $test_status"
    echo "Completed: $(date -u +'%Y-%m-%dT%H:%M:%SZ')"
    echo "Dump file: $dump_file"
} | tee -a "$dump_file"

if (( tee_status != 0 )); then
    echo "ERROR: Test execution completed, but writing the dump failed with exit code $tee_status." >&2

    if (( test_status == 0 )); then
        exit "$tee_status"
    fi
fi

exit "$test_status"