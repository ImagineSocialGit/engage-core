#!/usr/bin/env python3
"""Reviewed, local-only Composer revision update and lock-pinned repair.

This is a stage of Core's existing scripts/package.sh workflow, not another
package registry. Client Composer files and optional package declarations stay
owned by the selected client; installed vendor artifacts are generated.
"""

from __future__ import annotations

import argparse
import hashlib
import json
import os
from pathlib import Path
import re
import subprocess
import sys

import package_workflow as core

REF = re.compile(r"^[0-9a-fA-F]{40}$")
PACKAGE = re.compile(r"^imagine-social/engage-[a-z0-9]+(?:-[a-z0-9]+)*$")


class RevisionError(core.WorkflowError):
    pass


def reject(message: str) -> None:
    raise RevisionError(message)


def regular(path: Path) -> bool:
    return path.is_file() and not path.is_symlink()


def document(path: Path) -> dict:
    if not regular(path):
        reject(f"Required regular client Composer file is missing or unsafe: {path.name}")
    try:
        value = json.loads(path.read_text(encoding="utf-8"))
    except (OSError, ValueError, UnicodeError):
        reject(f"Invalid client Composer JSON: {path.name}")
    if not isinstance(value, dict):
        reject(f"Client Composer JSON must be an object: {path.name}")
    return value


def lock_packages(lock: dict) -> dict[str, dict]:
    records: dict[str, dict] = {}
    for section in ("packages", "packages-dev"):
        entries = lock.get(section, [])
        if not isinstance(entries, list):
            reject(f"Composer lock {section} must be a list.")
        for entry in entries:
            if not isinstance(entry, dict) or not isinstance(entry.get("name"), str):
                reject(f"Invalid Composer lock package in {section}.")
            name = entry["name"]
            if name in records:
                reject(f"Duplicate Composer lock package: {name}")
            records[name] = entry
    return records


def locked_source(item: dict) -> tuple[str, str]:
    source = item.get("source")
    if not isinstance(source, dict):
        reject("Selected package has no Git source in the client's Composer lock.")
    url, ref = source.get("url"), source.get("reference")
    if not isinstance(url, str) or not url.strip() or not isinstance(ref, str) or not REF.fullmatch(ref):
        reject("Selected package must have a valid Git source URL and full revision in composer.lock.")
    if not (url.startswith("git@") or url.startswith("ssh://") or url.startswith("https://")):
        reject("Selected package lock source must use an SSH or HTTPS Git URL.")
    return url, ref.lower()


def git_output(path: Path, *args: str, optional: bool = False) -> str | None:
    result = subprocess.run(["git", "-C", str(path), *args], text=True,
                            capture_output=True, check=False)
    if result.returncode:
        if optional:
            return None
        reject(f"Installed package Git inspection failed ({' '.join(args)}).")
    return result.stdout.strip()


def metadata_reference(vendor: Path, name: str) -> str | None:
    metadata = vendor / "composer" / "installed.json"
    if metadata.is_symlink() or (metadata.exists() and not regular(metadata)):
        reject("Client installed Composer metadata path is unsafe.")
    if not regular(metadata):
        return None
    try:
        content = json.loads(metadata.read_text(encoding="utf-8"))
    except (OSError, UnicodeError, ValueError):
        reject("Client installed Composer metadata is unreadable or invalid.")
    records = content.get("packages") if isinstance(content, dict) else content
    if not isinstance(records, list):
        reject("Client installed Composer metadata has an unsupported format.")
    matches = [entry for entry in records if isinstance(entry, dict) and entry.get("name") == name]
    if len(matches) != 1:
        return None
    entry = matches[0]
    for field in ("source", "dist"):
        candidate = entry.get(field)
        if isinstance(candidate, dict) and isinstance(candidate.get("reference"), str):
            return candidate["reference"].lower()
    return None


