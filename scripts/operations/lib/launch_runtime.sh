#!/usr/bin/env bash
# Runtime activation (legacy privileged host operations pending Ansible replacement).
# Functions are declarations only; sourcing this library never mutates the host.
# Not a supported legacy compatibility path for new staging/production servers.

runtime_permission_setup() {
    note "Runtime directory permissions"
    cd "$APP_PATH"
    sudo chown -R "$DEPLOY_USER:$WEB_GROUP" storage bootstrap/cache
    sudo find storage bootstrap/cache -type d -exec chmod 2775 {} \;
    sudo find storage bootstrap/cache -type f -exec chmod 0664 {} \;

    local log_dir="$APP_PATH/storage/logs"
    local deploy_probe="$log_dir/.engage-permission-deploy-$$"
    local web_probe="$log_dir/.engage-permission-web-$$"

    sudo -u "$DEPLOY_USER" sh -c 'printf "deploy-created\\n" > "$1"' sh "$deploy_probe"
    sudo -u "$WEB_USER" sh -c 'printf "web-updated\\n" >> "$1"' sh "$deploy_probe"
    sudo -u "$WEB_USER" sh -c 'printf "web-created\\n" > "$1"' sh "$web_probe"

    sudo rm -f "$deploy_probe" "$web_probe"
}

install_supervisor_program() {
    note "Supervisor / Horizon"
    require_command supervisorctl
    local conf="/etc/supervisor/conf.d/${HORIZON_PROGRAM}.conf"
    local tmp
    tmp="$(mktemp)"
    cat > "$tmp" <<EOF
[program:${HORIZON_PROGRAM}]
process_name=%(program_name)s
command=${PHP_BIN} ${APP_PATH}/artisan horizon
directory=${APP_PATH}
autostart=true
autorestart=true
user=${WEB_USER}
redirect_stderr=true
stdout_logfile=${APP_PATH}/storage/logs/horizon.log
stopwaitsecs=3600
EOF
    sudo install -o root -g root -m 0644 "$tmp" "$conf"
    rm -f "$tmp"
    sudo supervisorctl reread
    sudo supervisorctl update
    sudo supervisorctl restart "$HORIZON_PROGRAM" >/dev/null 2>&1 || true
    sleep 1
    sudo supervisorctl status "$HORIZON_PROGRAM"
    ps aux | grep '[a]rtisan horizon' | grep -F "$APP_PATH" >/dev/null \
        || fail "Horizon process for $APP_PATH was not found."
}

install_scheduler_cron() {
    note "Laravel Scheduler"
    require_command crontab
    local line="* * * * * cd ${APP_PATH} && ${PHP_BIN} artisan schedule:run >> /dev/null 2>&1 # ${SCHEDULER_MARKER}"
    local tmp
    tmp="$(mktemp)"
    sudo crontab -u "$SCHEDULER_USER" -l 2>/dev/null \
        | grep -Fv "# ${SCHEDULER_MARKER}" > "$tmp" || true
    printf '%s\n' "$line" >> "$tmp"
    sudo crontab -u "$SCHEDULER_USER" "$tmp"
    rm -f "$tmp"
    sudo crontab -u "$SCHEDULER_USER" -l | grep -F "# ${SCHEDULER_MARKER}"
    cd "$APP_PATH"
    "$PHP_BIN" artisan schedule:list
}

ensure_runtime() {
    if phase_done runtime; then
        return
    fi

    identity_preflight
    ensure_env_permissions
    runtime_permission_setup
    install_supervisor_program
    install_scheduler_cron

    note "Reload PHP-FPM"
    sudo systemctl reload "$PHP_FPM_SERVICE"
    cd "$APP_PATH"
    "$PHP_BIN" artisan horizon:status
    mark_phase runtime
}