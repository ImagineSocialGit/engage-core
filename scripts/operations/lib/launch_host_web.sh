#!/usr/bin/env bash
# Legacy Nginx, DNS and TLS host operations, isolated for Ansible replacement.
# This library only defines functions; sourcing never changes host configuration.
# Ansible must own privileged Nginx/certificate management on new infrastructure.

core_hosts() {
    local -a hosts=("$CRM_HOST")

    if module_enabled messaging; then
        hosts+=("$MESSAGING_HOST")
    fi
    if module_enabled webinars; then
        hosts+=("$WEBINAR_HOST")
    fi
    if module_enabled messaging || module_enabled inbound_messaging || module_enabled webinars || module_enabled forms; then
        hosts+=("$WEBHOOKS_HOST")
    fi

    local forms_url forms_host
    if module_enabled forms; then
        forms_url="$(env_get "$CLIENT_ENV" FORMS_APP_URL)"
        if [[ -n "$forms_url" ]]; then
            forms_host="$(python3 "$HELPER" origin-host --origin "$forms_url")"
            [[ -n "$forms_host" ]] && hosts+=("$forms_host")
        fi
    fi

    local scheduling_url scheduling_host
    scheduling_url="$(env_get "$CLIENT_ENV" SCHEDULING_APP_URL)"
    if [[ -n "$scheduling_url" ]]; then
        scheduling_host="$(python3 "$HELPER" origin-host --origin "$scheduling_url")"
        [[ -n "$scheduling_host" ]] && hosts+=("$scheduling_host")
    fi

    printf '%s\n' "${hosts[@]}" | awk 'NF && !seen[$0]++'
}

current_core_hosts_json() {
    core_hosts | python3 -c 'import json,sys; print(json.dumps([line.strip() for line in sys.stdin if line.strip()]))'
}

json_array_lines() {
    python3 -c 'import json,sys; value=sys.stdin.read().strip(); data=json.loads(value) if value else []; [print(str(item)) for item in data if str(item)]'
}

stored_tls_hosts() {
    local value
    value="$(state_get tls_core_hosts 2>/dev/null || true)"
    [[ -n "$value" ]] || return 0
    printf '%s' "$value" | json_array_lines
}

