#!/usr/bin/env python3
"""Read-only inspection of an Engage Infrastructure application allocation.

The authoritative allocation and PHP-FPM configuration are created by Ansible.
This command cannot create an instance, select a release or configure services.
It deliberately does not read application .env files or credential material.
"""

from __future__ import annotations

import argparse
import configparser
import grp
import json
import os
from pathlib import Path
import pwd
import re
import stat
import sys

INSTANCE_ID = re.compile(r"^[a-z][a-z0-9-]*$")
INSTANCE_ROOT = Path("/srv/engage/instances")
POOL_ROOT = Path("/etc/php/8.5/fpm/pool.d")


def inspect_instance(
    instance_id: str,
    *,
    instances_root: Path = INSTANCE_ROOT,
    pools_root: Path = POOL_ROOT,
    trusted_pool_owner_uid: int = 0,
) -> dict:
    """Inspect only; return a redacted result with all discovered blockers."""
    issues: list[str] = []
    result: dict = {
        "instance_id": instance_id,
        "ready": False,
        "instance_root": None,
        "deploy_user": None,
        "runtime_user": None,
        "group": None,
        "php_fpm_socket": None,
        "issues": issues,
    }

    # Validate before constructing a child path. No arbitrary path arguments
    # are exposed by the production command-line interface.
    if not INSTANCE_ID.fullmatch(instance_id):
        issues.append("Invalid instance ID (expected lowercase hyphenated identifier).")
        return result

    root = instances_root / instance_id
    pool_file = pools_root / f"engage-{instance_id}.conf"
    socket_path = f"/run/php/engage-{instance_id}.sock"
    result["instance_root"] = str(root)
    result["php_fpm_socket"] = socket_path

    def check_directory(
        path: Path, *, owner: int | None, group: int | None, writable_by_group: bool = False
    ) -> None:
        try:
            info = path.lstat()
        except (OSError, ValueError):
            issues.append(f"Missing or inaccessible Ansible directory: {path}")
            return
        if not stat.S_ISDIR(info.st_mode):
            issues.append(f"Expected actual directory (not symlink): {path}")
            return
        if info.st_mode & stat.S_IWOTH or info.st_mode & stat.S_IROTH or info.st_mode & stat.S_IXOTH:
            issues.append(f"Directory grants unrelated-user access: {path}")
        if not info.st_mode & stat.S_IXGRP:
            issues.append(f"Directory denies traversal to the isolated runtime group: {path}")
        if writable_by_group and info.st_mode & 0o070 != 0o070:
            issues.append(f"Shared runtime directory is not group read/write/traversable: {path}")
        if owner is not None and info.st_uid != owner:
            issues.append(f"Unexpected directory owner: {path}")
        if group is not None and info.st_gid != group:
            issues.append(f"Unexpected directory group: {path}")

    try:
        root_info = root.lstat()
    except OSError:
        issues.append(f"Missing or inaccessible Ansible instance root: {root}")
        return result
    if not stat.S_ISDIR(root_info.st_mode):
        issues.append(f"Instance root is not an actual directory: {root}")
        return result

    try:
        deploy = pwd.getpwuid(root_info.st_uid)
        instance_group = grp.getgrgid(root_info.st_gid)
    except KeyError:
        issues.append("Instance root owner or group does not resolve to an OS identity.")
        return result

    result["deploy_user"] = deploy.pw_name
    result["group"] = instance_group.gr_name
    check_directory(root, owner=deploy.pw_uid, group=instance_group.gr_gid)
    for directory in (root / "releases", root / "shared"):
        check_directory(directory, owner=deploy.pw_uid, group=instance_group.gr_gid)

    # Persistent storage belongs to the separate Ansible shared-storage role.
    # It is group-accessible to the isolated runtime user but not world-visible.
    for directory in (root / "shared/storage", root / "shared/logs"):
        check_directory(directory, owner=None, group=instance_group.gr_gid, writable_by_group=True)

    try:
        pool_stat = pool_file.lstat()
        if not stat.S_ISREG(pool_stat.st_mode):
            issues.append(f"PHP-FPM pool must be a regular file, not a symlink: {pool_file}")
            return result
        if pool_stat.st_uid != trusted_pool_owner_uid or pool_stat.st_mode & 0o022:
            issues.append(f"PHP-FPM pool ownership/permissions are not trusted: {pool_file}")
            return result
        parser = configparser.ConfigParser(interpolation=None, strict=True)
        parser.optionxform = str
        with pool_file.open(encoding="utf-8") as handle:
            parser.read_file(handle)
        if parser.sections() != [instance_id]:
            issues.append(f"PHP-FPM pool section must match instance identity: {pool_file}")
            return result
        settings = parser[instance_id]
        runtime_user = settings.get("user", "").strip()
        runtime_group = settings.get("group", "").strip()
        listen = settings.get("listen", "").strip()
        listen_owner = settings.get("listen.owner", "").strip()
        listen_group = settings.get("listen.group", "").strip()
        listen_mode = settings.get("listen.mode", "").strip()
    except (OSError, UnicodeError, configparser.Error, ValueError):
        issues.append(f"Missing, unreadable or invalid Ansible PHP-FPM pool: {pool_file}")
        return result

    result["runtime_user"] = runtime_user or None
    if not re.fullmatch(r"[a-z][a-z0-9_]*", runtime_user):
        issues.append("PHP-FPM pool has no valid isolated runtime user.")
    else:
        try:
            runtime = pwd.getpwnam(runtime_user)
            if runtime.pw_uid == deploy.pw_uid:
                issues.append("Deploy and PHP runtime identities must be distinct.")
            if runtime.pw_gid != instance_group.gr_gid:
                issues.append("PHP runtime user's primary group differs from instance group.")
        except KeyError:
            issues.append("PHP-FPM runtime user does not exist.")

    if runtime_group != instance_group.gr_name:
        issues.append("PHP-FPM group differs from the Ansible instance group.")
    if listen != socket_path:
        issues.append("PHP-FPM socket path differs from the Ansible instance convention.")
    if listen_owner != runtime_user or listen_group != "www-data" or listen_mode != "0660":
        issues.append("PHP-FPM socket access settings differ from Ansible's isolation contract.")

    result["ready"] = not issues
    return result


def main() -> int:
    parser = argparse.ArgumentParser(description="Read-only Ansible app-instance allocation check")
    parser.add_argument("--instance-id", required=True)
    args = parser.parse_args()
    result = inspect_instance(args.instance_id)
    print(json.dumps(result, sort_keys=True))
    return 0 if result["ready"] else 1


if __name__ == "__main__":
    sys.exit(main())