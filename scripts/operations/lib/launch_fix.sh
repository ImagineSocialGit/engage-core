#!/usr/bin/env bash
# Guided reconciliation, eligibility and fix orchestration.
# Loaded as function declarations; nothing runs until fix dispatch.

audit_has_breaking() {
    local expected="$1"
    local key
    for key in "${AUDIT_BREAKING_KEYS[@]:-}"; do
        [[ "$key" == "$expected" ]] && return 0
    done
    return 1
}

audit_has_breaking_prefix() {
    local prefix="$1"
    local key
    for key in "${AUDIT_BREAKING_KEYS[@]:-}"; do
        [[ "$key" == "$prefix"* ]] && return 0
    done
    return 1
}

fix_confirm_mutation_once() {
    if [[ "$DEPLOY_ENV" != "production" ]]; then
        return 0
    fi

    if [[ "${FIX_PRODUCTION_CONFIRMED:-false}" == "true" ]]; then
        return 0
    fi

    local confirmation
    read -r -p "Type [$ROOT_DOMAIN] to allow this guided/automatic production mutation: " confirmation
    [[ "$confirmation" == "$ROOT_DOMAIN" ]] \
        || fail "Production fix confirmation did not match the root domain."

    FIX_PRODUCTION_CONFIRMED="true"
}

