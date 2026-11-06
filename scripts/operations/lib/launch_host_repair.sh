#!/usr/bin/env bash
# Existing host-mutating repair routines, isolated ahead of Ansible replacement.
# Do not invoke these against the new Ansible-provisioned staging/production hosts.
# Loaded as function declarations; nothing runs until fix dispatch.

fix_runtime_plan() {
    echo "  [runtime] chown $DEPLOY_USER:$WEB_GROUP under $APP_PATH/storage and $APP_PATH/bootstrap/cache"
    echo "            directories -> 2775; files -> 0664; then verify deploy/web effective writes."
}

fix_apply_runtime() {
    note "Fix: runtime directory permissions"

    cd "$APP_PATH" || return 1
    sudo chown -R "$DEPLOY_USER:$WEB_GROUP" storage bootstrap/cache || return 1
    sudo find storage bootstrap/cache -type d -exec chmod 2775 {} \; || return 1
    sudo find storage bootstrap/cache -type f -exec chmod 0664 {} \; || return 1

    local log_dir="$APP_PATH/storage/logs"
    local deploy_probe="$log_dir/.engage-permission-deploy-fix-$$"
    local web_probe="$log_dir/.engage-permission-web-fix-$$"

    if ! sudo -u "$DEPLOY_USER" sh -c 'printf "deploy-created\n" > "$1"' sh "$deploy_probe"; then
        sudo rm -f "$deploy_probe" "$web_probe" || true
        return 1
    fi
    if ! sudo -u "$WEB_USER" sh -c 'printf "web-updated\n" >> "$1"' sh "$deploy_probe"; then
        sudo rm -f "$deploy_probe" "$web_probe" || true
        return 1
    fi
    if ! sudo -u "$WEB_USER" sh -c 'printf "web-created\n" > "$1"' sh "$web_probe"; then
        sudo rm -f "$deploy_probe" "$web_probe" || true
        return 1
    fi

    sudo rm -f "$deploy_probe" "$web_probe" || return 1
}

fix_host_dns_ready() {
    local host="$1"
    local expected="$AUDIT_SERVER_IP"

    if [[ -z "$expected" ]] && command -v curl >/dev/null 2>&1; then
        expected="$(curl -fsS --max-time 4 https://api.ipify.org 2>/dev/null || true)"
        [[ "$expected" =~ ^([0-9]{1,3}\.){3}[0-9]{1,3}$ ]] || expected=""
    fi
    [[ -n "$expected" ]] || return 1
    dns_host_ready "$host" "$expected"
}

fix_supplemental_nginx_id() {
    local host="$1"
    printf 'engage-core-%s' "${host//./-}"
}

fix_render_supplemental_nginx() {
    local host="$1"
    local cert_name="$2"
    local destination="$3"
    local tls_enabled="$4"
    local id access_log error_log
    id="$(fix_supplemental_nginx_id "$host")"
    access_log="/var/log/nginx/${id}-access.log"
    error_log="/var/log/nginx/${id}-error.log"

    local log_format_suffix=""
    local request_header=""
    local fastcgi_request_id=""
    if [[ -f /etc/nginx/conf.d/00-engage-core-observability.conf ]]; then
        log_format_suffix=" engage_core_json"
    fi
    if [[ -f /etc/nginx/snippets/engage-core-request-id-fastcgi.conf ]]; then
        request_header='    add_header X-Request-ID $request_id always;'
        fastcgi_request_id='        include /etc/nginx/snippets/engage-core-request-id-fastcgi.conf;'
    fi

    {
        echo "# Managed by Engage Core deployment fix: supplemental Core host."
        echo "server {"
        echo "    listen 80;"
        echo "    listen [::]:80;"
        echo "    server_name ${host};"
        echo "    root ${APP_PATH}/public;"
        echo "    index index.php;"
        echo "    charset utf-8;"
        [[ -n "$request_header" ]] && echo "$request_header"
        echo
        echo "    access_log ${access_log}${log_format_suffix};"
        echo "    error_log ${error_log};"
        echo
        echo "    location ^~ /.well-known/acme-challenge/ {"
        echo '        try_files $uri =404;'
        echo "    }"
        echo

        if [[ "$tls_enabled" == "true" ]]; then
            echo "    location / {"
            echo '        return 301 https://$host$request_uri;'
            echo "    }"
        else
            echo "    location / {"
            echo '        try_files $uri $uri/ /index.php?$query_string;'
            echo "    }"
            echo
            echo '    location ~ \.php$ {'
            echo "        include snippets/fastcgi-php.conf;"
            [[ -n "$fastcgi_request_id" ]] && echo "$fastcgi_request_id"
            echo "        fastcgi_pass unix:${PHP_FPM_SOCKET};"
            echo "    }"
            echo
            echo '    location ~ /\.(?!well-known).* {'
            echo "        deny all;"
            echo "    }"
        fi
        echo "}"

        if [[ "$tls_enabled" == "true" ]]; then
            cat <<EOF

server {
    listen 443 ssl http2;
    listen [::]:443 ssl http2;
    server_name ${host};
    root ${APP_PATH}/public;
    index index.php;
    charset utf-8;
${request_header}

    ssl_certificate /etc/letsencrypt/live/${cert_name}/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/${cert_name}/privkey.pem;
    ssl_protocols TLSv1.2 TLSv1.3;
    ssl_session_cache shared:SSL:10m;
    ssl_session_timeout 1d;

    access_log ${access_log}${log_format_suffix};
    error_log ${error_log};

    location / {
        try_files \$uri \$uri/ /index.php?\$query_string;
    }

    location = /favicon.ico { access_log off; log_not_found off; }
    location = /robots.txt  { access_log off; log_not_found off; }

    location ~ \.php\$ {
        include snippets/fastcgi-php.conf;
${fastcgi_request_id}
        fastcgi_pass unix:${PHP_FPM_SOCKET};
    }

    location ~ /\.(?!well-known).* {
        deny all;
    }
}
EOF
        fi
    } > "$destination"
}