render_nginx_site_config() {
    local available="$1"
    local access_log="$2"
    local error_log="$3"

    local -a desired=() tls=() served=() pending=()
    mapfile -t desired < <(core_hosts)
    mapfile -t tls < <(stored_tls_hosts)

    local host
    for host in "${desired[@]}"; do
        if printf '%s\n' "${tls[@]}" | grep -Fxq "$host"; then
            served+=("$host")
        else
            pending+=("$host")
        fi
    done

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

    local tmp
    tmp="$(mktemp)"
    : > "$tmp"

    if [[ ${#served[@]} -gt 0 ]]; then
        cat >> "$tmp" <<EOF
server {
    listen 80;
    listen [::]:80;
    server_name ${served[*]};

    location ^~ /.well-known/acme-challenge/ {
        root ${APP_PATH}/public;
        try_files \$uri =404;
    }

    location / {
        return 301 https://\$host\$request_uri;
    }
}

EOF
    fi

    if [[ ${#pending[@]} -gt 0 ]]; then
        cat >> "$tmp" <<EOF
server {
    listen 80;
    listen [::]:80;
    server_name ${pending[*]};
    root ${APP_PATH}/public;
    index index.php;
    charset utf-8;
${request_header}

    access_log ${access_log}${log_format_suffix};
    error_log ${error_log};

    location ^~ /.well-known/acme-challenge/ {
        try_files \$uri =404;
    }

    location / {
        try_files \$uri \$uri/ /index.php?\$query_string;
    }

    location = /favicon.ico { access_log off; log_not_found off; }
    location = /robots.txt  { access_log off; log_not_found off; }

    location ~ \.php$ {
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

    if [[ ${#served[@]} -gt 0 ]]; then
        cat >> "$tmp" <<EOF
server {
    listen 443 ssl http2;
    listen [::]:443 ssl http2;
    server_name ${served[*]};
    root ${APP_PATH}/public;
    index index.php;
    charset utf-8;
${request_header}

    ssl_certificate /etc/letsencrypt/live/${NGINX_SITE_NAME}/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/${NGINX_SITE_NAME}/privkey.pem;
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

    location ~ \.php$ {
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

    sudo install -o root -g root -m 0644 "$tmp" "$available"
    rm -f "$tmp"
}

install_nginx_site() {
    local desired_hosts_json previous_hosts_json first_install=false
    desired_hosts_json="$(current_core_hosts_json)"
    previous_hosts_json="$(state_get core_hosts 2>/dev/null || true)"
    if phase_done nginx && [[ "$previous_hosts_json" == "$desired_hosts_json" ]]; then
        return
    fi
    [[ -z "$previous_hosts_json" ]] && first_install=true

    note "Nginx Core-owned hosts"
    require_command nginx
    [[ -S "$PHP_FPM_SOCKET" ]] || fail "PHP-FPM socket not found: $PHP_FPM_SOCKET"

    local -a hosts
    mapfile -t hosts < <(core_hosts)
    [[ ${#hosts[@]} -gt 0 ]] || fail "No Core-owned hosts resolved."
    printf 'Core will own:\n'
    printf '  - %s\n' "${hosts[@]}"

    if [[ "$TOPOLOGY" == "core_services_only" ]]; then
        echo "Root site $ROOT_DOMAIN is external and will NOT be changed by this launcher."
    else
        echo "Root site $ROOT_DOMAIN is reserved for managed main-site type [$MAIN_SITE_TYPE] and will NOT be pointed at Core."
    fi

    local available="/etc/nginx/sites-available/$NGINX_SITE_NAME"
    local enabled="/etc/nginx/sites-enabled/$NGINX_SITE_NAME"
    local access_log="/var/log/nginx/${NGINX_SITE_NAME}-access.log"
    local error_log="/var/log/nginx/${NGINX_SITE_NAME}-error.log"

    render_nginx_site_config "$available" "$access_log" "$error_log"
    sudo ln -sfn "$available" "$enabled"
    sudo nginx -t
    sudo systemctl reload nginx

    local pool_config="/etc/php/${PHP_VERSION}/fpm/pool.d/www.conf"
    if [[ "$first_install" == "true" && -f "$APP_PATH/scripts/operations/install-observability.sh" && -f "$pool_config" ]]; then
        note "Install/refresh Core observability integration"
        sudo bash "$APP_PATH/scripts/operations/install-observability.sh" \
            --client-key "$CLIENT_KEY" \
            --app-path "$APP_PATH" \
            --nginx-site "$available" \
            --access-log "$access_log" \
            --php-version "$PHP_VERSION" \
            --php-pool-config "$pool_config" \
            --deploy-user "$DEPLOY_USER" \
            --apply
    fi

    state_set nginx_site_path "$available"
    state_set access_log_path "$access_log"
    state_set_json core_hosts "$desired_hosts_json"
    mark_phase nginx
}

server_public_ip() {
    local current
    current="$(state_get server_public_ip 2>/dev/null || true)"
    if [[ -n "$current" ]]; then
        printf '%s' "$current"
        return
    fi

    local guess
    guess="$(hostname -I 2>/dev/null | awk '{print $1}')"
    current="$(prompt_default "Public IPv4 address DNS should point to" "${guess:-127.0.0.1}")"
    state_set server_public_ip "$current"
    printf '%s' "$current"
}

dns_host_ready() {
    local host="$1"
    local expected="$2"
    getent ahostsv4 "$host" 2>/dev/null | awk '{print $1}' | sort -u | grep -Fxq "$expected"
}

ensure_certbot() {
    if command -v certbot >/dev/null 2>&1; then
        return
    fi

    if prompt_yes_no "Certbot is not installed. Install certbot now?" yes; then
        sudo apt-get update
        sudo apt-get install -y certbot
    else
        fail "Install Certbot and resume when ready."
    fi
}

install_certbot_reload_hook() {
    local hook='/etc/letsencrypt/renewal-hooks/deploy/engage-nginx-reload.sh'
    local tmp
    tmp="$(mktemp)"
    cat > "$tmp" <<'EOF'
#!/usr/bin/env bash
set -euo pipefail
nginx -t
systemctl reload nginx
EOF
    sudo install -d -o root -g root -m 0755 /etc/letsencrypt/renewal-hooks/deploy
    sudo install -o root -g root -m 0755 "$tmp" "$hook"
    rm -f "$tmp"
}

ensure_dns_and_tls() {
    local desired_hosts_json tls_hosts_json
    desired_hosts_json="$(current_core_hosts_json)"
    tls_hosts_json="$(state_get tls_core_hosts 2>/dev/null || true)"
    if phase_done dns_tls && [[ "$tls_hosts_json" == "$desired_hosts_json" ]]; then
        return
    fi

    note "DNS gate"
    require_command getent
    local ip
    ip="$(server_public_ip)"
    local -a hosts old_tls_hosts
    mapfile -t hosts < <(core_hosts)
    mapfile -t old_tls_hosts < <(stored_tls_hosts)

    echo "Create/verify these DNS records in the client's DNS provider:"
    local host
    for host in "${hosts[@]}"; do
        printf '  A  %-45s -> %s\n' "$host" "$ip"
    done
    echo
    echo "Do not change $ROOT_DOMAIN here; its owner is defined by topology [$TOPOLOGY]."

    while true; do
        local missing=0
        for host in "${hosts[@]}"; do
            if dns_host_ready "$host" "$ip"; then
                echo "DNS ready: $host -> $ip"
            else
                echo "DNS NOT ready: $host does not currently resolve to $ip"
                missing=1
            fi
        done
        [[ "$missing" == "0" ]] && break
        echo "Press Enter to check DNS again, or Ctrl+C to stop and resume later."
        read -r
    done

    note "TLS certificates"
    ensure_certbot
    install_certbot_reload_hook

    local email
    email="$(state_get certbot_email 2>/dev/null || true)"
    if [[ -z "$email" ]]; then
        email="$(prompt_required "Email address for Let's Encrypt/Certbot notices")"
        state_set certbot_email "$email"
    fi

    local -a cert_args=()
    for host in "${hosts[@]}"; do
        cert_args+=( -d "$host" )
    done

    local mode_arg='--keep-until-expiring'
    if [[ ${#old_tls_hosts[@]} -gt 0 ]]; then
        local old_host all_old_present=true
        for old_host in "${old_tls_hosts[@]}"; do
            if ! printf '%s\n' "${hosts[@]}" | grep -Fxq "$old_host"; then
                all_old_present=false
                break
            fi
        done
        if [[ "$all_old_present" == "true" ]]; then
            mode_arg='--expand'
        else
            mode_arg='--force-renewal'
        fi
    fi

    sudo certbot certonly --webroot \
        -w "$APP_PATH/public" \
        --non-interactive \
        --agree-tos \
        --cert-name "$NGINX_SITE_NAME" \
        "$mode_arg" \
        -m "$email" \
        "${cert_args[@]}"

    state_set_json tls_core_hosts "$desired_hosts_json"
    local available access_log error_log
    available="$(state_get nginx_site_path)"
    access_log="$(state_get access_log_path)"
    error_log="/var/log/nginx/${NGINX_SITE_NAME}-error.log"
    render_nginx_site_config "$available" "$access_log" "$error_log"
    sudo nginx -t
    sudo systemctl reload nginx
    mark_phase dns_tls
}