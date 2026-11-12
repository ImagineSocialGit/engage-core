#!/usr/bin/env python3
"""Local Engage Core permission policy for selected-client runtime files.

Developer owns files. PHP-FPM receives read/traverse access through its group.
Only paths passed explicitly, the selected client root, managed package files,
or the selected client config subtree are touched. Never changes ownership or recursively chowns a checkout.
"""

from __future__ import annotations

import grp
import json
import os
from pathlib import Path
import pwd
import stat
import subprocess
import sys


class PermissionsError(Exception):
    pass


def error(message: str) -> None:
    raise PermissionsError(message)


def policy() -> tuple[str, int, str]:
    group_name = os.environ.get("ENGAGE_CORE_WEB_GROUP", "www-data")
    user_name = os.environ.get("ENGAGE_CORE_WEB_USER", "www-data")
    try:
        group = grp.getgrnam(group_name)
        pwd.getpwnam(user_name)
    except KeyError as exc:
        error(f"Unknown local PHP-FPM identity: {exc}. Set ENGAGE_CORE_WEB_USER/ENGAGE_CORE_WEB_GROUP correctly.")
    return group_name, group.gr_gid, user_name


def inspect(path: Path, kind: str) -> os.stat_result:
    try:
        details = path.lstat()
    except OSError as exc:
        error(f"Cannot inspect client runtime path {path}: {exc}")
    if stat.S_ISLNK(details.st_mode):
        error(f"Refusing to change or validate symlinked client configuration path: {path}")
    if (kind in {"dir", "traverse"} and not stat.S_ISDIR(details.st_mode)) or (
        kind == "file" and not stat.S_ISREG(details.st_mode)
    ):
        error(f"Expected {kind} for selected-client runtime path: {path}")
    return details


def owned_by_developer(path: Path, details: os.stat_result) -> None:
    if details.st_uid != os.geteuid():
        owner = pwd.getpwuid(details.st_uid).pw_name
        error(f"Refusing to modify {path}: owned by [{owner}], not the invoking developer. Repair ownership explicitly; do not run the workflow with sudo.")


def prepare(path: Path, kind: str, group_name: str, group_gid: int) -> None:
    details = inspect(path, kind)
    owned_by_developer(path, details)
    if details.st_gid != group_gid:
        try:
            os.chown(path, -1, group_gid, follow_symlinks=False)
        except PermissionError:
            result = subprocess.run(["sudo", "chgrp", "--", group_name, str(path)], check=False)
            if result.returncode:
                error(f"Cannot assign PHP-FPM group [{group_name}] to {path}. Owner and file permissions were not deliberately changed.")
        if inspect(path, kind).st_gid != group_gid:
            error(f"Group assignment did not take effect for {path}; expected [{group_name}].")
    mode = 0o2750 if kind == "dir" else 0o640
    if stat.S_IMODE(inspect(path, kind).st_mode) != mode:
        path.chmod(mode)
        # Linux silently drops setgid when the owner is not a member of the
        # directory's group. After narrowly assigning the web group, the
        # developer may not be able to set 2750 without elevated privileges.
        # Only retry the specific config directory; never escalate for code.
        if kind == "dir" and stat.S_IMODE(inspect(path, kind).st_mode) != mode:
            result = subprocess.run(
                ["sudo", "chmod", "--", f"{mode:04o}", str(path)],
                check=False,
            )
            if result.returncode:
                error(f"Cannot set group-inheritance permissions on {path}; expected mode {mode:04o}.")
    details = inspect(path, kind)
    if details.st_gid != group_gid or stat.S_IMODE(details.st_mode) != mode:
        error(f"Incorrect final permissions for {path}: expected group [{group_name}] mode {mode:04o}.")


def verify_client_root(client: Path, group_gid: int) -> None:
    details = inspect(client, "dir")
    owned_by_developer(client, details)
    if details.st_gid != group_gid or stat.S_IMODE(details.st_mode) != 0o2750:
        error(f"Selected client directory {client} requires its authorized runtime group and mode 2750 for atomic-write group inheritance. Run the normal client preparation workflow.")