def installed_state(client: Path, name: str, expected_ref: str, source_url: str) -> dict:
    vendor = client / "vendor"
    if vendor.is_symlink() or (vendor / "composer").is_symlink() or (vendor / name.split("/", 1)[0]).is_symlink():
        reject("Client vendor directory structure contains an unsafe symlink.")
    target = vendor / name
    if target.is_symlink():
        return {"state": "local_link", "revision": None, "remote": "not_checked"}
    if not target.is_dir():
        return {"state": "missing", "revision": None, "remote": "not_checked"}
    if not regular(vendor / "autoload.php"):
        return {"state": "autoload_missing", "revision": None, "remote": "not_checked"}
    git_directory = target / ".git"
    if git_directory.is_symlink() or (git_directory.exists() and not git_directory.is_dir()):
        reject("Installed package uses an unsupported or unsafe Git directory.")
    if git_directory.is_dir():
        top = git_output(target, "rev-parse", "--show-toplevel")
        if top is None or Path(top).resolve() != target.resolve():
            reject("Installed package Git working tree is not isolated to its vendor directory.")
        dirty = git_output(target, "status", "--porcelain", "--untracked-files=no")
        if dirty:
            return {"state": "dirty_source", "revision": None, "remote": "not_checked"}
        actual_ref = git_output(target, "rev-parse", "HEAD")
        if actual_ref is None or not REF.fullmatch(actual_ref):
            reject("Installed package Git HEAD is invalid.")
        remote = git_output(target, "remote", "get-url", "composer", optional=True)
        remote_state = "missing" if remote is None else "matches" if remote == source_url else "mismatch"
        metadata_ref = metadata_reference(vendor, name)
        source_state = ("source_metadata_missing" if metadata_ref is None else
                        "source_metadata_drift" if metadata_ref != expected_ref else
                        "source_match" if actual_ref.lower() == expected_ref else "source_drift")
        return {"state": source_state,
                "revision": actual_ref.lower(), "remote": remote_state}
    installed_ref = metadata_reference(vendor, name)
    if installed_ref is None:
        return {"state": "unverified_dist", "revision": None, "remote": "not_applicable"}
    return {"state": "dist_match" if installed_ref == expected_ref else "dist_drift",
            "revision": installed_ref, "remote": "not_applicable"}


def inspect(client_key: str, name: str, action: str) -> dict:
    if not core.CLIENT_PATTERN.fullmatch(client_key):
        reject("Invalid selected client key.")
    if not PACKAGE.fullmatch(name):
        reject("Expected one explicit Engage Composer package name.")
    if action not in ("update", "repair"):
        reject("Unsupported Composer revision operation.")
    root = core.ROOT
    if not (root / "artisan").is_file():
        reject("Core checkout is missing.")
    client = root / "client" / client_key
    if (root / "client").is_symlink() or client.is_symlink():
        reject("Selected client source root must not be a symlink.")
    if not regular(client / "config" / "client.php"):
        reject("Selected client source does not exist.")
    composer_path, lock_path = client / "composer.json", client / "composer.lock"
    composer, lock = document(composer_path), document(lock_path)
    config = composer.get("config", {})
    if not isinstance(config, dict) or config.get("vendor-dir", "vendor") != "vendor":
        reject("Selected-client Composer vendor-dir must be vendor.")
    if not isinstance(composer.get("require"), dict) or name not in composer["require"]:
        reject("Package is not a direct selected-client Composer requirement.")
    packages = lock_packages(lock)
    if name not in packages:
        reject("Required package is missing from the selected client's Composer lock.")
    url, ref = locked_source(packages[name])
    registry = client / "config" / core.REGISTRY_NAME
    manifest = client / "config" / "client_packages.php"
    if manifest.is_symlink() or registry.is_symlink():
        reject("Client package manifest or registry must not be symlinked.")
    if registry.exists() or registry.is_symlink():
        core.safe_registry(client)  # Do not silently overwrite a managed manifest.
    state = installed_state(client, name, ref, url)
    if state["state"] == "local_link":
        reject("Package is locally symlinked. Unlink it with package.sh unlink before revision operations.")
    if state["state"] == "dirty_source":
        reject("Installed source contains modified tracked files. Preserve changes before updating or repairing.")
    if state["remote"] == "mismatch":
        reject("Installed source Composer Git remote differs from composer.lock; refusing to overwrite it.")
    if action == "update" and state["state"] not in ("source_match", "dist_match"):
        reject("Installed package does not match the lock; run the reviewed package repair operation first.")
    watched = (composer_path, lock_path, registry, manifest, client / "vendor" / "composer" / "installed.json")
    fingerprint = hashlib.sha256()
    for path in watched:
        blob = path.read_bytes() if regular(path) else b""
        fingerprint.update(str(path.relative_to(client)).encode() + b"\x00")
        fingerprint.update(len(blob).to_bytes(8, "big") + blob)
    # Bind source HEAD, selected client, and missing/mismatched remote to the approval.
    fingerprint.update(json.dumps([client_key, name, action, ref, state], sort_keys=True).encode())
    return {
        "schema": 1, "action": action, "client": client_key, "package": name,
        "locked_revision": ref, "installed": state, "approval_fingerprint": fingerprint.hexdigest(),
        "remote_repair_required": state["remote"] == "missing",
        "requires_approved_apply": True, "client_source_files_written": False,
        "provider_credentials_changed": False,
        "status": "package_revision_plan",
    }