fix_nginx_plan() {
    local -a hosts=()
    mapfile -t hosts < <(fix_safe_nginx_missing_hosts)
    local host id

    for host in "${hosts[@]}"; do
        id="$(fix_supplemental_nginx_id "$host")"
        echo "  [nginx] Add missing Core host [$host] without rewriting the existing shared/legacy site."
        echo "          Write/enable: /etc/nginx/sites-available/$id -> /etc/nginx/sites-enabled/$id"
        echo "          Validate/reload Nginx, issue dedicated Certbot certificate [$id] for [$host], render HTTPS, validate/reload again."
    done
}

fix_install_certbot_reload_hook() {
    local hook='/etc/letsencrypt/renewal-hooks/deploy/engage-nginx-reload.sh'
    local tmp
    tmp="$(mktemp)" || return 1

    if ! cat > "$tmp" <<'EOF'
#!/usr/bin/env bash
set -euo pipefail
nginx -t
systemctl reload nginx
EOF
    then
        rm -f "$tmp"
        return 1
    fi

    if ! sudo install -d -o root -g root -m 0755 /etc/letsencrypt/renewal-hooks/deploy; then
        rm -f "$tmp"
        return 1
    fi
    if ! sudo install -o root -g root -m 0755 "$tmp" "$hook"; then
        rm -f "$tmp"
        return 1
    fi
    rm -f "$tmp"
}

fix_apply_nginx_host() {
    local host="$1"
    local id available enabled cert_name tmp
    id="$(fix_supplemental_nginx_id "$host")"
    available="/etc/nginx/sites-available/$id"
    enabled="/etc/nginx/sites-enabled/$id"
    cert_name="$id"

    local owners_json count
    owners_json="$(audit_nginx_owners_json "$host")"
    count="$(printf '%s' "$owners_json" | python3 -c 'import json,sys; print(len(json.load(sys.stdin)))' 2>/dev/null || printf '0')"
    if [[ "$count" -ne 0 ]]; then
        echo "ERROR: Refusing Nginx auto-repair for [$host]: an application-serving owner now exists. Re-audit first." >&2
        return 1
    fi

    if [[ -f "$available" ]] && ! grep -Fq '# Managed by Engage Core deployment fix: supplemental Core host.' "$available"; then
        echo "ERROR: Refusing to overwrite unmanaged Nginx config [$available]." >&2
        return 1
    fi

    if ! fix_host_dns_ready "$host"; then
        echo "ERROR: Refusing TLS repair for [$host]: DNS is not proven to resolve directly to this server." >&2
        return 1
    fi

    if ! command -v certbot >/dev/null 2>&1; then
        echo "ERROR: Certbot is not installed; automatic TLS repair is unavailable." >&2
        return 1
    fi

    note "Fix: Nginx/TLS supplemental host $host"

    tmp="$(mktemp)" || return 1
    if ! fix_render_supplemental_nginx "$host" "$cert_name" "$tmp" false; then
        rm -f "$tmp"
        return 1
    fi
    if ! sudo install -o root -g root -m 0644 "$tmp" "$available"; then
        rm -f "$tmp"
        return 1
    fi
    rm -f "$tmp"

    sudo ln -sfn "$available" "$enabled" || return 1
    sudo nginx -t || return 1
    sudo systemctl reload nginx || return 1

    fix_install_certbot_reload_hook || return 1
    sudo certbot certonly --webroot \
        -w "$APP_PATH/public" \
        --non-interactive \
        --agree-tos \
        --keep-until-expiring \
        --cert-name "$cert_name" \
        -d "$host" || return 1

    tmp="$(mktemp)" || return 1
    if ! fix_render_supplemental_nginx "$host" "$cert_name" "$tmp" true; then
        rm -f "$tmp"
        return 1
    fi
    if ! sudo install -o root -g root -m 0644 "$tmp" "$available"; then
        rm -f "$tmp"
        return 1
    fi
    rm -f "$tmp"

    sudo nginx -t || return 1
    sudo systemctl reload nginx || return 1
}