fix_review_nonbreaking_findings() {
    local -a indexes=()
    local i status key message

    for i in "${!AUDIT_RESULT_STATUSES[@]}"; do
        status="${AUDIT_RESULT_STATUSES[$i]}"
        [[ "$status" == "INFO" || "$status" == "WARNING" ]] || continue

        key="${AUDIT_RESULT_KEYS[$i]}"
        case "$key" in
            deployment.app_path|database.topology|database.name|database.user|redis.cache_prefix|redis.prefix|redis.horizon_prefix|redis.*_collision|deployment_plan.unused_environment|runtime.*.metadata|supervisor.config|supervisor.program|supervisor.running|nginx.site)
                indexes+=("$i")
                ;;
        esac
    done

    [[ ${#indexes[@]} -gt 0 ]] || return 0

    echo
    echo "== Guided review: non-breaking drift/warnings =="
    for i in "${indexes[@]}"; do
        printf '  %-38s [%s] %s\n' \
            "${AUDIT_RESULT_KEYS[$i]}" \
            "${AUDIT_RESULT_STATUSES[$i]}" \
            "${AUDIT_RESULT_MESSAGES[$i]}"
    done

    if prompt_yes_no "Keep these non-breaking findings as-is for this fix pass?" "yes"; then
        return 0
    fi

    echo
    echo "Reviewing individually. Choosing not to keep one stops fix without mutating that finding."
    for i in "${indexes[@]}"; do
        key="${AUDIT_RESULT_KEYS[$i]}"
        status="${AUDIT_RESULT_STATUSES[$i]}"
        message="${AUDIT_RESULT_MESSAGES[$i]}"
        echo
        echo "[$status] $key"
        echo "  $message"
        if ! prompt_yes_no "Keep the current state for [$key] and continue?" "yes"; then
            fail "Fix stopped at [$key]. This non-breaking finding has no generic automatic normalization; reconcile it deliberately, then rerun fix."
        fi
    done
}

fix_plan_blocking_environment_count() {
    [[ -n "$AUDIT_PLAN_JSON" ]] || {
        printf '0\n'
        return 0
    }

    printf '%s' "$AUDIT_PLAN_JSON" | python3 -c '
import json, sys
plan = json.load(sys.stdin)
count = 0
for item in plan.get("environment_requirements", []):
    if not isinstance(item, dict):
        continue
    status = item.get("status")
    requirement = item.get("requirement")
    if status in {"mismatch", "invalid"}:
        count += 1
    elif requirement == "required" and status in {"missing", "unresolved"}:
        count += 1
print(count)
'
}

fix_environment_plan() {
    [[ -n "$AUDIT_PLAN_JSON" ]] || return 0

    local count
    count="$(fix_plan_blocking_environment_count)"
    [[ "$count" -gt 0 ]] || return 0

    echo "  [environment] $count blocking environment requirement(s) need operator reconciliation:"
    printf '%s' "$AUDIT_PLAN_JSON" | python3 -c '
import json, sys
plan = json.load(sys.stdin)
for item in plan.get("environment_requirements", []):
    if not isinstance(item, dict):
        continue
    status = item.get("status")
    requirement = item.get("requirement")
    blocking = status in {"mismatch", "invalid"} or (
        requirement == "required" and status in {"missing", "unresolved"}
    )
    if not blocking:
        continue
    key = str(item.get("key", "unknown"))
    scope = str(item.get("scope", "unknown"))
    secret = "secret; hidden input" if item.get("secret") else "non-secret"
    expected = item.get("expected_value")
    suffix = f"; expected={expected}" if expected is not None and not item.get("secret") else ""
    print(f"                {key} ({scope}, {status}, {secret}{suffix})")
'
}

fix_environment_guidance() {
    [[ -n "$AUDIT_PLAN_JSON" ]] || return 0

    printf '%s' "$AUDIT_PLAN_JSON" | python3 -c '
import json, sys
plan = json.load(sys.stdin)
blocking = set()
for item in plan.get("environment_requirements", []):
    if not isinstance(item, dict):
        continue
    status = item.get("status")
    requirement = item.get("requirement")
    if status in {"mismatch", "invalid"} or (
        requirement == "required" and status in {"missing", "unresolved"}
    ):
        key = item.get("key")
        if isinstance(key, str):
            blocking.add(key)

shown = False
for step in plan.get("setup_steps", []):
    if not isinstance(step, dict):
        continue
    keys = {key for key in step.get("environment_keys", []) if isinstance(key, str)}
    if not (keys & blocking):
        continue
    if not shown:
        print("\nProvider/external guidance for the blocking values:")
        shown = True
    title = str(step.get("title", step.get("key", "Setup step"))).strip()
    reason = str(step.get("reason", "")).strip()
    print(f"\n  {title}")
    if reason:
        print(f"    {reason}")
    for instruction in step.get("instructions", []):
        if isinstance(instruction, str) and instruction.strip():
            print(f"    - {instruction.strip()}")
'
}

fix_reaudit_after_environment_change() {
    echo
    echo "Environment values changed. Clearing cached application configuration and re-auditing before any runtime repair."
    (
        cd "$APP_PATH" || exit 1
        "$PHP_BIN" artisan optimize:clear
    ) || return 1

    run_audit || true

    [[ "$AUDIT_SOURCE_CURRENT" == "true" ]] \
        || fail "Fix requires clean Core/client checkouts matching their configured remote branches. Source is never pulled automatically."
    [[ "${AUDIT_CHECKOUT_ACTIVE:-unknown}" == "true" ]] \
        || fail "Fix requires the audited checkout to be the active CRM owner."
}

fix_interactive_environment_reconciliation() {
    fix_category_selected environment || return 0
    [[ -n "$AUDIT_PLAN_JSON" ]] || return 0

    local count
    count="$(fix_plan_blocking_environment_count)"
    [[ "$count" -gt 0 ]] || return 0

    echo
    echo "== Guided reconciliation: environment/provider requirements =="
    fix_environment_guidance
    echo
    echo "The launcher will now prompt only for currently blocking environment values."
    echo "Secret input is hidden. Values are written only after you supply or approve them."
    echo "Use Ctrl+C to stop safely if you need to leave and retrieve a provider value."

    if ! prompt_yes_no "Reconcile these blocking environment values now?" "yes"; then
        echo "Skipping environment reconciliation for this pass."
        return 0
    fi

    fix_confirm_mutation_once

    local plan_file result_file changed
    plan_file="$(mktemp)" || return 1
    result_file="$(mktemp)" || {
        rm -f "$plan_file"
        return 1
    }

    printf '%s\n' "$AUDIT_PLAN_JSON" > "$plan_file"

    if ! python3 "$HELPER" resolve-requirements \
        --plan "$plan_file" \
        --root-env "$ROOT_ENV" \
        --client-env "$CLIENT_ENV" \
        --mode all \
        --result-file "$result_file"
    then
        rm -f "$plan_file" "$result_file"
        return 1
    fi

    changed="$(cat "$result_file" 2>/dev/null || printf '0')"
    rm -f "$plan_file" "$result_file"

    if [[ "$changed" =~ ^[0-9]+$ && "$changed" -gt 0 ]]; then
        fix_reaudit_after_environment_change || return 1
    else
        echo "No environment values changed."
    fi
}

fix_should_apply_interactively() {
    local prompt="$1"
    if [[ "$FIX_MODE" != "interactive" ]]; then
        return 0
    fi
    prompt_yes_no "$prompt" "yes"
}

fix_category_selected() {
    local category="$1"
    [[ ",${FIX_CATEGORIES}," == *",${category},"* ]]
}

fix_safe_nginx_missing_hosts() {
    [[ "${AUDIT_NGINX_DUMP_LOADED:-false}" == "true" ]] || return 0
    audit_has_breaking nginx.syntax && return 0

    local key host owners_json count
    for key in "${AUDIT_BREAKING_KEYS[@]:-}"; do
        [[ "$key" == nginx.host.* ]] || continue
        host="${key#nginx.host.}"
        owners_json="$(audit_nginx_owners_json "$host")"
        count="$(printf '%s' "$owners_json" | python3 -c 'import json,sys; print(len(json.load(sys.stdin)))' 2>/dev/null || printf '0')"
        [[ "$count" -eq 0 ]] && printf '%s\n' "$host"
    done | awk 'NF && !seen[$0]++'
}

fix_schema_modules() {
    [[ -n "$AUDIT_MODULE_STATUS_JSON" ]] || return 0
    printf '%s' "$AUDIT_MODULE_STATUS_JSON" | python3 "$HELPER" module-status-fix-modules
}

fix_schema_repairable() {
    [[ -n "$AUDIT_MODULE_STATUS_JSON" ]]         && audit_has_breaking_prefix module_migrations.
}

fix_schema_plan() {
    local -a modules=()
    mapfile -t modules < <(fix_schema_modules)

    echo "  [schema] cd $APP_PATH"
    echo "           $PHP_BIN artisan migrate --force"
    echo "           $PHP_BIN artisan modules:preflight"
    local module
    for module in "${modules[@]}"; do
        echo "           $PHP_BIN artisan modules:install $module --force"
    done
    echo "           $PHP_BIN artisan presets:sync"
    echo "           $PHP_BIN artisan modules:status"
    echo "           then re-evaluate setup:validate before runtime workers are eligible."
}

fix_apply_schema() {
    if [[ -z "$AUDIT_MODULE_STATUS_JSON" ]]; then
        echo "ERROR: Module migration audit data is unavailable; refusing automatic schema repair." >&2
        return 1
    fi

    local modules_output
    if ! modules_output="$(fix_schema_modules)"; then
        echo "ERROR: Unable to derive the enabled schema repair list from audit data." >&2
        return 1
    fi

    local -a modules=()
    if [[ -n "$modules_output" ]]; then
        mapfile -t modules <<<"$modules_output"
    fi

    note "Fix: platform and enabled module schema"

    cd "$APP_PATH" || return 1
    "$PHP_BIN" artisan migrate --force || return 1
    "$PHP_BIN" artisan modules:preflight || return 1

    local module
    for module in "${modules[@]}"; do
        "$PHP_BIN" artisan modules:install "$module" --force || return 1
    done

    "$PHP_BIN" artisan presets:sync || return 1
    "$PHP_BIN" artisan modules:status || return 1
}

fix_runtime_directories_ready() {
    local relative path
    for relative in storage storage/logs bootstrap/cache; do
        path="$APP_PATH/$relative"
        [[ -d "$path" ]] || return 1
        sudo -u "$DEPLOY_USER" test -w "$path" || return 1
        sudo -u "$WEB_USER" test -w "$path" || return 1
    done
}

fix_application_ready() {
    local plan output status

    set +e
    plan="$(
        cd "$APP_PATH" &&
        "$PHP_BIN" artisan engage:deployment-plan --json 2>/dev/null
    )"
    status=$?
    set -e
    [[ "$status" -eq 0 ]] || return 1
    printf '%s' "$plan" | python3 -m json.tool >/dev/null 2>&1 || return 1

    local blockers
    blockers="$(printf '%s' "$plan" | python3 -c '
import json, sys
plan = json.load(sys.stdin)
blocking = 0
for item in plan.get("environment_requirements", []):
    if not isinstance(item, dict):
        continue
    status = item.get("status")
    requirement = item.get("requirement")
    if status in {"mismatch", "invalid"}:
        blocking += 1
    elif requirement == "required" and status in {"missing", "unresolved"}:
        blocking += 1
print(blocking)
')"
    [[ "$blockers" == "0" ]] || return 1

    set +e
    output="$(
        cd "$APP_PATH" &&
        "$PHP_BIN" artisan setup:validate >/dev/null 2>&1
    )"
    status=$?
    set -e

    [[ "$status" -eq 0 ]]
}