def ensure_composer_remote(client: Path, name: str, url: str, expected_ref: str) -> None:
    target = client / "vendor" / name
    git_directory = target / ".git"
    if not target.is_dir() or not git_directory.is_dir():
        return
    if target.is_symlink() or git_directory.is_symlink():
        reject("Installed source Git path is unsafe.")
    top = git_output(target, "rev-parse", "--show-toplevel")
    if top is None or Path(top).resolve() != target.resolve():
        reject("Installed source is not an isolated Composer vendor checkout.")
    if git_output(target, "status", "--porcelain", "--untracked-files=no"):
        reject("Installed source contains modified tracked files; cannot repair Git remote.")
    remote = git_output(target, "remote", "get-url", "composer", optional=True)
    if remote is None:
        core.run(["git", "-C", str(target), "remote", "add", "composer", url], capture=True)
    elif remote != url:
        reject("Existing installed source Composer Git remote is unsafe or mismatched.")


def restore_lock(lock_path: Path, original: bytes) -> None:
    if not regular(lock_path):
        reject("Cannot recover: client composer.lock is missing or no longer a regular file.")
    if lock_path.read_bytes() != original:
        # In-place restore keeps the authorized ownership and group of the existing lock.
        with lock_path.open("wb") as output:
            output.write(original)
            output.flush()
            os.fsync(output.fileno())


