#!/usr/bin/env python3
"""Read-only inspection of an approved Engage Core release inside an Ansible instance.

The Ansible/controller side prepares source and approves exact commit IDs. This
inspector neither clones nor fetches repositories, writes a manifest, selects a
current symlink, installs dependencies, nor reads any application credentials.
"""

from __future__ import annotations

import argparse
import grp
import json
import os
from pathlib import Path
import pwd
import re
import stat
import subprocess
import sys

from launch_managed_instance import INSTANCE_ROOT, POOL_ROOT, inspect_instance

RELEASE_ID = re.compile(r"^[a-z0-9][a-z0-9-]{0,63}$")
CLIENT_KEY = re.compile(r"^[a-z0-9][a-z0-9_-]*$")
REPOSITORY = re.compile(r"^[A-Za-z0-9][A-Za-z0-9-]*/[A-Za-z0-9][A-Za-z0-9._-]*$")
COMMIT = re.compile(r"^[0-9a-fA-F]{40}$")
SSH_ORIGIN = re.compile(r"^git@github\.com:([^/]+)/([^/]+)$")
HTTPS_ORIGIN = re.compile(r"^https://github\.com/([^/]+)/([^/]+)$")


def normalize_origin(value: str) -> str | None:
    """Accept only canonical, credential-free GitHub SSH or HTTPS remotes."""
    match = SSH_ORIGIN.fullmatch(value) or HTTPS_ORIGIN.fullmatch(value)
    if not match:
        return None
    owner, repo = match.groups()
    if repo.endswith(".git"):
        repo = repo[:-4]
    identity = f"{owner}/{repo}"
    if not REPOSITORY.fullmatch(identity) or ".." in repo:
        return None
    return identity.casefold()