fix_manual_breaking_summary() {
    local key
    for key in "${AUDIT_BREAKING_KEYS[@]:-}"; do
        case "$key" in
            module_migrations.*)
                fix_category_selected schema || echo "  [not selected] $key"
                ;;
            runtime.storage.write|runtime.storage/logs.write|runtime.bootstrap/cache.write)
                fix_category_selected runtime || echo "  [not selected] $key"
                ;;
            nginx.host.*)
                if fix_category_selected nginx; then
                    local host="${key#nginx.host.}"
                    if ! fix_safe_nginx_missing_hosts | grep -Fxq "$host"; then
                        echo "  [manual] $key — Nginx ownership is ambiguous or already claimed; automatic repair is refused."
                    fi
                else
                    echo "  [not selected] $key"
                fi
                ;;
            tls.host.*)
                local tls_host="${key#tls.host.}"
                if fix_category_selected nginx && fix_safe_nginx_missing_hosts | grep -Fxq "$tls_host"; then
                    :
                else
                    echo "  [manual] $key — TLS-only or non-selected repair requires operator review."
                fi
                ;;
            horizon.process|horizon.status)
                if fix_category_selected horizon; then
                    if [[ -z "$(fix_existing_supervisor_config 2>/dev/null || true)" ]]; then
                        echo "  [manual] $key — no single existing Supervisor owner can be selected safely."
                    fi
                else
                    echo "  [not selected] $key"
                fi
                ;;
            scheduler.cron)
                fix_category_selected scheduler || echo "  [not selected] $key"
                ;;
            setup.validate)
                if fix_category_selected schema && fix_schema_repairable; then
                    echo "  [recheck] setup.validate — re-evaluate after schema repair."
                else
                    echo "  [manual] setup.validate — no registered deterministic prerequisite repair explains this failure."
                fi
                ;;
            deployment_plan.*)
                if fix_category_selected environment && [[ "$FIX_MODE" != "apply" ]]; then
                    echo "  [guided] $key — operator-supplied environment reconciliation is available; values are never invented."
                else
                    echo "  [manual] $key — non-interactive fix never invents or collects environment/provider values."
                fi
                ;;
            external_setup.*|dns.*)
                echo "  [manual] $key — provider-dashboard verification and DNS changes remain operator/external work."
                ;;
            *)
                echo "  [manual] $key — no closed safe-remediation handler is registered."
                ;;
        esac
    done
}

