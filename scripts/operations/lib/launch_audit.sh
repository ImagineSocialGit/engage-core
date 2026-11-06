#!/usr/bin/env bash
# Read-only Core deployment audit and diagnostic identity parsing.
# Loaded by the operator entry point; no side effects on source.

audit_reset() {
    AUDIT_PASS=0
    AUDIT_INFO=0
    AUDIT_WARNING=0
    AUDIT_BREAKING=0
    AUDIT_MANUAL=0
    AUDIT_BREAKING_KEYS=()
    AUDIT_RESULT_STATUSES=()
    AUDIT_RESULT_KEYS=()
    AUDIT_RESULT_MESSAGES=()
    AUDIT_SOURCE_CURRENT="true"
    AUDIT_APP_COMMANDS_AVAILABLE="unknown"
    AUDIT_APP_BOOTSTRAP_READY="unknown"
    AUDIT_NGINX_DUMP_LOADED="false"
    AUDIT_NGINX_DUMP=""
    AUDIT_PLAN_JSON=""
    AUDIT_MODULE_STATUS_JSON=""
}

audit_result() {
    local status="$1"
    local key="$2"
    local message="$3"
    message="${message//$'\n'/ }"

    printf '%-28s %-38s %s\n' "$status" "$key" "$message"

    AUDIT_RESULT_STATUSES+=("$status")
    AUDIT_RESULT_KEYS+=("$key")
    AUDIT_RESULT_MESSAGES+=("$message")

    case "$status" in
        PASS) ((AUDIT_PASS += 1)) ;;
        INFO) ((AUDIT_INFO += 1)) ;;
        WARNING) ((AUDIT_WARNING += 1)) ;;
        BREAKING)
            ((AUDIT_BREAKING += 1))
            AUDIT_BREAKING_KEYS+=("$key")
            ;;
        "MANUAL VERIFICATION REQUIRED") ((AUDIT_MANUAL += 1)) ;;
        *) fail "Unknown audit result status [$status]." ;;
    esac
}

audit_summary() {
    echo
    echo "Audit summary"
    printf '  PASS:                         %d\n' "$AUDIT_PASS"
    printf '  INFO:                         %d\n' "$AUDIT_INFO"
    printf '  WARNING:                      %d\n' "$AUDIT_WARNING"
    printf '  BREAKING:                     %d\n' "$AUDIT_BREAKING"
    printf '  MANUAL VERIFICATION REQUIRED: %d\n' "$AUDIT_MANUAL"
    echo
    echo "Fix eligibility: only BREAKING findings are candidates for automatic remediation."
    echo "Guided fix may review INFO/WARNING findings and collect operator-supplied required environment values."
    echo "INFO and WARNING findings are never normalized merely to match a new-deployment convention."
    echo "No deployment state was changed by audit."

    [[ "$AUDIT_BREAKING" -eq 0 ]]
}

audit_runtime_violation() {
    local key="$1"
    local message="$2"

    case "${AUDIT_CHECKOUT_ACTIVE:-unknown}" in
        true)
            audit_result BREAKING "$key" "$message"
            ;;
        false)
            audit_result WARNING "$key" "$message The audited checkout is not the live CRM owner, so this is cutover readiness rather than a current platform break."
            ;;
        *)
            audit_result WARNING "$key" "$message Active runtime ownership is not proven, so automatic repair is not eligible."
            ;;
    esac
}

audit_identity_value() {
    local key="$1"
    printf '%s' "$AUDIT_IDENTITY_JSON" | python3 -c '
import json, sys
data = json.load(sys.stdin)
value = data.get(sys.argv[1])
if value is None:
    raise SystemExit(1)
print(value)
' "$key"
}

