#!/usr/bin/env bash
# Deployment verification, separate from application setup and host activation.
# Functions are declarations only; no checks are executed on import.
# Current Supervisor/HTTP probes will be reconciled with Ansible's runtime contract.

http_status() {
    curl -sS -o /dev/null -w '%{http_code}' "$1"
}

verify_runtime() {
    note "Final Core verification"
    require_command curl
    cd "$APP_PATH"
    ensure_env_permissions
    validate_client_env_contract

    # Resolve directly through a pipe. No deployment-plan JSON or state-file
    # write is allowed in verify, even when required values are missing.
    local plan_json blocking
    plan_json="$("$PHP_BIN" artisan engage:deployment-plan --json)" || true
    [[ -n "$plan_json" ]] || fail "Read-only deployment-plan inspection returned no JSON."
    blocking="$(printf '%s' "$plan_json" | python3 "$HELPER" plan-blocking-count --plan -)" \
        || fail "Read-only deployment-plan JSON is invalid."
    [[ "$blocking" == "0" ]] || fail "Deployment plan has $blocking blocking environment requirement(s)."

    "$PHP_BIN" artisan modules:status
    "$PHP_BIN" artisan setup:validate
    sudo supervisorctl status "$HORIZON_PROGRAM"
    "$PHP_BIN" artisan horizon:status
    "$PHP_BIN" artisan schedule:list

    local admin_status
    admin_status="$(http_status "https://$CRM_HOST/")"
    case "$admin_status" in
        2??|3??|401|403) echo "CRM root HTTP $admin_status" ;;
        *) fail "CRM root returned HTTP $admin_status" ;;
    esac

    local login_status
    login_status="$(http_status "https://$CRM_HOST/login")"
    case "$login_status" in
        2??|3??|401|403) echo "CRM login HTTP $login_status" ;;
        *) fail "CRM login returned HTTP $login_status" ;;
    esac

    echo
    echo "External/provider verification remains intentionally human-observed where the provider dashboard or a real message/event is authoritative."
    echo "Review the verification items shown during each deployment setup step."
    echo "State file: $STATE_FILE"
    echo "Deployment plan: inspected in memory (no file written)"
}