fix_print_plan() {
    echo
    echo "Fix plan (${FIX_MODE})"
    echo "Selected categories: $FIX_CATEGORIES"

    local planned=false

    if fix_category_selected environment && [[ "$(fix_plan_blocking_environment_count)" -gt 0 ]]; then
        fix_environment_plan
        planned=true
    fi

    if fix_category_selected schema && fix_schema_repairable; then
        fix_schema_plan
        planned=true
    fi

    if fix_category_selected runtime && (
        audit_has_breaking runtime.storage.write \
        || audit_has_breaking runtime.storage/logs.write \
        || audit_has_breaking runtime.bootstrap/cache.write
    ); then
        fix_runtime_plan
        planned=true
    fi

    if fix_category_selected nginx && [[ -n "$(fix_safe_nginx_missing_hosts)" ]]; then
        fix_nginx_plan
        planned=true
    fi

    if fix_category_selected horizon && (
        audit_has_breaking horizon.process || audit_has_breaking horizon.status
    ); then
        fix_horizon_plan
        planned=true
    fi

    if fix_category_selected scheduler && audit_has_breaking scheduler.cron; then
        fix_scheduler_plan
        planned=true
    fi

    if [[ "$planned" == "false" ]]; then
        echo "  No guided reconciliation or registered safe repair is currently selected."
    fi

    echo
    echo "Breaking findings outside automatic application:"
    fix_manual_breaking_summary
}