def inspect_release(
    instance_id: str,
    release_id: str,
    client_key: str,
    core_repository: str,
    core_commit: str,
    client_repository: str,
    client_commit: str,
    *,
    instances_root: Path = INSTANCE_ROOT,
    pools_root: Path = POOL_ROOT,
    trusted_pool_owner_uid: int = 0,
    git_binary: str = "git",
) -> dict:
    """Check exact source identity and tracked cleanliness, without mutating state."""
    issues: list[str] = []
    result = {
        "instance_id": instance_id,
        "release_id": release_id,
        "client_key": client_key,
        "ready": False,
        "release_path": None,
        "core": {"repository": core_repository, "commit": None},
        "client": {"repository": client_repository, "commit": None},
        "issues": issues,
    }

    # Refuse path construction and Git invocation for invalid user-controlled IDs.
    for label, value, pattern in (
        ("release ID", release_id, RELEASE_ID),
        ("client key", client_key, CLIENT_KEY),
        ("Core repository", core_repository, REPOSITORY),
        ("client repository", client_repository, REPOSITORY),
        ("Core commit", core_commit, COMMIT),
        ("client commit", client_commit, COMMIT),
    ):
        if not pattern.fullmatch(value):
            issues.append(f"Invalid {label} in the approved source selection.")
    if ".." in core_repository or ".." in client_repository:
        issues.append("Repository identifiers must not contain consecutive dots.")
    if issues:
        return result

    allocation = inspect_instance(
        instance_id,
        instances_root=instances_root,
        pools_root=pools_root,
        trusted_pool_owner_uid=trusted_pool_owner_uid,
    )
    if not allocation["ready"]:
        issues.extend(f"Allocation: {issue}" for issue in allocation["issues"])
        return result

    release = instances_root / instance_id / "releases" / release_id
    result["release_path"] = str(release)
    deploy = pwd.getpwnam(allocation["deploy_user"])
    group = grp.getgrnam(allocation["group"])

    def trusted_directory(path: Path, label: str) -> bool:
        try:
            info = path.lstat()
        except OSError:
            issues.append(f"Missing or inaccessible {label}: {path}")
            return False
        if not stat.S_ISDIR(info.st_mode):
            issues.append(f"{label} must be a real directory, not a symlink: {path}")
            return False
        if info.st_uid != deploy.pw_uid or info.st_gid != group.gr_gid:
            issues.append(f"{label} is not owned by the allocated deploy identity/group: {path}")
        if info.st_mode & 0o007 or info.st_mode & stat.S_IWGRP:
            issues.append(f"{label} permits unintended world access or group writes: {path}")
        return True

    if not trusted_directory(release, "release checkout"):
        return result
    client_directory = release / "client"
    if not trusted_directory(client_directory, "client checkout parent"):
        return result
    client_path = client_directory / client_key
    if not trusted_directory(client_path, "selected client checkout"):
        return result

    def trusted_regular(path: Path, label: str) -> None:
        try:
            info = path.lstat()
        except OSError:
            issues.append(f"Missing or inaccessible {label}: {path}")
            return
        if not stat.S_ISREG(info.st_mode) or info.st_uid != deploy.pw_uid:
            issues.append(f"{label} is not a regular deploy-owned file: {path}")
        elif info.st_mode & 0o007 or info.st_mode & 0o020:
            issues.append(f"{label} has unsafe permissions: {path}")

    trusted_directory(release / "bootstrap", "Core bootstrap directory")
    trusted_directory(client_path / "config", "selected client config directory")
    trusted_regular(release / "artisan", "Core artisan entrypoint")
    trusted_regular(release / "bootstrap/app.php", "Core bootstrap entrypoint")
    trusted_regular(client_path / "config/client.php", "selected client configuration")

    # Git should not use controller/user configuration, acquire optional locks,
    # consult a credential helper, invoke hooks, or access any network remotes.
    git_environment = {key: value for key, value in os.environ.items() if not key.startswith("GIT_")}
    git_environment.update({
        "GIT_CONFIG_NOSYSTEM": "1",
        "GIT_CONFIG_GLOBAL": os.devnull,
        "GIT_OPTIONAL_LOCKS": "0",
        "GIT_TERMINAL_PROMPT": "0",
        "GIT_NO_REPLACE_OBJECTS": "1",
    })

    def git(repository_path: Path, *args: str) -> tuple[bool, str]:
        try:
            completed = subprocess.run(
                [git_binary, "-c", "core.fsmonitor=false", "-c", "core.hooksPath=/dev/null",
                 "-c", "diff.external=", "-C", str(repository_path), *args],
                stdin=subprocess.DEVNULL,
                capture_output=True,
                text=True,
                env=git_environment,
                timeout=15,
                check=False,
            )
        except (OSError, subprocess.TimeoutExpired):
            return False, ""
        return completed.returncode == 0, completed.stdout.strip()

    def inspect_git(path: Path, label: str, expected_repo: str, expected_commit: str) -> None:
        git_directory = path / ".git"
        if not trusted_directory(git_directory, f"{label} Git metadata"):
            return
        ok, top_level = git(path, "rev-parse", "--show-toplevel")
        if not ok or top_level != str(path):
            issues.append(f"{label} must be its own Git checkout at the approved release path.")
            return
        ok, origin = git(path, "config", "--local", "--get", "remote.origin.url")
        if not ok or normalize_origin(origin) != expected_repo.casefold():
            issues.append(f"{label} Git origin does not match the approved repository.")
        ok, actual = git(path, "rev-parse", "--verify", "HEAD^{commit}")
        if not ok or not COMMIT.fullmatch(actual):
            issues.append(f"{label} HEAD commit cannot be verified.")
        else:
            result[label.lower()]["commit"] = actual
            if actual.casefold() != expected_commit.casefold():
                issues.append(f"{label} HEAD differs from the approved commit.")
        # Inspects tracked files and the index without reporting sensitive file
        # contents. Untracked build artifacts (vendor, .env, etc.) are not read.
        ok, status = git(path, "status", "--porcelain", "--untracked-files=no")
        if not ok or status:
            issues.append(f"{label} has modified tracked files or cannot verify a clean index.")

    inspect_git(release, "Core", core_repository, core_commit)
    inspect_git(client_path, "Client", client_repository, client_commit)
    result["ready"] = not issues
    return result


def main() -> int:
    parser = argparse.ArgumentParser(description="Read-only approved Core release source check")
    parser.add_argument("--instance-id", required=True)
    parser.add_argument("--release-id", required=True)
    parser.add_argument("--client-key", required=True)
    parser.add_argument("--core-repository", required=True, help="GitHub OWNER/REPO")
    parser.add_argument("--core-commit", required=True, help="Approved full 40-hex Git commit")
    parser.add_argument("--client-repository", required=True, help="GitHub OWNER/REPO")
    parser.add_argument("--client-commit", required=True, help="Approved full 40-hex Git commit")
    args = parser.parse_args()
    result = inspect_release(
        args.instance_id,
        args.release_id,
        args.client_key,
        args.core_repository,
        args.core_commit,
        args.client_repository,
        args.client_commit,
    )
    print(json.dumps(result, sort_keys=True))
    return 0 if result["ready"] else 1


if __name__ == "__main__":
    sys.exit(main())