def reconcile(client_key: str, name: str, action: str, fingerprint: str) -> dict:
    review = inspect(client_key, name, action)
    if not fingerprint or fingerprint != review["approval_fingerprint"]:
        reject("Apply requires the exact fingerprint from a freshly reviewed plan.")
    client = core.validate_client(client_key)  # Mutating operations require active local selection.
    if inspect(client_key, name, action)["approval_fingerprint"] != fingerprint:
        reject("Client Composer or installed source changed since review; re-plan required.")
    core.require_command("composer")
    core.require_command("git")
    core.verify_managed_runtime_permissions(client)
    # Reject an already-inconsistent Composer manifest before editing vendor or lock.
    core.run(["composer", "validate", "--no-check-publish", "--no-interaction"], cwd=client)
    composer_path, lock_path = client / "composer.json", client / "composer.lock"
    original_composer, original_lock = composer_path.read_bytes(), lock_path.read_bytes()
    old_packages = lock_packages(json.loads(original_lock))
    url, ref = locked_source(old_packages[name])
    ensure_composer_remote(client, name, url, ref)
    base = ["--prefer-dist", "--no-interaction", "--no-progress", "--no-scripts", "--no-plugins"]
    command = (["composer", "update", name, *base] if action == "update"
               else ["composer", "install", *base])
    try:
        core.run(command, cwd=client)
        if composer_path.read_bytes() != original_composer:
            reject("Composer unexpectedly changed selected-client composer.json.")
        latest = document(lock_path)
        new_packages = lock_packages(latest)
        if action == "repair" and lock_path.read_bytes() != original_lock:
            reject("Lock-pinned repair changed composer.lock.")
        if action == "update":
            before = {key: value for key, value in old_packages.items() if key != name}
            after = {key: value for key, value in new_packages.items() if key != name}
            if before != after:
                reject("Update changed unrelated dependency locks. Review separately; no broad update authorized.")
        if name not in new_packages:
            reject("Updated lock no longer contains the selected package.")
        new_url, new_ref = locked_source(new_packages[name])
        if new_url != url:
            reject("Composer changed the package source repository unexpectedly.")
        state = installed_state(client, name, new_ref, new_url)
        if state["state"] not in ("source_match", "dist_match"):
            reject("Installed package revision does not match the resulting composer.lock.")
        core.run(["composer", "validate", "--no-check-publish", "--no-interaction"], cwd=client)
        core.run(["composer", "show", name, "--no-interaction"], cwd=client, capture=True)
        core.ensure_managed_runtime_permissions(client)
    except (core.WorkflowError, OSError, ValueError) as exc:
        # The vendor tree may have changed even if the lock did not. Recover to
        # the *original* pinned lock, and never describe failed updates as success.
        try:
            if not regular(composer_path):
                reject("Cannot recover: client composer.json is missing or unsafe.")
            if composer_path.read_bytes() != original_composer:
                with composer_path.open("wb") as output:
                    output.write(original_composer)
                    output.flush()
                    os.fsync(output.fileno())
            restore_lock(lock_path, original_lock)
            core.run(["composer", "install", *base], cwd=client)
            restored = installed_state(client, name, ref, url)
            if restored["state"] not in ("source_match", "dist_match"):
                reject("Rollback left installed package inconsistent with original composer.lock.")
            core.ensure_managed_runtime_permissions(client)
        except (core.WorkflowError, OSError) as recovery_error:
            reject(f"Package {action} failed; lock restored but vendor recovery is not verified: {recovery_error}")
        reject(f"Package {action} failed; original lock and installed package were recovered: {exc}")
    return {
        "schema": 1, "action": action, "client": client_key, "package": name,
        "previous_revision": ref, "installed_revision": new_ref,
        "revision_changed": new_ref != ref, "installed_revision_verified": True,
        "lock_pinned": True, "provider_credentials_changed": False,
        "status": "package_revision_reconciled",
    }


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description="Reviewed Core selected-client Composer revision workflow")
    parser.add_argument("action", choices=("update", "repair"))
    parser.add_argument("client")
    parser.add_argument("package")
    mode = parser.add_mutually_exclusive_group(required=True)
    mode.add_argument("--plan", action="store_true")
    mode.add_argument("--apply", action="store_true")
    parser.add_argument("--confirm-plan")
    parser.add_argument("--json", action="store_true")
    args = parser.parse_args(argv)
    try:
        result = (inspect(args.client, args.package, args.action) if args.plan else
                  reconcile(args.client, args.package, args.action, args.confirm_plan or ""))
        if args.json:
            print(json.dumps(result, sort_keys=True))
        else:
            print(f"{result['status']}: {args.client} / {args.package}")
            if args.plan:
                print(f"Locked revision: {result['locked_revision']}")
                print(f"Installed state: {result['installed']['state']}")
                print(f"Approval fingerprint: {result['approval_fingerprint']}")
                print("Plan only: no files or credentials modified.")
            else:
                print(f"Installed revision: {result['installed_revision']}")
                print("Verify and commit the selected client's composer.lock if changed.")
        return 0
    except (core.WorkflowError, OSError, ValueError) as exc:
        print(f"ERROR: {exc}", file=sys.stderr)
        return 1


if __name__ == "__main__":
    sys.exit(main())