fix_reaudit_after_failure() {
    echo
    echo "A safe repair step failed. Re-auditing the resulting state before stopping."
    set +e
    run_audit
    set -e
}

run_fix() {
    echo "== Fix precondition audit =="
    run_audit || true

    [[ "$AUDIT_SOURCE_CURRENT" == "true" ]] \
        || fail "Fix requires clean Core/client checkouts matching their configured remote branches. Source is never pulled automatically."

    [[ "${AUDIT_CHECKOUT_ACTIVE:-unknown}" == "true" ]] \
        || fail "Fix requires the audited checkout to be the active CRM owner."

    fix_print_plan

    if [[ "$FIX_MODE" == "dry-run" ]]; then
        echo
        echo "Dry-run only. No prompts were opened and no deployment state was changed by fix."
        return 0
    fi

    if [[ "$FIX_MODE" == "interactive" ]]; then
        if fix_category_selected environment; then
            fix_review_nonbreaking_findings
        fi
        if ! fix_interactive_environment_reconciliation; then
            fix_reaudit_after_failure
            return 1
        fi
        echo
        echo "== Reconciled repair plan =="
        fix_print_plan
    fi

    if fix_category_selected schema && fix_schema_repairable; then
        if fix_should_apply_interactively "Apply the enabled schema/ledger repair shown above?"; then
            fix_confirm_mutation_once
            if ! fix_apply_schema; then
                fix_reaudit_after_failure
                return 1
            fi
        else
            echo "Skipped schema repair."
        fi
    fi

    if fix_category_selected runtime && (
        audit_has_breaking runtime.storage.write \
        || audit_has_breaking runtime.storage/logs.write \
        || audit_has_breaking runtime.bootstrap/cache.write
    ); then
        if fix_should_apply_interactively "Repair the proven runtime-directory write failure?"; then
            fix_confirm_mutation_once
            if ! fix_apply_runtime; then
                fix_reaudit_after_failure
                return 1
            fi
        else
            echo "Skipped runtime-directory repair."
        fi
    fi

    if fix_category_selected nginx; then
        local -a nginx_hosts=()
        mapfile -t nginx_hosts < <(fix_safe_nginx_missing_hosts)
        local host
        for host in "${nginx_hosts[@]}"; do
            if fix_should_apply_interactively "Create the supplemental Nginx/TLS host [$host]?"; then
                fix_confirm_mutation_once
                if ! fix_apply_nginx_host "$host"; then
                    fix_reaudit_after_failure
                    return 1
                fi
            else
                echo "Skipped Nginx/TLS repair for [$host]."
            fi
        done
    fi

    local runtime_ready=false
    if fix_application_ready && fix_runtime_directories_ready; then
        runtime_ready=true
    fi

    if [[ "$runtime_ready" == "true" ]]; then
        if fix_category_selected horizon && (
            audit_has_breaking horizon.process || audit_has_breaking horizon.status
        ); then
            if fix_should_apply_interactively "Start/restart Horizon through the existing checkout-owned Supervisor program?"; then
                fix_confirm_mutation_once
                if ! fix_apply_horizon; then
                    fix_reaudit_after_failure
                    return 1
                fi
            else
                echo "Skipped Horizon repair."
            fi
        fi

        if fix_category_selected scheduler && audit_has_breaking scheduler.cron; then
            if fix_should_apply_interactively "Install the missing Laravel Scheduler cron entry?"; then
                fix_confirm_mutation_once
                if ! fix_apply_scheduler; then
                    fix_reaudit_after_failure
                    return 1
                fi
            else
                echo "Skipped Scheduler repair."
            fi
        fi
    else
        if fix_category_selected scheduler && audit_has_breaking scheduler.cron; then
            echo
            echo "Deferred Scheduler repair: deployment plan/setup validation or effective runtime-directory access is not green yet."
        fi
        if fix_category_selected horizon && (
            audit_has_breaking horizon.process || audit_has_breaking horizon.status
        ); then
            echo "Deferred Horizon repair: deployment plan/setup validation or effective runtime-directory access is not green yet."
        fi
    fi

    echo
    echo "== Mandatory post-fix audit =="
    run_audit || true

    if [[ "$AUDIT_BREAKING" -eq 0 ]]; then
        echo "Fix reconciliation completed and re-audit is clean."
        return 0
    fi

    echo "Fix reconciliation completed. Re-audit still reports findings that require another selected pass or operator/external resolution."
    return 1
}

