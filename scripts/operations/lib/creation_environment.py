#!/usr/bin/env python3
"""Fail-closed development-only guard for source creation workflows.

Creation is defined by the platform checkout's persisted root APP_ENV, not a
process override (which could accidentally mask a deployed environment).
This tool performs read-only checks and does not load/evaluate shell .env files.
"""

from __future__ import annotations

import argparse
from pathlib import Path
import re
import sys


class CreationEnvironmentError(Exception):
    pass


def require_local_creation(root: Path, operation: str) -> None:
    env_path = root / ".env"
    try:
        content = env_path.read_text(encoding="utf-8")
    except (OSError, UnicodeError) as exc:
        raise CreationEnvironmentError(
            f"{operation} requires root APP_ENV=local; cannot read {env_path}: {exc}"
        ) from exc

    assignments: list[str] = []
    for line in content.splitlines():
        stripped = line.strip()
        if not stripped or stripped.startswith("#"):
            continue
        match = re.fullmatch(r"APP_ENV\s*=\s*(.*?)\s*", stripped)
        if match is not None:
            value = match.group(1)
            if len(value) >= 2 and value[0] == value[-1] and value[0] in ("'", '"'):
                value = value[1:-1]
            assignments.append(value)

    if len(assignments) != 1:
        raise CreationEnvironmentError(
            f"{operation} requires exactly one active root APP_ENV=local assignment "
            f"in {env_path}; found {len(assignments)}."
        )
    if assignments[0] != "local":
        raise CreationEnvironmentError(
            f"{operation} is restricted to local development; "
            f"root {env_path} declares APP_ENV={assignments[0]!r}, not 'local'."
        )


def main() -> int:
    parser = argparse.ArgumentParser(description="Check source creation is running in local development")
    parser.add_argument("root", type=Path, help="Platform root containing persisted .env")
    parser.add_argument("operation", help="Human-readable workflow name")
    args = parser.parse_args()
    try:
        require_local_creation(args.root, args.operation)
    except CreationEnvironmentError as exc:
        print(f"ERROR: {exc}", file=sys.stderr)
        return 1
    return 0


if __name__ == "__main__":
    sys.exit(main())