def check_web_access(entries: list[tuple[Path, str]], user_name: str) -> None:
    """Check every path under the real PHP-FPM user in a single sudo invocation."""
    for path, kind in entries:
        inspect(path, kind)
    required = [(str(path), kind) for path, kind in entries]
    program = (
        "import json, os, sys\n"
        "for path, kind in json.loads(sys.argv[1]):\n"
        "    mask = (os.R_OK | os.X_OK) if kind == 'dir' else (os.X_OK if kind == 'traverse' else os.R_OK)\n"
        "    if not os.access(path, mask):\n"
        "        operation = 'traverse directory' if kind == 'traverse' else ('read/traverse directory' if kind == 'dir' else 'read file')\n"
        "        print('Cannot ' + operation + ': ' + path, file=sys.stderr)\n"
        "        sys.exit(17)\n"
    )
    result = subprocess.run(
        ["sudo", "-u", user_name, "--", sys.executable, "-c", program, json.dumps(required)],
        text=True, capture_output=True, check=False,
    )
    if result.returncode:
        detail = result.stderr.strip()
        error(f"PHP-FPM user [{user_name}] cannot access selected-client runtime configuration. "
              f"{detail or 'Sudo user access check failed.'} "
              "Check the indicated path and its parent directory traversal, not world permissions.")


def config_paths(client: Path) -> list[tuple[Path, str]]:
    config = client / "config"
    inspect(client, "dir")
    inspect(config, "dir")
    entries: list[tuple[Path, str]] = [(client, "traverse"), (config, "dir")]
    for path in sorted(config.rglob("*")):
        if path.is_symlink():
            error(f"Refusing symlink within client configuration tree: {path}")
        if path.is_dir():
            entries.append((path, "dir"))
        elif path.is_file():
            entries.append((path, "file"))
        else:
            error(f"Unsupported file type in client configuration tree: {path}")
    return entries


def managed_paths(client: Path) -> list[tuple[Path, str]]:
    config = client / "config"
    # Composer manifests are developer/build-time artifacts, not PHP-FPM
    # runtime configuration. They must not be chgrp'd or chmod'd for the web.
    entries = [(client, "traverse"), (config, "dir")]
    for rel in (
        "config/client_package_registry.json", "config/client_packages.php",
    ):
        path = client / rel
        if path.exists() or path.is_symlink():
            entries.append((path, "file"))
    return entries


def check_core_client_config(client: Path, user: str) -> None:
    # The package workflow doesn't rewrite unrelated hand-authored config files.
    # It DOES detect them if modifying config dir access would break runtime.
    check_web_access(config_paths(client), user)


def main(argv: list[str]) -> int:
    if len(argv) < 3:
        error("Usage: client_file_permissions.py prepare-config|verify-config|prepare-managed|verify-managed|prepare-env|verify-env PATH [PATH ...]")
    action, *args = argv[1:]
    group_name, group_gid, web_user = policy()
    if action in {"prepare-config", "verify-config", "prepare-managed", "verify-managed"}:
        if len(args) != 1:
            error(f"{action} expects exactly one client directory")
        client = Path(args[0]).absolute()
        entries = config_paths(client) if action.endswith("config") else managed_paths(client)
        if action.startswith("prepare"):
            for path, kind in entries:
                # The selected root also needs inheritance for atomic .env
                # replacements. This touches that exact directory, never .git,
                # Composer files or unrelated source below the checkout.
                prepare(path, "dir" if path == client else kind, group_name, group_gid)
        verify_client_root(client, group_gid)
        check_web_access(entries, web_user)
        if action.endswith("managed"):
            check_core_client_config(client, web_user)
    elif action in {"prepare-env", "verify-env"}:
        entries = [(Path(item).absolute(), "file") for item in args]
        if action == "prepare-env":
            for path, kind in entries:
                if path.name == ".env" and path.parent.parent.name == "client":
                    prepare(path.parent, "dir", group_name, group_gid)
                prepare(path, kind, group_name, group_gid)
        for path, _kind in entries:
            if path.name == ".env" and path.parent.parent.name == "client":
                verify_client_root(path.parent, group_gid)
        check_web_access(entries, web_user)
    else:
        error(f"Unknown permission action: {action}")
    return 0


if __name__ == "__main__":
    try:
        sys.exit(main(sys.argv))
    except (PermissionsError, OSError) as exc:
        print(f"ERROR: {exc}", file=sys.stderr)
        sys.exit(1)