normalize_fix_categories() {
    local raw="$1"
    python3 -c '
import sys
aliases = {
    "environment": "environment",
    "env": "environment",
    "provider": "environment",
    "providers": "environment",
    "schema": "schema",
    "runtime": "runtime",
    "nginx": "nginx",
    "tls": "nginx",
    "horizon": "horizon",
    "supervisor": "horizon",
    "scheduler": "scheduler",
    "cron": "scheduler",
}
raw = sys.argv[1]
selected = []
for item in raw.split(","):
    item = item.strip().lower()
    if not item:
        continue
    if item not in aliases:
        raise SystemExit(f"Unknown fix category [{item}].")
    canonical = aliases[item]
    if canonical not in selected:
        selected.append(canonical)
print(",".join(selected))
' "$raw"
}

parse_fix() {
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
    local mode="interactive"
    local only=""
    local all_safe=false

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
            --interactive) mode="interactive"; shift ;;
            --apply) mode="apply"; shift ;;
            --dry-run) mode="dry-run"; shift ;;
            --only) only=${2:?}; shift 2 ;;
            --all-safe) all_safe=true; shift ;;
            -h|--help) usage; return 0 ;;
            *) fail "Unknown fix argument: $1" ;;
        esac
    done

    [[ "$environment" == "staging" || "$environment" == "production" ]] \
        || fail "fix requires --environment staging|production."
    [[ -n "$client_key" ]] || fail "fix requires --client-key."
    [[ -n "$root_domain" ]] || fail "fix requires --root-domain."
    [[ "$client_key" =~ ^[a-z0-9][a-z0-9_-]*$ ]] || fail "Invalid --client-key [$client_key]."
    [[ -z "$only" || "$all_safe" == "false" ]] \
        || fail "Use either --only or --all-safe, not both."

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

    FIX_MODE="$mode"
    FIX_PRODUCTION_CONFIRMED="false"
    if [[ -n "$only" ]]; then
        FIX_CATEGORIES="$(normalize_fix_categories "$only")" \
            || fail "Invalid --only category list [$only]."
        [[ -n "$FIX_CATEGORIES" ]] || fail "--only must select at least one fix category."
    elif [[ "$all_safe" == "true" ]]; then
        FIX_CATEGORIES="schema,runtime,nginx,horizon,scheduler"
    else
        FIX_CATEGORIES="environment,schema,runtime,nginx,horizon,scheduler"
    fi

    run_fix
}