audit_find_app_path() {
    local requested="$1"
    local canonical="$2"

    if [[ -n "$requested" ]]; then
        printf '%s\n' "$requested"
        return 0
    fi

    if [[ -f "$canonical/artisan" && -d "$canonical/client/$CLIENT_KEY" ]]; then
        printf '%s\n' "$canonical"
        return 0
    fi

    local -a candidates=()
    local modules_file candidate existing
    while IFS= read -r modules_file; do
        [[ -n "$modules_file" ]] || continue
        candidate="${modules_file%/client/$CLIENT_KEY/config/modules.php}"
        [[ -f "$candidate/artisan" ]] || continue

        local duplicate=false
        for existing in "${candidates[@]:-}"; do
            if [[ "$existing" == "$candidate" ]]; then
                duplicate=true
                break
            fi
        done

        [[ "$duplicate" == "true" ]] || candidates+=("$candidate")
    done < <(find /var/www -maxdepth 9 -type f -path "*/client/$CLIENT_KEY/config/modules.php" -print 2>/dev/null || true)

    if [[ ${#candidates[@]} -eq 1 ]]; then
        printf '%s\n' "${candidates[0]}"
        return 0
    fi

    if [[ ${#candidates[@]} -gt 1 ]]; then
        printf 'Multiple candidate Core checkouts contain client [%s]:\n' "$CLIENT_KEY" >&2
        printf '  - %s\n' "${candidates[@]}" >&2
        return 2
    fi

    return 1
}

audit_git_repo() {
    local path="$1"
    local key_prefix="$2"
    local expected_branch="$3"
    local expected_origin="${4:-}"

    if [[ ! -d "$path/.git" ]]; then
        audit_result WARNING "$key_prefix.repository" "Git metadata is missing at $path; current-source provenance cannot be verified."
        AUDIT_SOURCE_CURRENT="false"
        return
    fi

    local branch dirty origin head remote_head
    branch="$(git -C "$path" branch --show-current 2>/dev/null || true)"
    if [[ "$branch" == "$expected_branch" ]]; then
        audit_result PASS "$key_prefix.branch" "Branch is $branch."
    else
        audit_result WARNING "$key_prefix.branch" "Checkout is on [${branch:-detached/unknown}], expected [$expected_branch]. Pull/select the intended current branch before auditing runtime state."
        AUDIT_SOURCE_CURRENT="false"
    fi

    dirty="$(git -C "$path" status --porcelain 2>/dev/null || true)"
    if [[ -z "$dirty" ]]; then
        audit_result PASS "$key_prefix.clean" "Checkout is clean."
    else
        audit_result WARNING "$key_prefix.clean" "Checkout has uncommitted or untracked changes. Resolve source drift before auditing runtime state."
        AUDIT_SOURCE_CURRENT="false"
    fi

    origin="$(git -C "$path" remote get-url origin 2>/dev/null || true)"
    if [[ -z "$origin" ]]; then
        audit_result WARNING "$key_prefix.origin" "Origin remote is not configured; current-source provenance cannot be verified."
        AUDIT_SOURCE_CURRENT="false"
        return
    fi

    if [[ -n "$expected_origin" && "$origin" != "$expected_origin" ]]; then
        audit_result WARNING "$key_prefix.origin" "Origin differs from expected [$expected_origin]; found [$origin]. Resolve source identity before auditing runtime state."
        AUDIT_SOURCE_CURRENT="false"
    else
        audit_result PASS "$key_prefix.origin" "Origin is [$origin]."
    fi

    head="$(git -C "$path" rev-parse HEAD 2>/dev/null || true)"

    local remote_output remote_head remote_status
    set +e
    remote_output="$(git ls-remote "$origin" "refs/heads/$expected_branch" 2>&1)"
    remote_status=$?
    set -e

    remote_head="$(printf '%s\n' "$remote_output" | awk 'NR == 1 {print $1}')"

    if [[ "$remote_status" -ne 0 || -z "$remote_head" ]]; then
        remote_output="$(printf '%s' "$remote_output" | tail -n 3 | tr '\n' ' ')"
        audit_result "MANUAL VERIFICATION REQUIRED" "$key_prefix.current" \
            "Could not verify the remote [$expected_branch] revision without mutating local Git state. Git reported: ${remote_output:-[no diagnostic output]}"
        AUDIT_SOURCE_CURRENT="false"
    elif [[ "$head" == "$remote_head" ]]; then
        audit_result PASS "$key_prefix.current" "Local HEAD matches origin/$expected_branch."
    else
        audit_result WARNING "$key_prefix.current" \
            "Local HEAD does not match origin/$expected_branch. Pull the current repository state, then rerun audit."
        AUDIT_SOURCE_CURRENT="false"
    fi
}

audit_env_equals() {
    local file="$1"
    local env_key="$2"
    local expected="$3"
    local result_key="$4"
    local actual

    actual="$(env_get "$file" "$env_key")"
    if [[ -z "$actual" ]]; then
        audit_runtime_violation "$result_key" "$env_key is not populated in $file."
    elif [[ "$actual" == "$expected" ]]; then
        audit_result PASS "$result_key" "$env_key matches the canonical value."
    else
        audit_runtime_violation "$result_key" "$env_key expected [$expected]; found [$actual]."
    fi
}

audit_env_present_redacted() {
    local file="$1"
    local env_key="$2"
    local result_key="$3"
    local actual

    actual="$(env_get "$file" "$env_key")"
    if [[ -n "$actual" ]]; then
        audit_result PASS "$result_key" "$env_key is populated; value not displayed."
    else
        audit_runtime_violation "$result_key" "$env_key is blank or missing."
    fi
}

audit_env_file_metadata() {
    local path="$1"
    local key_prefix="$2"

    if [[ ! -f "$path" || -L "$path" ]]; then
        audit_result BREAKING "$key_prefix.file" "Environment file missing or symlinked: $path"
        return
    fi

    local owner group mode permission_bits
    owner="$(stat -c '%U' "$path")"
    group="$(stat -c '%G' "$path")"
    mode="$(stat -c '%a' "$path")"
    permission_bits=$((8#$mode))

    # Proven secret exposure is always reported, including for dirty Git or an
    # inactive checkout. Never classify world-readable files as harmless drift.
    if (( (permission_bits & 0007) != 0 || (permission_bits & 0020) != 0 )); then
        audit_result BREAKING "$key_prefix.permissions" \
            "Unsafe secret-file mode [$mode] ($owner:$group) at $path. Other access and group write must be disabled."
    else
        audit_result PASS "$key_prefix.permissions" \
            "Secret-file mode [$mode] ($owner:$group) denies other access and group write."
    fi

    if id "$DEPLOY_USER" >/dev/null 2>&1; then
        if sudo -n -u "$DEPLOY_USER" test -r "$path" 2>/dev/null; then
            audit_result PASS "$key_prefix.deploy_read" "Deploy identity can read the environment file."
        else
            audit_runtime_violation "$key_prefix.deploy_read" \
                "Deploy identity cannot read the environment file (or the read probe was unavailable)."
        fi
    else
        audit_result WARNING "$key_prefix.deploy_read" "Deploy identity is unavailable; cannot verify file access."
    fi

    if id "$WEB_USER" >/dev/null 2>&1; then
        if sudo -n -u "$WEB_USER" test -r "$path" 2>/dev/null; then
            audit_result PASS "$key_prefix.web_read" "PHP runtime identity can read the environment file."
        else
            audit_runtime_violation "$key_prefix.web_read" \
                "PHP runtime identity cannot read the environment file (or the read probe was unavailable)."
        fi
    else
        audit_result WARNING "$key_prefix.web_read" "PHP runtime identity is unavailable; cannot verify file access."
    fi

    if id nobody >/dev/null 2>&1 && [[ "$DEPLOY_USER" != nobody && "$WEB_USER" != nobody ]]; then
        if sudo -n -u nobody true >/dev/null 2>&1; then
            if sudo -n -u nobody test -r "$path" 2>/dev/null; then
                audit_result BREAKING "$key_prefix.unrelated_read" \
                    "Unrelated identity [nobody] can read the environment file; inspect extended ACLs."
            else
                audit_result PASS "$key_prefix.unrelated_read" \
                    "Unrelated identity [nobody] cannot read the environment file."
            fi
        else
            audit_result WARNING "$key_prefix.unrelated_read" \
                "Cannot execute the unrelated-identity denial probe; inspect sudo policy/ACLs manually."
        fi
    fi
}

audit_prefix_collision_from_map() {
    local collision_json="$1"
    local env_key="$2"
    local value="$3"
    local result_key="$4"

    [[ -n "$value" ]] || return 0

    local collisions
    collisions="$(printf '%s' "$collision_json" | python3 -c '
import json, sys
key = sys.argv[1]
try:
    payload = json.load(sys.stdin)
except json.JSONDecodeError:
    payload = {}
for path in payload.get(key, []):
    print(path)
' "$env_key" 2>/dev/null || true)"

    if [[ -z "$collisions" ]]; then
        audit_result PASS "$result_key" "$env_key is not duplicated in another readable /var/www environment."
    else
        collisions="${collisions//$'\n'/, }"
        audit_result WARNING "$result_key" \
            "$env_key is duplicated in another readable environment: $collisions. File duplication alone does not prove concurrent Redis use; review active workers before remediation."
    fi
}

audit_env_defaulted() {
    local file="$1"
    local env_key="$2"
    local default_value="$3"
    local result_key="$4"
    local actual

    actual="$(env_get "$file" "$env_key")"
    if [[ -z "$actual" ]]; then
        audit_result PASS "$result_key" "$env_key is not persisted and therefore uses the Core default [$default_value]."
    elif [[ "$actual" == "$default_value" ]]; then
        audit_result PASS "$result_key" "$env_key explicitly matches the Core default [$default_value]."
    else
        audit_result WARNING "$result_key" \
            "$env_key explicitly overrides the Core default [$default_value] with [$actual]. Review only if the override is not deliberate."
    fi
}

audit_canonical_drift() {
    local actual="$1"
    local expected="$2"
    local result_key="$3"
    local label="$4"

    if [[ -z "$actual" ]]; then
        audit_result WARNING "$result_key" "$label is blank. Validate the application-owned requirement before treating this as repairable."
    elif [[ "$actual" == "$expected" ]]; then
        audit_result PASS "$result_key" "$label matches canonical [$expected]."
    else
        audit_result INFO "$result_key" \
            "Legacy naming drift only: new deployments derive [$expected]; found [$actual]. Do not normalize a functional existing identity."
    fi
}

audit_load_nginx_dump() {
    if [[ "$AUDIT_NGINX_DUMP_LOADED" == "true" ]]; then
        [[ -n "$AUDIT_NGINX_DUMP" ]]
        return
    fi

    AUDIT_NGINX_DUMP_LOADED="true"

    local status
    set +e
    AUDIT_NGINX_DUMP="$(sudo nginx -T 2>/dev/null)"
    status=$?
    set -e

    [[ "$status" -eq 0 && -n "$AUDIT_NGINX_DUMP" ]]
}

audit_nginx_owners_json() {
    local host="$1"

    if audit_load_nginx_dump; then
        printf '%s' "$AUDIT_NGINX_DUMP" \
            | python3 "$HELPER" nginx-host-owners-stdin --host "$host" 2>/dev/null \
            || printf '[]'
        return
    fi

    python3 "$HELPER" nginx-host-owners \
        --directory /etc/nginx/sites-enabled \
        --host "$host" 2>/dev/null || printf '[]'
}

audit_nginx_owner_summary() {
    local owners_json="$1"

    printf '%s' "$owners_json" | python3 -c '
import json, sys
try:
    owners = json.load(sys.stdin)
except json.JSONDecodeError:
    owners = []
for owner in owners:
    root = owner.get("root") or "[no root]"
    config = owner.get("config") or "[unknown config]"
    fastcgi = owner.get("fastcgi_pass") or "[no fastcgi_pass]"
    print(f"{root} via {config} ({fastcgi})")
' 2>/dev/null || true
}

audit_detect_active_host_owner() {
    AUDIT_CHECKOUT_ACTIVE="unknown"
    AUDIT_ACTIVE_APP_PATH=""

    local owners_json count root config owner_summary
    owners_json="$(audit_nginx_owners_json "$CRM_HOST")"
    count="$(printf '%s' "$owners_json" | python3 -c 'import json,sys; print(len(json.load(sys.stdin)))' 2>/dev/null || printf '0')"

    if [[ "$count" -eq 0 ]]; then
        audit_result WARNING deployment.crm_host_owner \
            "No application-serving Nginx block was discovered for CRM host [$CRM_HOST]. Runtime ownership is not proven."
        return
    fi

    if [[ "$count" -gt 1 ]]; then
        owner_summary="$(audit_nginx_owner_summary "$owners_json")"
        owner_summary="${owner_summary//$'\n'/; }"
        audit_result BREAKING deployment.crm_host_owner \
            "Multiple distinct application-serving Nginx owners claim CRM host [$CRM_HOST]: ${owner_summary:-[details unavailable]}. Host ownership is genuinely ambiguous."
        return
    fi

    root="$(printf '%s' "$owners_json" | python3 -c 'import json,sys; print(json.load(sys.stdin)[0].get("root", ""))')"
    config="$(printf '%s' "$owners_json" | python3 -c 'import json,sys; print(json.load(sys.stdin)[0].get("config", ""))')"

    if [[ "$root" == "${APP_PATH%/}/public" ]]; then
        AUDIT_CHECKOUT_ACTIVE="true"
        AUDIT_ACTIVE_APP_PATH="$APP_PATH"
        audit_result PASS deployment.crm_host_owner \
            "CRM host [$CRM_HOST] is served by the audited Engage Core checkout via [$config]."
        return
    fi

    if [[ "$root" == */public ]]; then
        AUDIT_ACTIVE_APP_PATH="${root%/public}"
    fi
    AUDIT_CHECKOUT_ACTIVE="false"

    local active_origin=""
    if [[ -n "$AUDIT_ACTIVE_APP_PATH" && -d "$AUDIT_ACTIVE_APP_PATH/.git" ]]; then
        active_origin="$(git -C "$AUDIT_ACTIVE_APP_PATH" remote get-url origin 2>/dev/null || true)"
    fi

    audit_result INFO deployment.crm_host_owner \
        "CRM host [$CRM_HOST] is currently served from [${root:-unknown root}] via [$config], not [$APP_PATH/public].${active_origin:+ Active checkout origin: [$active_origin].} This is runtime ownership/cutover state, not an automatic fix."

    audit_result INFO deployment.checkout_role \
        "The audited Engage Core checkout is not the live CRM host owner. Treat its runtime findings as cutover readiness, not defects in the currently served application."
}

audit_composer_dependencies() {
    if [[ -f "$APP_PATH/vendor/autoload.php" ]]; then
        AUDIT_APP_COMMANDS_AVAILABLE="true"
        audit_result PASS runtime.composer_dependencies "Composer runtime dependencies are installed."
        return
    fi

    AUDIT_APP_COMMANDS_AVAILABLE="false"
    AUDIT_APP_BOOTSTRAP_READY="false"
    if [[ "$AUDIT_CHECKOUT_ACTIVE" == "false" ]]; then
        audit_result INFO runtime.composer_dependencies \
            "vendor/autoload.php is absent in the inactive Engage Core checkout. Application-level checks are unavailable until a deliberate cutover is prepared."
    else
        audit_result BREAKING runtime.composer_dependencies \
            "vendor/autoload.php is absent in the active Engage Core checkout. Artisan and application runtime cannot operate normally without installed Composer dependencies."
    fi
}

audit_client_environment_contract() {
    if [[ "$AUDIT_APP_COMMANDS_AVAILABLE" != "true" ]]; then
        return
    fi

    local output status
    set +e
    output="$(
        cd "$APP_PATH" &&
        "$PHP_BIN" -r '
require __DIR__."/vendor/autoload.php";

try {
    Dotenv\Dotenv::createImmutable(__DIR__)->safeLoad();
} catch (Throwable $e) {
    fwrite(STDERR, "ROOT_ENV_PARSE_ERROR: ".get_class($e).PHP_EOL);
    exit(2);
}

try {
    (new App\Support\Clients\ClientEnvironmentLoader())->load(__DIR__);
    echo "CLIENT ENV OK\n";
} catch (Throwable $e) {
    fwrite(STDERR, get_class($e).": ".$e->getMessage().PHP_EOL);
    exit(1);
}
' 2>&1
    )"
    status=$?
    set -e

    if [[ "$status" -eq 0 ]]; then
        AUDIT_APP_BOOTSTRAP_READY="true"
        audit_result PASS env.client_contract "Root/client environment loading contract passed before Laravel bootstrap."
        return
    fi

    AUDIT_APP_BOOTSTRAP_READY="false"
    output="$(printf '%s' "$output" | tail -n 3 | tr '\n' ' ')"

    if [[ "$status" -eq 2 ]]; then
        audit_runtime_violation env.root_parse \
            "Root .env could not be parsed safely. Error details are suppressed because environment values may be sensitive."
    else
        audit_runtime_violation env.client_contract \
            "Selected-client environment violates the bootstrap-safe ownership contract: ${output:-[no diagnostic output]}"
    fi
}

audit_app_command() {
    local result_key="$1"
    shift

    if [[ "${AUDIT_APP_BOOTSTRAP_READY:-unknown}" == "false" ]]; then
        audit_result INFO "$result_key"             "Not evaluated because the early environment/bootstrap contract is already failing."
        return
    fi

    local output status
    set +e
    output="$(
        cd "$APP_PATH" &&
        "$@" 2>&1
    )"
    status=$?
    set -e

    if [[ "$status" -eq 0 ]]; then
        audit_result PASS "$result_key" "Read-only application command passed."
    else
        output="$(printf '%s' "$output" | tail -n 8 | tr '\n' ' ')"
        audit_runtime_violation "$result_key" "Read-only application command failed: ${output:-[no output]}"
    fi
}

audit_resolve_plan() {
    if [[ "${AUDIT_APP_BOOTSTRAP_READY:-unknown}" == "false" ]]; then
        audit_result INFO deployment_plan.unavailable             "Not evaluated because the early environment/bootstrap contract is already failing."
        AUDIT_PLAN_JSON=""
        return
    fi

    local output status
    set +e
    output="$(
        cd "$APP_PATH" &&
        "$PHP_BIN" artisan engage:deployment-plan --json 2>&1
    )"
    status=$?
    set -e

    if ! printf '%s' "$output" | python3 -m json.tool >/dev/null 2>&1; then
        audit_runtime_violation deployment_plan.json \
            "engage:deployment-plan --json did not produce valid JSON (exit $status)."
        AUDIT_PLAN_JSON=""
        return
    fi

    AUDIT_PLAN_JSON="$output"
    while IFS=$'\t' read -r result_status result_key result_message; do
        [[ -n "$result_status" ]] || continue
        if [[ "$result_status" == "BREAKING" && "${AUDIT_CHECKOUT_ACTIVE:-unknown}" != "true" ]]; then
            audit_result WARNING "$result_key" "$result_message The audited checkout is not the live CRM owner, so this is cutover readiness rather than a current platform break."
        else
            audit_result "$result_status" "$result_key" "$result_message"
        fi
    done < <(printf '%s' "$AUDIT_PLAN_JSON" | python3 "$HELPER" plan-audit)
}

audit_module_migrations() {
    if [[ "${AUDIT_APP_BOOTSTRAP_READY:-unknown}" == "false" ]]; then
        audit_result INFO module_migrations.unavailable \
            "Not evaluated because the early environment/bootstrap contract is already failing."
        AUDIT_MODULE_STATUS_JSON=""
        return
    fi

    local output status payload
    set +e
    output="$(
        cd "$APP_PATH" &&
        "$PHP_BIN" -r '
require "vendor/autoload.php";
$app = require "bootstrap/app.php";
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$modules = app(\App\Support\Modules\ModuleManager::class);
$planner = app(\App\Support\Modules\Migrations\ModuleMigrationPlanner::class);
$inspector = app(\App\Support\Modules\Migrations\ModuleMigrationStatusInspector::class);
$migrator = app(\Illuminate\Database\Migrations\Migrator::class);

$enabled = $modules->enabledKeysWithDependencies();
$plan = $planner->forModules($enabled);
$statuses = $inspector->inspectScopes($plan->migrationScopes);

$payload = [
    "platform" => [
        "repository_exists" => $migrator->repositoryExists(),
        "ledger_exists" => \Illuminate\Support\Facades\Schema::hasTable("module_installations"),
        "checksum_ledger_exists" => \Illuminate\Support\Facades\Schema::hasTable("module_installations")
            && \Illuminate\Support\Facades\Schema::hasColumn("module_installations", "migration_checksums"),
    ],
    "scopes" => array_map(
        static fn (\App\Support\Modules\Migrations\ModuleMigrationStatus $status): array => [
            "module_key" => (string) $status->scope->moduleKey,
            "migration_state" => $status->migrationState,
            "progress" => $status->progress(),
            "pending_migrations" => $status->pendingMigrationFiles,
            "ledger_status" => $status->ledgerStatus,
            "contract_state" => $status->contractState,
            "integrity_state" => $status->integrityState,
            "changed_applied_migrations" => $status->changedAppliedMigrationFiles,
            "missing_recorded_migrations" => $status->missingRecordedMigrationFiles,
            "untracked_applied_migrations" => $status->untrackedAppliedMigrationFiles,
        ],
        $statuses,
    ),
];

echo "__ENGAGE_MODULE_STATUS_JSON__".json_encode($payload, JSON_THROW_ON_ERROR);
' 2>&1
    )"
    status=$?
    set -e

    if [[ "$status" -ne 0 || "$output" != *"__ENGAGE_MODULE_STATUS_JSON__"* ]]; then
        output="$(printf '%s' "$output" | tail -n 8 | tr '\n' ' ')"
        audit_runtime_violation module_migrations.inspect \
            "Unable to inspect enabled module migration state read-only: ${output:-[no output]}"
        AUDIT_MODULE_STATUS_JSON=""
        return
    fi

    payload="${output#*__ENGAGE_MODULE_STATUS_JSON__}"
    if ! printf '%s' "$payload" | python3 -m json.tool >/dev/null 2>&1; then
        audit_runtime_violation module_migrations.inspect \
            "Enabled module migration inspection returned invalid JSON."
        AUDIT_MODULE_STATUS_JSON=""
        return
    fi

    AUDIT_MODULE_STATUS_JSON="$payload"
    while IFS=$'\t' read -r result_status result_key result_message; do
        [[ -n "$result_status" ]] || continue
        if [[ "$result_status" == "BREAKING" && "${AUDIT_CHECKOUT_ACTIVE:-unknown}" != "true" ]]; then
            audit_result WARNING "$result_key" \
                "$result_message The audited checkout is not the live CRM owner, so this is cutover readiness rather than a current platform break."
        else
            audit_result "$result_status" "$result_key" "$result_message"
        fi
    done < <(printf '%s' "$AUDIT_MODULE_STATUS_JSON" | python3 "$HELPER" module-status-audit)
}

audit_plan_module_enabled() {
    local module="$1"
    [[ -n "$AUDIT_PLAN_JSON" ]] || return 1
    printf '%s' "$AUDIT_PLAN_JSON" \
        | python3 "$HELPER" plan-modules-stdin \
        | grep -Fxq "$module"
}

audit_core_hosts() {
    local -a hosts=("$CRM_HOST")

    if audit_plan_module_enabled messaging; then
        hosts+=("$MESSAGING_HOST")
    fi
    if audit_plan_module_enabled webinars; then
        hosts+=("$WEBINAR_HOST")
    fi
    if audit_plan_module_enabled messaging \
        || audit_plan_module_enabled inbound_messaging \
        || audit_plan_module_enabled webinars \
        || audit_plan_module_enabled forms
    then
        hosts+=("$WEBHOOKS_HOST")
    fi

    local forms_url forms_host
    if audit_plan_module_enabled forms; then
        forms_url="$(env_get "$CLIENT_ENV" FORMS_APP_URL)"
        if [[ -n "$forms_url" ]]; then
            forms_host="$(python3 "$HELPER" origin-host --origin "$forms_url" 2>/dev/null || true)"
            [[ -n "$forms_host" ]] && hosts+=("$forms_host")
        fi
    fi

    local scheduling_url scheduling_host
    scheduling_url="$(env_get "$CLIENT_ENV" SCHEDULING_APP_URL)"
    if [[ -n "$scheduling_url" ]]; then
        scheduling_host="$(python3 "$HELPER" origin-host --origin "$scheduling_url" 2>/dev/null || true)"
        [[ -n "$scheduling_host" ]] && hosts+=("$scheduling_host")
    fi

    printf '%s\n' "${hosts[@]}" | awk 'NF && !seen[$0]++'
}

audit_nginx_host() {
    local expected_host="$1"
    local owners_json count owner_summary matching_roots

    owners_json="$(audit_nginx_owners_json "$expected_host")"
    count="$(printf '%s' "$owners_json" | python3 -c 'import json,sys; print(len(json.load(sys.stdin)))' 2>/dev/null || printf '0')"

    if [[ "$count" -eq 0 ]]; then
        audit_result BREAKING "nginx.host.$expected_host" \
            "No application-serving Nginx block owns required Core host [$expected_host]."
        return
    fi

    matching_roots="$(printf '%s' "$owners_json" | python3 -c '
import json, sys
target = sys.argv[1].rstrip("/")
try:
    owners = json.load(sys.stdin)
except json.JSONDecodeError:
    owners = []
print(sum(1 for owner in owners if (owner.get("root") or "").rstrip("/") == target))
' "$APP_PATH/public" 2>/dev/null || printf '0')"

    if [[ "$count" -eq 1 && "$matching_roots" -eq 1 ]]; then
        audit_result PASS "nginx.host.$expected_host" \
            "Required Core host is served from $APP_PATH/public."
        return
    fi

    owner_summary="$(audit_nginx_owner_summary "$owners_json")"
    owner_summary="${owner_summary//$'\n'/; }"

    if [[ "$matching_roots" -gt 0 ]]; then
        audit_result BREAKING "nginx.host.$expected_host" \
            "Core host [$expected_host] has multiple distinct application-serving owners: ${owner_summary:-[details unavailable]}."
    else
        audit_result BREAKING "nginx.host.$expected_host" \
            "Core host [$expected_host] is served by a different application root: ${owner_summary:-[details unavailable]}."
    fi
}

audit_live_tls_host() {
    local host="$1"

    if ! command -v curl >/dev/null 2>&1; then
        audit_result "MANUAL VERIFICATION REQUIRED" "tls.host.$host" \
            "curl is unavailable; verify the live certificate and hostname coverage for https://$host manually."
        return
    fi

    local tls_error
    set +e
    tls_error="$(curl -sS \
        --resolve "$host:443:127.0.0.1" \
        --connect-timeout 5 \
        --max-time 10 \
        -o /dev/null \
        "https://$host/" 2>&1)"
    local curl_status=$?
    set -e

    if [[ "$curl_status" -eq 0 ]]; then
        audit_result PASS "tls.host.$host" \
            "The certificate served locally for [$host] passes CA and hostname validation."
    else
        tls_error="$(printf '%s' "$tls_error" | tail -n 2 | tr '\n' ' ')"
        audit_result BREAKING "tls.host.$host" \
            "TLS validation failed for the certificate actually served for [$host]: ${tls_error:-curl exit $curl_status}."
        return
    fi

    if ! command -v openssl >/dev/null 2>&1; then
        audit_result "MANUAL VERIFICATION REQUIRED" "tls.expiry.$host" \
            "openssl is unavailable; verify certificate expiry for [$host] manually."
        return
    fi

    local certificate
    certificate="$(
        printf '\n' \
            | openssl s_client \
                -connect 127.0.0.1:443 \
                -servername "$host" \
                -showcerts 2>/dev/null \
            | awk '
                /-----BEGIN CERTIFICATE-----/ {capture=1}
                capture {print}
                /-----END CERTIFICATE-----/ {exit}
            '
    )"

    if [[ -z "$certificate" ]]; then
        audit_result WARNING "tls.expiry.$host" \
            "TLS hostname validation passed, but the leaf certificate could not be extracted for expiry inspection."
        return
    fi

    if printf '%s\n' "$certificate" \
        | openssl x509 -noout -checkend 2592000 >/dev/null 2>&1
    then
        audit_result PASS "tls.expiry.$host" \
            "The certificate actually served for [$host] is valid for more than 30 days."
    else
        audit_result WARNING "tls.expiry.$host" \
            "The certificate actually served for [$host] expires within 30 days or its expiry could not be validated."
    fi
}

audit_nginx() {
    note "Nginx / TLS"
    local canonical="/etc/nginx/sites-available/$NGINX_SITE_NAME"
    local -a configs=()
    local path resolved existing

    for path in /etc/nginx/sites-enabled/*; do
        [[ -e "$path" ]] || continue
        if grep -Fq "root $APP_PATH/public;" "$path" 2>/dev/null; then
            resolved="$(readlink -f "$path" 2>/dev/null || printf '%s' "$path")"
            local duplicate=false
            for existing in "${configs[@]:-}"; do
                [[ "$existing" == "$resolved" ]] && duplicate=true
            done
            [[ "$duplicate" == "true" ]] || configs+=("$resolved")
        fi
    done

    if [[ ${#configs[@]} -eq 0 ]]; then
        audit_result BREAKING nginx.site \
            "No enabled Nginx configuration references the active Engage Core document root $APP_PATH/public."
    elif [[ ${#configs[@]} -eq 1 && "$(readlink -f "${configs[0]}" 2>/dev/null || printf '%s' "${configs[0]}")" == "$(readlink -f "$canonical" 2>/dev/null || printf '%s' "$canonical")" ]]; then
        audit_result PASS nginx.site "Canonical Core site exists at $canonical and is enabled."
    else
        audit_result INFO nginx.site \
            "Nginx site filename/layout differs from new-deployment convention [$canonical]; discovered: ${configs[*]}. Do not rename a healthy site for consistency."
    fi

    local expected_host
    while IFS= read -r expected_host; do
        [[ -n "$expected_host" ]] || continue
        audit_nginx_host "$expected_host"
    done < <(audit_core_hosts)

    local root_owners root_core_count root_summary
    root_owners="$(audit_nginx_owners_json "$ROOT_DOMAIN")"
    root_core_count="$(printf '%s' "$root_owners" | python3 -c '
import json, sys
target = sys.argv[1].rstrip("/")
try:
    owners = json.load(sys.stdin)
except json.JSONDecodeError:
    owners = []
print(sum(1 for owner in owners if (owner.get("root") or "").rstrip("/") == target))
' "$APP_PATH/public" 2>/dev/null || printf '0')"

    if [[ "$root_core_count" -gt 0 ]]; then
        audit_result BREAKING nginx.root_domain \
            "Root domain [$ROOT_DOMAIN] is served from the Core document root [$APP_PATH/public]. Core must not take ownership of the main-site root."
    else
        root_summary="$(audit_nginx_owner_summary "$root_owners")"
        root_summary="${root_summary//$'\n'/; }"
        if [[ -n "$root_summary" ]]; then
            audit_result PASS nginx.root_domain \
                "Root domain [$ROOT_DOMAIN] is served separately from Core: $root_summary."
        else
            audit_result PASS nginx.root_domain \
                "Root domain [$ROOT_DOMAIN] is not served from the Core document root."
        fi
    fi

    local crm_owners crm_root crm_fastcgi
    crm_owners="$(audit_nginx_owners_json "$CRM_HOST")"
    crm_root="$(printf '%s' "$crm_owners" | python3 -c '
import json, sys
target = sys.argv[1].rstrip("/")
try:
    owners = json.load(sys.stdin)
except json.JSONDecodeError:
    owners = []
for owner in owners:
    if (owner.get("root") or "").rstrip("/") == target:
        print(owner.get("root") or "")
        break
' "$APP_PATH/public" 2>/dev/null || true)"
    crm_fastcgi="$(printf '%s' "$crm_owners" | python3 -c '
import json, sys
target = sys.argv[1].rstrip("/")
try:
    owners = json.load(sys.stdin)
except json.JSONDecodeError:
    owners = []
for owner in owners:
    if (owner.get("root") or "").rstrip("/") == target:
        print(owner.get("fastcgi_pass") or "")
        break
' "$APP_PATH/public" 2>/dev/null || true)"

    if [[ "$crm_root" == "$APP_PATH/public" ]]; then
        audit_result PASS nginx.document_root \
            "CRM Nginx ownership resolves to $APP_PATH/public."
    else
        audit_result BREAKING nginx.document_root \
            "CRM Nginx ownership does not resolve to $APP_PATH/public."
    fi
    if [[ "$crm_fastcgi" == "unix:$PHP_FPM_SOCKET" ]]; then
        audit_result PASS nginx.php_fpm \
            "The CRM application-serving block uses expected PHP-FPM socket $PHP_FPM_SOCKET."
    elif [[ -n "$crm_fastcgi" ]]; then
        audit_result INFO nginx.php_fpm \
            "The CRM application-serving block uses [$crm_fastcgi] rather than new-deployment default [unix:$PHP_FPM_SOCKET]. HTTP runtime verification is authoritative before remediation."
    else
        audit_result WARNING nginx.php_fpm \
            "No fastcgi_pass target was discovered in the CRM application-serving block."
    fi

    if audit_load_nginx_dump; then
        audit_result PASS nginx.syntax "nginx -T passed and the active configuration was parsed."
    else
        local nginx_test
        set +e
        nginx_test="$(sudo nginx -t 2>&1)"
        local nginx_status=$?
        set -e
        if [[ "$nginx_status" -eq 0 ]]; then
            audit_result PASS nginx.syntax \
                "nginx -t passed, but nginx -T output was unavailable for authoritative ownership parsing."
        else
            audit_result BREAKING nginx.syntax \
                "$(printf '%s' "$nginx_test" | tail -n 3 | tr '\n' ' ')"
        fi
    fi

    while IFS= read -r expected_host; do
        [[ -n "$expected_host" ]] || continue
        audit_live_tls_host "$expected_host"
    done < <(audit_core_hosts)
}

audit_http_runtime() {
    note "HTTP runtime"

    if [[ "${AUDIT_CHECKOUT_ACTIVE:-unknown}" != "true" ]]; then
        audit_result INFO http.crm "HTTP runtime verification is skipped because the audited checkout is not the active CRM host owner."
        return
    fi

    if ! command -v curl >/dev/null 2>&1; then
        audit_result "MANUAL VERIFICATION REQUIRED" http.crm "curl is unavailable; verify https://$CRM_HOST/login manually."
        return
    fi

    local status
    status="$(curl -sS -o /dev/null -w '%{http_code}' --max-time 10 "https://$CRM_HOST/login" 2>/dev/null || true)"
    case "$status" in
        2??|3??|401|403) audit_result PASS http.crm "https://$CRM_HOST/login returned HTTP $status." ;;
        000|'') audit_result BREAKING http.crm "https://$CRM_HOST/login was not reachable over HTTPS." ;;
        *) audit_result BREAKING http.crm "https://$CRM_HOST/login returned HTTP $status." ;;
    esac
}

audit_dns() {
    note "DNS"
    local server_ip="$AUDIT_SERVER_IP"
    local source="explicit"

    if [[ -z "$server_ip" ]] && command -v curl >/dev/null 2>&1; then
        server_ip="$(curl -fsS --max-time 4 https://api.ipify.org 2>/dev/null || true)"
        if [[ ! "$server_ip" =~ ^([0-9]{1,3}\.){3}[0-9]{1,3}$ ]]; then
            server_ip=""
        else
            source="observed public IPv4"
        fi
    fi

    local host addresses
    while IFS= read -r host; do
        [[ -n "$host" ]] || continue
        addresses="$(getent ahostsv4 "$host" 2>/dev/null | awk '{print $1}' | sort -u | paste -sd, - || true)"
        if [[ -z "$addresses" ]]; then
            audit_runtime_violation "dns.$host" "No IPv4 address resolved for required host [$host]."
            continue
        fi

        if [[ -n "$AUDIT_SERVER_IP" ]]; then
            if tr ',' '\n' <<<"$addresses" | grep -Fxq "$AUDIT_SERVER_IP"; then
                audit_result PASS "dns.$host" "Resolves to authoritative server IPv4 $AUDIT_SERVER_IP."
            else
                audit_result BREAKING "dns.$host" "Authoritative server IPv4 is $AUDIT_SERVER_IP; required host resolved to $addresses."
            fi
        elif [[ -n "$server_ip" ]]; then
            if tr ',' '\n' <<<"$addresses" | grep -Fxq "$server_ip"; then
                audit_result PASS "dns.$host" "Resolves to this server's $source $server_ip."
            else
                audit_result WARNING "dns.$host" \
                    "Resolved $addresses, while this server reports $server_ip. Proxy/NAT may be intentional; use --server-ip for an authoritative direct-DNS comparison."
            fi
        else
            audit_result "MANUAL VERIFICATION REQUIRED" "dns.$host" \
                "Resolved IPv4: $addresses. Supply --server-ip to compare against an authoritative direct-DNS target."
        fi
    done < <(audit_core_hosts)
}

audit_runtime_directories() {
    note "Runtime directory access"
    local relative path owner group mode

    for relative in storage storage/logs bootstrap/cache; do
        path="$APP_PATH/$relative"
        if [[ ! -d "$path" ]]; then
            audit_runtime_violation "runtime.$relative" "Required runtime directory is missing: $path"
            continue
        fi

        if sudo -u "$DEPLOY_USER" test -w "$path" && sudo -u "$WEB_USER" test -w "$path"; then
            audit_result PASS "runtime.$relative.write" "$DEPLOY_USER and $WEB_USER both have effective write access."
        elif [[ "$AUDIT_CHECKOUT_ACTIVE" == "false" ]]; then
            audit_result INFO "runtime.$relative.write" \
                "The inactive Engage Core checkout is not yet writable by both $DEPLOY_USER and $WEB_USER. This is cutover readiness only."
        else
            audit_result BREAKING "runtime.$relative.write" \
                "The active runtime requires both $DEPLOY_USER and $WEB_USER to have effective write access."
        fi

        owner="$(stat -c '%U' "$path")"
        group="$(stat -c '%G' "$path")"
        mode="$(stat -c '%a' "$path")"
        if [[ "$owner" == "$DEPLOY_USER" && "$group" == "$WEB_GROUP" && "$mode" == "2775" ]]; then
            audit_result PASS "runtime.$relative.metadata" "Matches canonical $DEPLOY_USER:$WEB_GROUP 2775."
        else
            audit_result INFO "runtime.$relative.metadata" \
                "Metadata differs from new-deployment convention $DEPLOY_USER:$WEB_GROUP 2775; found $owner:$group $mode. Effective access is authoritative."
        fi
    done
}

audit_supervisor() {
    note "Supervisor / Horizon"
    local canonical="/etc/supervisor/conf.d/${HORIZON_PROGRAM}.conf"
    local config=""
    local -a candidates=()
    local horizon_process=false

    if ps aux | grep '[a]rtisan horizon' | grep -F "$APP_PATH" >/dev/null; then
        horizon_process=true
        audit_result PASS horizon.process "A Horizon process is running from $APP_PATH."
    else
        audit_result BREAKING horizon.process "No Horizon process was found for the active audited checkout $APP_PATH."
    fi

    if [[ -f "$canonical" ]]; then
        config="$canonical"
    else
        local path
        for path in /etc/supervisor/conf.d/*.conf; do
            [[ -f "$path" ]] || continue
            if grep -Fq "$APP_PATH/artisan horizon" "$path" 2>/dev/null; then
                candidates+=("$path")
            fi
        done
        if [[ ${#candidates[@]} -eq 1 ]]; then
            config="${candidates[0]}"
        fi
    fi

    if [[ -z "$config" ]]; then
        if [[ "$horizon_process" == "true" ]]; then
            audit_result WARNING supervisor.config                 "Horizon is running from the correct checkout, but no matching Supervisor config was found. An alternative process manager may be intentional; do not auto-install Supervisor without confirming ownership."
        else
            audit_result WARNING supervisor.config                 "No matching Supervisor config was found. The BREAKING horizon.process finding is the functional failure; process-manager choice remains a separate remediation decision."
        fi
        audit_app_command horizon.status "$PHP_BIN" artisan horizon:status
        return
    fi

    if [[ "$config" == "$canonical" ]]; then
        audit_result PASS supervisor.config "Canonical config exists at $canonical."
    else
        audit_result INFO supervisor.config "Supervisor config differs from new-deployment filename [$canonical]; found [$config]. Functional process ownership is authoritative."
    fi

    local program directory user command
    program="$(sed -n 's/^\[program:\([^]]*\)\].*/\1/p' "$config" | head -n 1)"
    directory="$(sed -n 's/^directory=//p' "$config" | head -n 1)"
    user="$(sed -n 's/^user=//p' "$config" | head -n 1)"
    command="$(sed -n 's/^command=//p' "$config" | head -n 1)"

    if [[ "$program" == "$HORIZON_PROGRAM" ]]; then
        audit_result PASS supervisor.program "Program name is $program."
    else
        audit_result INFO supervisor.program "Supervisor program name differs from new-deployment convention [$HORIZON_PROGRAM]; found [${program:-missing}]. Do not rename a healthy process for consistency."
    fi

    if [[ "$directory" == "$APP_PATH" && "$command" == *"$APP_PATH/artisan horizon"* ]]; then
        audit_result PASS supervisor.path "Supervisor command/directory point at the audited checkout."
    else
        audit_result WARNING supervisor.path "Supervisor config does not consistently point at $APP_PATH. Actual running process ownership is authoritative; do not rewrite a working process manager solely from config drift."
    fi

    if [[ "$user" == "$WEB_USER" ]]; then
        audit_result PASS supervisor.user "Horizon runs as $WEB_USER."
    else
        audit_result INFO supervisor.user "Horizon runs as [${user:-missing}] rather than new-deployment default [$WEB_USER]. Effective runtime access is authoritative."
    fi

    local status_output status_code
    set +e
    status_output="$(sudo supervisorctl status "${program:-$HORIZON_PROGRAM}" 2>&1)"
    status_code=$?
    set -e
    if [[ "$status_code" -eq 0 && "$status_output" == *"RUNNING"* ]]; then
        audit_result PASS supervisor.running "$status_output"
    elif [[ "$horizon_process" == "true" ]]; then
        audit_result WARNING supervisor.running "${status_output:-Supervisor does not report the program as running, but a Horizon process is active from the correct checkout.}"
    else
        audit_result WARNING supervisor.running "${status_output:-Supervisor program is not running.}"
    fi

    audit_app_command horizon.status "$PHP_BIN" artisan horizon:status
}

audit_scheduler() {
    note "Laravel Scheduler"
    local cron expected
    cron="$(sudo crontab -u "$SCHEDULER_USER" -l 2>/dev/null || true)"
    expected="cd ${APP_PATH} && ${PHP_BIN} artisan schedule:run"

    if printf '%s\n' "$cron" | grep -F "$expected" | grep -Fq "# ${SCHEDULER_MARKER}"; then
        audit_result PASS scheduler.cron "Canonical Scheduler cron entry and marker are present for $SCHEDULER_USER."
    elif printf '%s\n' "$cron" | grep -Fq "$APP_PATH" && printf '%s\n' "$cron" | grep -Fq "artisan schedule:run"; then
        audit_result INFO scheduler.cron \
            "A Scheduler entry exists for this checkout but differs from new-deployment command/marker [${SCHEDULER_MARKER}]. Functional scheduling is authoritative."
    else
        audit_result BREAKING scheduler.cron "No Scheduler cron entry was found for the active audited checkout $APP_PATH under $SCHEDULER_USER."
    fi

    audit_app_command scheduler.list "$PHP_BIN" artisan schedule:list
}

audit_database_and_namespaces() {
    note "Database / Redis identity"
    local db_host db_name db_user db_password
    db_host="$(env_get "$ROOT_ENV" DB_HOST)"
    db_name="$(env_get "$CLIENT_ENV" DB_DATABASE)"
    db_user="$(env_get "$CLIENT_ENV" DB_USERNAME)"
    db_password="$(env_get "$CLIENT_ENV" DB_PASSWORD)"

    if [[ "$DEPLOY_ENV" == "staging" ]]; then
        if [[ "$db_host" == "127.0.0.1" || "$db_host" == "localhost" ]]; then
            audit_result PASS database.topology "Core staging uses a local database host [$db_host]."
        else
            audit_result WARNING database.topology "New Core staging deployments use local MySQL; found DB_HOST [${db_host:-blank}]. A functional existing topology is not normalized automatically."
        fi
    else
        if [[ -n "$db_host" && "$db_host" != "127.0.0.1" && "$db_host" != "localhost" ]]; then
            audit_result PASS database.topology "Core production uses remote database host [$db_host]."
        else
            audit_result WARNING database.topology "New Core production deployments use a remote database; found DB_HOST [${db_host:-blank}]. Review deliberately, but do not auto-migrate a functional database topology."
        fi
    fi

    audit_canonical_drift "$db_name" "$DB_DATABASE_DERIVED" database.name DB_DATABASE
    audit_canonical_drift "$db_user" "$DB_USERNAME_DERIVED" database.user DB_USERNAME

    if [[ -n "$db_password" ]]; then
        audit_result PASS database.password "DB_PASSWORD is populated; value not displayed."
    else
        audit_runtime_violation database.password "DB_PASSWORD is blank."
    fi

    audit_canonical_drift "$(env_get "$CLIENT_ENV" CACHE_PREFIX)" "$CACHE_PREFIX" redis.cache_prefix CACHE_PREFIX
    audit_canonical_drift "$(env_get "$CLIENT_ENV" REDIS_PREFIX)" "$REDIS_PREFIX" redis.prefix REDIS_PREFIX
    audit_canonical_drift "$(env_get "$CLIENT_ENV" HORIZON_PREFIX)" "$HORIZON_PREFIX" redis.horizon_prefix HORIZON_PREFIX

    local cache_value redis_value horizon_value
    cache_value="$(env_get "$CLIENT_ENV" CACHE_PREFIX)"
    redis_value="$(env_get "$CLIENT_ENV" REDIS_PREFIX)"
    horizon_value="$(env_get "$CLIENT_ENV" HORIZON_PREFIX)"

    local -a collision_args=(
        python3 "$HELPER" env-collision-map
        --search-root /var/www
        --exclude "$CLIENT_ENV"
    )
    [[ -n "$cache_value" ]] && collision_args+=(--query "CACHE_PREFIX=$cache_value")
    [[ -n "$redis_value" ]] && collision_args+=(--query "REDIS_PREFIX=$redis_value")
    [[ -n "$horizon_value" ]] && collision_args+=(--query "HORIZON_PREFIX=$horizon_value")

    local collision_json
    collision_json="$("${collision_args[@]}" 2>/dev/null || printf '{}')"
    audit_prefix_collision_from_map "$collision_json" CACHE_PREFIX "$cache_value" redis.cache_collision
    audit_prefix_collision_from_map "$collision_json" REDIS_PREFIX "$redis_value" redis.redis_collision
    audit_prefix_collision_from_map "$collision_json" HORIZON_PREFIX "$horizon_value" redis.horizon_collision

    audit_env_defaulted "$ROOT_ENV" REDIS_HOST 127.0.0.1 redis.host
    audit_env_defaulted "$ROOT_ENV" REDIS_DB 0 redis.database
    audit_env_defaulted "$ROOT_ENV" REDIS_CACHE_DB 1 redis.cache_database
}

audit_resolve_public_hosts() {
    local crm_url discovered_crm
    crm_url="$(env_get "$CLIENT_ENV" CRM_APP_URL)"

    if [[ -n "$AUDIT_CRM_HOST" ]]; then
        CRM_HOST="$(python3 "$HELPER" origin-host --origin "https://$AUDIT_CRM_HOST" 2>/dev/null || true)"
        if [[ -z "$CRM_HOST" ]]; then
            audit_result WARNING env.crm_host "Provided --crm-host [$AUDIT_CRM_HOST] is invalid; using the derived CRM hostname for inspection."
            CRM_HOST="$(audit_identity_value crm_host)"
        fi
    elif [[ -n "$crm_url" ]]; then
        discovered_crm="$(python3 "$HELPER" origin-host --origin "$crm_url" 2>/dev/null || true)"
        CRM_HOST="${discovered_crm:-$(audit_identity_value crm_host)}"
    else
        CRM_HOST="$(audit_identity_value crm_host)"
    fi

    WEBHOOKS_HOST="$(audit_identity_value webhooks_host)"
    WEBINAR_HOST="$(audit_identity_value webinar_host)"
    MESSAGING_HOST="$(audit_identity_value messaging_host)"
}

audit_env_origin() {
    local file="$1"
    local env_key="$2"
    local expected_host="$3"
    local result_key="$4"
    local actual actual_host

    actual="$(env_get "$file" "$env_key")"
    if [[ -z "$actual" ]]; then
        audit_runtime_violation "$result_key" "$env_key is blank or missing."
        return
    fi

    actual_host="$(python3 "$HELPER" origin-host --origin "$actual" 2>/dev/null || true)"
    if [[ -z "$actual_host" ]]; then
        audit_runtime_violation "$result_key" "$env_key must be a valid http:// or https:// origin; found [$actual]."
        return
    fi

    if [[ "$actual_host" == "$expected_host" ]]; then
        audit_result PASS "$result_key" "$env_key is a valid origin for [$actual_host]."
    else
        audit_result INFO "$result_key" "$env_key is a valid origin for [$actual_host], while new deployments derive [$expected_host]. Functional host ownership is authoritative."
    fi
}

audit_environment_identity() {
    note "Environment / client identity"
    [[ -f "$ROOT_ENV" && -f "$CLIENT_ENV" ]] || return 0

    audit_env_equals "$ROOT_ENV" APP_ENV "$DEPLOY_ENV" env.app_env
    audit_env_equals "$ROOT_ENV" CLIENT_KEY "$CLIENT_KEY" env.client_key
    audit_env_present_redacted "$ROOT_ENV" APP_KEY env.app_key
    audit_env_equals "$CLIENT_ENV" ROOT_DOMAIN "$ROOT_DOMAIN" env.root_domain
    audit_env_origin "$CLIENT_ENV" APP_URL "$ROOT_DOMAIN" env.app_url
    audit_env_origin "$CLIENT_ENV" CRM_APP_URL "$CRM_HOST" env.crm_app_url
}

run_audit() {
    audit_reset
    require_command python3
    require_command git
    require_command stat
    require_command find
    require_command getent
    require_command sudo
    require_command awk
    require_command grep

    AUDIT_IDENTITY_JSON="$(python3 "$HELPER" derive-audit \
        --environment "$DEPLOY_ENV" \
        --client-key "$CLIENT_KEY" \
        --root-domain "$ROOT_DOMAIN")"

    CANONICAL_APP_PATH="$(audit_identity_value app_path)"
    DB_DATABASE_DERIVED="$(audit_identity_value database_name)"
    DB_USERNAME_DERIVED="$(audit_identity_value database_user)"
    CACHE_PREFIX="$(audit_identity_value cache_prefix)"
    REDIS_PREFIX="$(audit_identity_value redis_prefix)"
    HORIZON_PREFIX="$(audit_identity_value horizon_prefix)"
    HORIZON_PROGRAM="$(audit_identity_value horizon_program)"
    NGINX_SITE_NAME="$(audit_identity_value nginx_site_name)"
    SCHEDULER_MARKER="$(audit_identity_value scheduler_marker)"

    local discovery_status=0
    set +e
    APP_PATH="$(audit_find_app_path "$AUDIT_APP_PATH" "$CANONICAL_APP_PATH")"
    discovery_status=$?
    set -e

    if [[ "$discovery_status" -ne 0 || -z "$APP_PATH" ]]; then
        if [[ "$discovery_status" -eq 2 ]]; then
            audit_result "MANUAL VERIFICATION REQUIRED" deployment.app_path.discovery \
                "Multiple matching checkouts exist. Rerun audit with --app-path; do not remediate until the intended checkout is identified."
        else
            audit_result "MANUAL VERIFICATION REQUIRED" deployment.app_path.discovery \
                "No Core checkout containing client [$CLIENT_KEY] was found. Rerun with --app-path if it is outside /var/www."
        fi
        audit_summary
        return 1
    fi

    if [[ "$APP_PATH" == "$CANONICAL_APP_PATH" ]]; then
        audit_result PASS deployment.app_path "Checkout is at canonical path $CANONICAL_APP_PATH."
    else
        audit_result INFO deployment.app_path \
            "Legacy path drift only: new deployments use $CANONICAL_APP_PATH; audited checkout is at $APP_PATH. Do not relocate a functional deployment for consistency."
    fi

    CLIENT_PATH="$APP_PATH/client/$CLIENT_KEY"
    ROOT_ENV="$APP_PATH/.env"
    CLIENT_ENV="$CLIENT_PATH/.env"
    DEPLOY_USER="$AUDIT_DEPLOY_USER"
    WEB_USER="$AUDIT_WEB_USER"
    WEB_GROUP="$AUDIT_WEB_GROUP"
    SCHEDULER_USER="$AUDIT_SCHEDULER_USER"

    note "Environment secret access (independent of source provenance)"
    audit_env_file_metadata "$ROOT_ENV" env.root
    audit_env_file_metadata "$CLIENT_ENV" env.client

    PHP_BIN="$(command -v php || true)"
    if [[ -z "$PHP_BIN" ]]; then
        audit_result BREAKING runtime.php "PHP is not installed or not on PATH; the audited Core runtime cannot execute."
        audit_summary
        return 1
    fi
    PHP_VERSION="$("$PHP_BIN" -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')"
    PHP_FPM_SOCKET="/run/php/php${PHP_VERSION}-fpm.sock"

    if id "$DEPLOY_USER" >/dev/null 2>&1; then
        audit_result PASS runtime.deploy_user "Deploy user [$DEPLOY_USER] exists."
    else
        audit_result WARNING runtime.deploy_user "Deploy user [$DEPLOY_USER] does not exist; verify audit identity before remediation."
    fi
    if id "$WEB_USER" >/dev/null 2>&1; then
        audit_result PASS runtime.web_user "Web user [$WEB_USER] exists."
    else
        audit_result WARNING runtime.web_user "Web user [$WEB_USER] does not exist; verify audit identity before remediation."
    fi
    if getent group "$WEB_GROUP" >/dev/null 2>&1; then
        audit_result PASS runtime.web_group "Web group [$WEB_GROUP] exists."
    else
        audit_result WARNING runtime.web_group "Web group [$WEB_GROUP] does not exist; verify audit identity before remediation."
    fi
    if id "$SCHEDULER_USER" >/dev/null 2>&1; then
        audit_result PASS runtime.scheduler_user "Scheduler user [$SCHEDULER_USER] exists."
    else
        audit_result WARNING runtime.scheduler_user "Scheduler user [$SCHEDULER_USER] does not exist; verify audit identity before remediation."
    fi

    note "Repository state"
    local expected_core_origin
    expected_core_origin="$(git -C "$BUNDLE_ROOT" remote get-url origin 2>/dev/null || true)"
    audit_git_repo "$APP_PATH" source.core "$AUDIT_CORE_BRANCH" "$expected_core_origin"
    audit_git_repo "$CLIENT_PATH" source.client "$AUDIT_CLIENT_BRANCH" "$AUDIT_CLIENT_REPO"

    if [[ "$AUDIT_SOURCE_CURRENT" != "true" ]]; then
        echo
        echo "Authoritative runtime audit deferred: Core and client source must be clean and match their configured remote branches first."
        audit_summary || true
        return 1
    fi

    if [[ ! -f "$ROOT_ENV" || ! -f "$CLIENT_ENV" ]]; then
        audit_environment_identity
        audit_summary
        return 1
    fi

    audit_resolve_public_hosts
    audit_load_nginx_dump || true
    audit_detect_active_host_owner
    audit_environment_identity
    audit_composer_dependencies
    audit_client_environment_contract
    audit_database_and_namespaces

    note "Application deployment contract"
    AUDIT_PLAN_JSON=""
    if [[ "$AUDIT_APP_COMMANDS_AVAILABLE" == "true" && "$AUDIT_APP_BOOTSTRAP_READY" == "true" ]]; then
        audit_resolve_plan
        audit_app_command modules.status "$PHP_BIN" artisan modules:status
        audit_module_migrations
        audit_app_command setup.validate "$PHP_BIN" artisan setup:validate
    elif [[ "$AUDIT_APP_COMMANDS_AVAILABLE" != "true" ]]; then
        audit_result INFO deployment_plan.unavailable \
            "Not evaluated because Composer runtime dependencies are unavailable in the audited checkout."
        audit_result INFO modules.status \
            "Not evaluated because Composer runtime dependencies are unavailable in the audited checkout."
        audit_result INFO module_migrations.unavailable \
            "Not evaluated because Composer runtime dependencies are unavailable in the audited checkout."
        audit_result INFO setup.validate \
            "Not evaluated because Composer runtime dependencies are unavailable in the audited checkout."
    else
        audit_result INFO deployment_plan.unavailable \
            "Not evaluated because the early environment/bootstrap contract is already failing."
        audit_result INFO modules.status \
            "Not evaluated because the early environment/bootstrap contract is already failing."
        audit_result INFO module_migrations.unavailable \
            "Not evaluated because the early environment/bootstrap contract is already failing."
        audit_result INFO setup.validate \
            "Not evaluated because the early environment/bootstrap contract is already failing."
    fi

    audit_runtime_directories

    if [[ "$AUDIT_CHECKOUT_ACTIVE" == "false" ]]; then
        note "Supervisor / Horizon"
        audit_result INFO supervisor.audit_scope \
            "Not evaluated as a live Engage Core service because CRM traffic is currently owned by [${AUDIT_ACTIVE_APP_PATH:-another checkout}]."

        note "Laravel Scheduler"
        audit_result INFO scheduler.audit_scope \
            "Not evaluated as a live Engage Core service because the audited checkout is not the current CRM host owner."

        note "Nginx / TLS"
        audit_result INFO nginx.audit_scope \
            "Core-specific Nginx/TLS reconciliation is deferred until cutover; the current CRM host is intentionally reported under deployment.crm_host_owner."
    else
        audit_supervisor
        audit_scheduler
        audit_nginx
    fi

    audit_dns
    audit_http_runtime

    audit_summary
}

parse_audit() {
    local environment=""
    local client_key=""
    local root_domain=""
    local app_path=""
    local client_repo=""
    local crm_host=""
    local core_branch="main"
    local client_branch="main"
    local deploy_user
    deploy_user="$(id -un)"
    local web_user="www-data"
    local web_group="www-data"
    local scheduler_user=""
    local server_ip=""

    while (($#)); do
        case "$1" in
            --environment) environment=${2:?}; shift 2 ;;
            --client-key) client_key=${2:?}; shift 2 ;;
            --root-domain) root_domain=${2:?}; shift 2 ;;
            --app-path) app_path=${2:?}; shift 2 ;;
            --client-repo) client_repo=${2:?}; shift 2 ;;
            --crm-host) crm_host=${2:?}; shift 2 ;;
            --core-branch) core_branch=${2:?}; shift 2 ;;
            --client-branch) client_branch=${2:?}; shift 2 ;;
            --deploy-user) deploy_user=${2:?}; shift 2 ;;
            --web-user) web_user=${2:?}; shift 2 ;;
            --web-group) web_group=${2:?}; shift 2 ;;
            --scheduler-user) scheduler_user=${2:?}; shift 2 ;;
            --server-ip) server_ip=${2:?}; shift 2 ;;
            -h|--help) usage; return 0 ;;
            *) fail "Unknown audit argument: $1" ;;
        esac
    done

    [[ "$environment" == "staging" || "$environment" == "production" ]] \
        || fail "audit requires --environment staging|production."
    [[ -n "$client_key" ]] || fail "audit requires --client-key."
    [[ -n "$root_domain" ]] || fail "audit requires --root-domain."
    [[ "$client_key" =~ ^[a-z0-9][a-z0-9_-]*$ ]] || fail "Invalid --client-key [$client_key]."

    DEPLOY_ENV="$environment"
    CLIENT_KEY="$client_key"
    ROOT_DOMAIN="$(python3 "$HELPER" derive-audit \
        --environment "$environment" \
        --client-key "$client_key" \
        --root-domain "$root_domain" \
        | python3 -c 'import json,sys; print(json.load(sys.stdin)["root_domain"])')"

    AUDIT_APP_PATH="$app_path"
    AUDIT_CLIENT_REPO="$client_repo"
    AUDIT_CRM_HOST="$crm_host"
    AUDIT_CORE_BRANCH="$core_branch"
    AUDIT_CLIENT_BRANCH="$client_branch"
    AUDIT_DEPLOY_USER="$deploy_user"
    AUDIT_WEB_USER="$web_user"
    AUDIT_WEB_GROUP="$web_group"
    AUDIT_SCHEDULER_USER="${scheduler_user:-$deploy_user}"
    AUDIT_SERVER_IP="$server_ip"

    run_audit
}