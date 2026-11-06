#!/usr/bin/env bash
# Application-owned deployment plan, schema, and selected-module operations.
# Functions run only when invoked by the entry-point workflow.

write_plan_json() {
    cd "$APP_PATH"
    set +e
    "$PHP_BIN" artisan engage:deployment-plan --json > "$PLAN_FILE"
    local status=$?
    set -e
    [[ -s "$PLAN_FILE" ]] || fail "engage:deployment-plan --json produced no JSON."
    python3 -m json.tool "$PLAN_FILE" >/dev/null || fail "Deployment-plan JSON is invalid."
    return "$status"
}

module_enabled() {
    local target="$1"
    python3 "$HELPER" plan-modules --plan "$PLAN_FILE" | grep -Fxq "$target"
}

prepare_host_plan() {
    write_plan_json || true
    maybe_configure_optional_surfaces
    write_plan_json || true
}

maybe_configure_optional_surfaces() {
    if module_enabled scheduling && [[ -z "$(env_get "$CLIENT_ENV" SCHEDULING_APP_URL)" ]]; then
        local asked
        asked="$(state_get optional_scheduling_decided 2>/dev/null || true)"
        if [[ "$asked" != "true" ]]; then
            if prompt_yes_no "Scheduling is enabled. Expose the generic public booking surface at https://$SCHEDULING_DEFAULT_HOST ?" no; then
                env_set "$CLIENT_ENV" SCHEDULING_APP_URL "https://$SCHEDULING_DEFAULT_HOST"
            fi
            state_set optional_scheduling_decided true
            cd "$APP_PATH"
            "$PHP_BIN" artisan optimize:clear
        fi
    fi
}

resolve_deployment_plan() {
    local force="${1:-false}"
    if [[ "$force" != "true" ]] && phase_done deployment_plan; then
        write_plan_json || true
        return
    fi

    note "Application-owned deployment plan"
    cd "$APP_PATH"
    ensure_env_permissions
    validate_client_env_contract

    local pass changed setup blocking
    for pass in $(seq 1 20); do
        write_plan_json || true
        maybe_configure_optional_surfaces
        write_plan_json || true

        "$PHP_BIN" artisan engage:environment:sync --write-missing
        ensure_env_permissions
        "$PHP_BIN" artisan optimize:clear
        write_plan_json || true

        local result_file
        result_file="$(mktemp)"
        python3 "$HELPER" resolve-requirements \
            --plan "$PLAN_FILE" \
            --root-env "$ROOT_ENV" \
            --client-env "$CLIENT_ENV" \
            --mode non-setup \
            --result-file "$result_file"
        changed="$(cat "$result_file")"
        rm -f "$result_file"

        if [[ "$changed" != "0" ]]; then
            ensure_env_permissions
            "$PHP_BIN" artisan optimize:clear
            continue
        fi

        result_file="$(mktemp)"
        python3 "$HELPER" run-setup-steps \
            --plan "$PLAN_FILE" \
            --root-env "$ROOT_ENV" \
            --client-env "$CLIENT_ENV" \
            --state "$STATE_FILE" \
            --result-file "$result_file"
        setup="$(cat "$result_file")"
        rm -f "$result_file"

        if [[ "$setup" != "0" ]]; then
            ensure_env_permissions
            "$PHP_BIN" artisan optimize:clear
            continue
        fi

        result_file="$(mktemp)"
        python3 "$HELPER" resolve-requirements \
            --plan "$PLAN_FILE" \
            --root-env "$ROOT_ENV" \
            --client-env "$CLIENT_ENV" \
            --mode all \
            --result-file "$result_file"
        changed="$(cat "$result_file")"
        rm -f "$result_file"

        if [[ "$changed" != "0" ]]; then
            ensure_env_permissions
            "$PHP_BIN" artisan optimize:clear
            continue
        fi

        write_plan_json || true
        blocking="$(python3 "$HELPER" plan-blocking-count --plan "$PLAN_FILE")"
        if [[ "$blocking" == "0" ]]; then
            mark_phase deployment_plan
            "$PHP_BIN" artisan engage:deployment-plan
            return
        fi
    done

    fail "Deployment-plan resolution did not converge after 20 passes. Inspect $PLAN_FILE and php artisan engage:deployment-plan --verbose."
}

verify_database_connection() {
    note "Database connectivity and identity"
    cd "$APP_PATH"
    "$PHP_BIN" artisan tinker --execute="dump([
        'host' => config('database.connections.mysql.host'),
        'database' => DB::connection()->getDatabaseName(),
        'connected' => DB::connection()->getPdo() !== null,
    ]);"
}

install_new_environment() {
    if phase_done application_install; then
        return
    fi

    note "Application installation"
    cd "$APP_PATH"
    verify_database_connection
    "$PHP_BIN" artisan engage:install --force --no-create-user
    "$PHP_BIN" artisan modules:status
    "$PHP_BIN" artisan setup:validate

    if prompt_yes_no "Create the initial CRM owner/login now?" yes; then
        "$PHP_BIN" artisan engage:user:add
    fi

    mark_phase application_install
}

update_application() {
    note "Normal application deployment"
    cd "$APP_PATH"
    verify_database_connection
    "$PHP_BIN" artisan migrate --force
    "$PHP_BIN" artisan modules:preflight
    "$PHP_BIN" artisan modules:migrate --force
    "$PHP_BIN" artisan presets:sync
    "$PHP_BIN" artisan modules:status
    "$PHP_BIN" artisan setup:validate
}

install_added_modules() {
    local module
    [[ ${#REQUESTED_MODULES[@]} -gt 0 ]] || fail "add-modules requires at least one --module MODULE."

    note "Install newly enabled module schema"
    cd "$APP_PATH"
    verify_database_connection
    for module in "${REQUESTED_MODULES[@]}"; do
        if ! module_enabled "$module"; then
            fail "Requested module [$module] is not enabled by the pulled client configuration. Enable/test/commit it in development first."
        fi
        "$PHP_BIN" artisan modules:preflight "$module"
        "$PHP_BIN" artisan modules:install "$module" --force
    done
    "$PHP_BIN" artisan presets:sync
    "$PHP_BIN" artisan modules:status
    "$PHP_BIN" artisan setup:validate
}