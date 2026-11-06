#!/usr/bin/env bash
# Legacy source checkout and dependency-build operations, pending Ansible handoff.
# This library only defines functions; sourcing never changes repositories.
# New staging/production source placement and Git checkout ownership belong to Ansible.

repo_must_be_clean() {
    local dir="$1"
    local label="$2"
    [[ -d "$dir/.git" ]] || fail "$label is not a Git repository: $dir"
    if [[ -n "$(git -C "$dir" status --porcelain)" ]]; then
        git -C "$dir" status --short >&2
        fail "$label checkout is dirty. Staging/production source must come from committed development changes."
    fi
}

repo_pull_ff() {
    local dir="$1"
    local label="$2"
    local branch="$3"

    repo_must_be_clean "$dir" "$label"
    local current
    current="$(git -C "$dir" branch --show-current)"
    [[ "$current" == "$branch" ]] || fail "$label is on branch [$current], expected [$branch]."
    git -C "$dir" fetch origin
    git -C "$dir" pull --ff-only origin "$branch"
    repo_must_be_clean "$dir" "$label"
    printf '%s revision: %s\n' "$label" "$(git -C "$dir" log -1 --oneline --decorate)"
}

ensure_source() {
    local force="${1:-false}"
    if [[ "$force" != "true" ]] && phase_done source; then
        return
    fi

    note "Repositories"
    identity_preflight
    require_command composer
    require_command npm

    local core_repo_url
    core_repo_url="$(git -C "$BUNDLE_ROOT" remote get-url origin 2>/dev/null || true)"
    [[ -n "$core_repo_url" ]] || fail "Unable to discover Core origin URL from launcher checkout."

    if [[ ! -d "$APP_PATH/.git" ]]; then
        sudo mkdir -p "$(dirname "$APP_PATH")"
        sudo chown "$DEPLOY_USER:$WEB_GROUP" "$(dirname "$APP_PATH")"
        git clone --branch "$CORE_BRANCH" "$core_repo_url" "$APP_PATH"
    fi
    repo_pull_ff "$APP_PATH" "Core" "$CORE_BRANCH"

    if [[ ! -d "$CLIENT_PATH/.git" ]]; then
        mkdir -p "$APP_PATH/client"
        git clone --branch "$CLIENT_BRANCH" "$CLIENT_REPO_URL" "$CLIENT_PATH"
    fi
    repo_pull_ff "$CLIENT_PATH" "Client" "$CLIENT_BRANCH"

    note "Host capacity"
    free -h || true
    swapon --show || true
    df -h "$APP_PATH" || true

    note "Dependencies and frontend build"
    cd "$APP_PATH"
    composer install --no-dev --optimize-autoloader
    npm ci
    npm run build

    mark_phase source
}