fix_existing_supervisor_config() {
    local canonical="/etc/supervisor/conf.d/${HORIZON_PROGRAM}.conf"
    local -a candidates=()
    local path

    if [[ -f "$canonical" ]] && grep -Fq "$APP_PATH/artisan horizon" "$canonical" 2>/dev/null; then
        printf '%s\n' "$canonical"
        return 0
    fi

    for path in /etc/supervisor/conf.d/*.conf; do
        [[ -f "$path" ]] || continue
        if grep -Fq "$APP_PATH/artisan horizon" "$path" 2>/dev/null; then
            candidates+=("$path")
        fi
    done

    [[ ${#candidates[@]} -eq 1 ]] || return 1
    printf '%s\n' "${candidates[0]}"
}

fix_horizon_plan() {
    local config program
    config="$(fix_existing_supervisor_config 2>/dev/null || true)"
    if [[ -n "$config" ]]; then
        program="$(sed -n 's/^\[program:\([^]]*\)\].*/\1/p' "$config" | head -n 1)"
        echo "  [horizon] After application readiness is green: supervisorctl reread/update/restart [${program:-unknown}] using existing [$config]."
    else
        echo "  [horizon] No single existing Supervisor config can be safely selected; automatic Horizon repair is not currently eligible."
    fi
}

fix_apply_horizon() {
    local config program
    config="$(fix_existing_supervisor_config 2>/dev/null || true)"
    if [[ -z "$config" ]]; then
        echo "ERROR: No single existing Supervisor config points at $APP_PATH; refusing to create/replace process-manager ownership automatically." >&2
        return 1
    fi

    program="$(sed -n 's/^\[program:\([^]]*\)\].*/\1/p' "$config" | head -n 1)"
    if [[ -z "$program" ]]; then
        echo "ERROR: Supervisor config [$config] does not declare a program name." >&2
        return 1
    fi

    note "Fix: Horizon via existing Supervisor program $program"
    sudo supervisorctl reread || return 1
    sudo supervisorctl update || return 1
    sudo supervisorctl restart "$program" || return 1
    sleep 1
    sudo supervisorctl status "$program" || return 1
    ps aux | grep '[a]rtisan horizon' | grep -F "$APP_PATH" >/dev/null \
        || return 1
}

fix_scheduler_plan() {
    echo "  [scheduler] After application readiness is green, install for [$SCHEDULER_USER]:"
    echo "              * * * * * cd ${APP_PATH} && ${PHP_BIN} artisan schedule:run >> /dev/null 2>&1 # ${SCHEDULER_MARKER}"
}

fix_apply_scheduler() {
    note "Fix: Laravel Scheduler"

    local line="* * * * * cd ${APP_PATH} && ${PHP_BIN} artisan schedule:run >> /dev/null 2>&1 # ${SCHEDULER_MARKER}"
    local tmp
    tmp="$(mktemp)" || return 1

    if ! sudo crontab -u "$SCHEDULER_USER" -l 2>/dev/null \
        | grep -Fv "# ${SCHEDULER_MARKER}" > "$tmp"
    then
        : > "$tmp"
    fi

    printf '%s\n' "$line" >> "$tmp" || {
        rm -f "$tmp"
        return 1
    }

    if ! sudo crontab -u "$SCHEDULER_USER" "$tmp"; then
        rm -f "$tmp"
        return 1
    fi
    rm -f "$tmp"

    sudo crontab -u "$SCHEDULER_USER" -l | grep -F "# ${SCHEDULER_MARKER}" || return 1
    (
        cd "$APP_PATH" || exit 1
        "$PHP_BIN" artisan schedule:list
    ) || return 1
}