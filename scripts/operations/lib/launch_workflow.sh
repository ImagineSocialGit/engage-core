#!/usr/bin/env bash
# Existing deployment workflow ordering; host operations are migrated to Ansible
# in a subsequent batch. Do not run against Ansible-managed hosts yet.

run_new_or_resume() {
    ensure_source
    load_state
    ensure_environment
    load_state
    prepare_host_plan
    install_nginx_site
    ensure_dns_and_tls
    resolve_deployment_plan
    prepare_host_plan
    install_nginx_site
    ensure_dns_and_tls
    install_new_environment
    ensure_runtime
    verify_runtime

    cat <<EOF

Deployment orchestration completed.
Client:      $CLIENT_KEY
Environment: $DEPLOY_ENV
Root domain: $ROOT_DOMAIN
Core path:   $APP_PATH
CRM:         https://$CRM_HOST
Horizon:     $HORIZON_PROGRAM

Main-site topology: $TOPOLOGY${MAIN_SITE_TYPE:+ ($MAIN_SITE_TYPE)}
The root site itself was not pointed at Core.
EOF
}

run_update() {
    ensure_source true
    load_state
    ensure_env_permissions
    prepare_host_plan
    install_nginx_site
    ensure_dns_and_tls
    resolve_deployment_plan true
    prepare_host_plan
    install_nginx_site
    ensure_dns_and_tls
    update_application
    runtime_permission_setup
    install_supervisor_program
    install_scheduler_cron
    sudo systemctl reload "$PHP_FPM_SERVICE"
    verify_runtime
}

run_add_modules() {
    ensure_source true
    load_state
    ensure_env_permissions
    prepare_host_plan
    install_nginx_site
    ensure_dns_and_tls
    resolve_deployment_plan true
    prepare_host_plan
    install_nginx_site
    ensure_dns_and_tls
    install_added_modules
    runtime_permission_setup
    install_supervisor_program
    install_scheduler_cron
    sudo systemctl reload "$PHP_FPM_SERVICE"
    verify_runtime
}