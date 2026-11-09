#!/usr/bin/env python3
"""Host-isolated PHPUnit runner for standalone Engage Composer packages.

Package src/ and tests/ PSR-4 namespaces are registered from the package's own
Composer manifest, not from a selected client's dependencies. The host keeps
its normal PHPUnit environment and (where applicable) .env.testing isolation.
This is a local/CI development command, not a deployment/provisioning step.
"""

from __future__ import annotations

import argparse
import json
import os
from pathlib import Path
import re
import subprocess
import sys
import tempfile

CORE_ROOT = Path(__file__).resolve().parents[3]
PLATFORMS = {
    "core": "engage-core",
    "seo": "engage-seo",
    "artist": "artist-site/artist-site",
}
PACKAGE_PATTERN = re.compile(r"^engage-[a-z0-9]+(?:-[a-z0-9]+)*$")
TEST_NAMES = ("*Test.php", "test_*.php", "*.test.php")


class PackageTestError(Exception):
    pass


def fail(message: str) -> None:
    raise PackageTestError(message)


def php_string(value: str) -> str:
    # json.dumps creates PHP-compatible double-quoted strings for paths and
    # PHP class names (escaped backslashes) without evaluating arbitrary input.
    return json.dumps(value, ensure_ascii=True)


def json_object(path: Path) -> dict:
    try:
        document = json.loads(path.read_text())
    except (OSError, ValueError) as exc:
        fail(f"Missing or invalid composer.json [{path}]: {exc}")
    if not isinstance(document, dict):
        fail(f"Expected a Composer JSON object: {path}")
    return document


def resolve_package(value: str, parent: Path) -> Path:
    if "/" in value or value.startswith("."):
        path = Path(value).expanduser().resolve()
    else:
        if not PACKAGE_PATTERN.fullmatch(value):
            fail("Package must be an engage-* repository basename or an absolute path.")
        path = parent / value
    if not path.is_dir() or not (path / "composer.json").is_file():
        fail(f"Package checkout with composer.json not found: {path}")
    return path


def resolve_platform(package: Path, composer: dict, selected: str) -> str:
    if selected != "auto":
        return selected
    descriptor = package / "engage-package.json"
    if descriptor.is_file():
        try:
            metadata = json.loads(descriptor.read_text())
        except (OSError, ValueError) as exc:
            fail(f"Invalid package descriptor: {exc}")
        platform = metadata.get("platform", "core")
        if platform not in PLATFORMS:
            fail(f"Unsupported declared package platform: {platform!r}")
        return platform
    name = str(composer.get("name", ""))
    # Legacy SEO packages have no engage-package.json. Other ambiguous
    # packages are Core-hosted by historical convention; override explicitly.
    if name.startswith(("imagine-social/engage-seo-", "engage-seo/")):
        return "seo"
    if name.startswith(("imagine-social/engage-artist-", "engage-artist/")):
        return "artist"
    return "core"


def mappings(composer: dict, directory: Path) -> tuple[list[tuple[str, Path]], list[Path]]:
    namespaces: list[tuple[str, Path]] = []
    files: list[Path] = []
    for section in ("autoload", "autoload-dev"):
        settings = composer.get(section, {})
        if not isinstance(settings, dict):
            fail(f"Composer {section} must be an object.")
        psr4 = settings.get("psr-4", {})
        if not isinstance(psr4, dict):
            fail(f"Composer {section}.psr-4 must be an object.")
        for namespace, locations in psr4.items():
            if not isinstance(namespace, str) or not namespace.endswith("\\"):
                fail(f"Invalid {section} PSR-4 namespace: {namespace!r}")
            if isinstance(locations, str):
                locations = [locations]
            if not isinstance(locations, list) or not all(isinstance(x, str) for x in locations):
                fail(f"Invalid {section} PSR-4 directories for {namespace}.")
            for location in locations:
                path = (directory / location).resolve()
                if not path.is_relative_to(directory) or not path.is_dir():
                    fail(f"Package namespace directory missing or outside package: {path}")
                namespaces.append((namespace, path))
        for location in settings.get("files", []):
            if not isinstance(location, str):
                fail(f"Invalid Composer {section}.files entry.")
            path = (directory / location).resolve()
            if not path.is_relative_to(directory) or not path.is_file():
                fail(f"Package autoload file missing or outside package: {path}")
            files.append(path)
    return namespaces, files


def build_bootstrap(host: Path, package: Path, composer: dict) -> str:
    namespaces, files = mappings(composer, package)
    host_autoload = host / "vendor" / "autoload.php"
    host_bootstrap = host / "tests" / "bootstrap.php"
    package_autoload = package / "vendor" / "autoload.php"
    if not host_autoload.is_file():
        fail(f"Host Composer dependencies are missing: {host_autoload}")
    statements = [
        "<?php", "",
        "// Temporary host-isolated bootstrap; no selected-client autoloader is loaded.",
        f"$engageHostLoader = require {php_string(str(host_autoload))};",
        "if (!$engageHostLoader instanceof \\Composer\\Autoload\\ClassLoader) {",
        "    throw new \\RuntimeException('Host Composer autoloader unavailable.');",
        "}",
    ]
    if package_autoload.is_file():
        statements.extend([
            "// Load this package's locked Composer dependencies when installed.",
            f"$engageDependencyLoader = require {php_string(str(package_autoload))};",
            "if (!$engageDependencyLoader instanceof \\Composer\\Autoload\\ClassLoader) {",
            "    throw new \\RuntimeException('Package Composer autoloader unavailable.');",
            "}",
            "// Host-owned framework classes take priority over package dev dependencies.",
            "$engageHostLoader->register(true);",
        ])
    for namespace, path in namespaces:
        # Source checkout wins over any stale copy of the package in vendor/.
        statements.append(f"$engageHostLoader->addPsr4({php_string(namespace)}, {php_string(str(path))}, true);")
    for file in files:
        statements.append(f"require_once {php_string(str(file))};")
    if host_bootstrap.is_file():
        # SEO's bootstrap clears CLIENT_KEY and loads only .env.testing; it
        # must run after the package's classmap is registered and before tests.
        statements.append(f"require_once {php_string(str(host_bootstrap))};")
    return "\n".join(statements) + "\n"


def test_targets(package: Path, paths: list[str]) -> list[Path]:
    if paths:
        result = []
        for supplied in paths:
            target = (package / supplied).resolve()
            if not target.is_relative_to(package / "tests") or not target.exists():
                fail(f"Test path must exist under {package / 'tests'}: {supplied}")
            result.append(target)
    else:
        result = [package / "tests"]
    if not any(p.is_file() and any(p.match(pattern) for pattern in TEST_NAMES)
               for target in result for p in ([target] if target.is_file() else target.rglob("*.php"))):
        fail("No PHP test files found. Run a PHPunit package suite or select --test for a specific PHP test.")
    return result


def run_tests(args: argparse.Namespace) -> int:
    parent = Path(args.workspace).expanduser().resolve() if args.workspace else CORE_ROOT.parent
    package = resolve_package(args.package, parent)
    composer = json_object(package / "composer.json")
    platform = resolve_platform(package, composer, args.platform)
    host = (parent / PLATFORMS[platform]).resolve()
    if not (host / "artisan").is_file():
        fail(f"Engage {platform} host checkout is missing or invalid: {host}")
    phpunit = host / "vendor" / "bin" / "phpunit"
    if not phpunit.is_file():
        fail(f"Install {platform} host development dependencies first: {phpunit}")
    configuration = ((host / args.configuration).resolve() if args.configuration else host / "phpunit.xml")
    if not configuration.is_file():
        fail(f"PHPUnit config not found: {configuration}")
    # A custom config must remain inside the host checkout to avoid silently
    # importing a package configuration with unsafe DB/client settings.
    if not configuration.is_relative_to(host):
        fail("Use a PHPUnit configuration from the host platform, not the package/client.")
    targets = test_targets(package, args.test)
    bootstrap_source = build_bootstrap(host, package, composer)
    command_display = ["php", str(phpunit), "--configuration", str(configuration),
                       "--bootstrap", "<temporary package bootstrap>", *(str(x) for x in targets),
                       *args.phpunit_args]
    print(f"Package: {package.name}", flush=True)
    print(f"Host:    {platform} ({host})", flush=True)
    print("Client:  isolated (no selected-client dependency)", flush=True)
    print("Dependencies: " + (
        "package Composer autoload available (host framework takes precedence)"
        if (package / "vendor" / "autoload.php").is_file()
        else "host autoload only (run composer install in the package if its dependencies are needed)"
    ), flush=True)
    if args.dry_run:
        print("Command: " + " ".join(command_display))
        print("Dry run: no tests executed or project files changed.")
        return 0
    with tempfile.TemporaryDirectory(prefix="engage-package-test-") as temp:
        bootstrap = Path(temp) / "bootstrap.php"
        bootstrap.write_text(bootstrap_source)
        command = ["php", str(phpunit), "--configuration", str(configuration), "--bootstrap",
                   str(bootstrap), *(str(x) for x in targets), *args.phpunit_args]
        environment = os.environ.copy()
        environment["APP_ENV"] = "testing"
        environment["CLIENT_KEY"] = ""
        # Do not force database credentials here. The host test config and
        # test bootstrap are authoritative for isolated test databases.
        try:
            return subprocess.run(command, cwd=host, env=environment, check=False).returncode
        except FileNotFoundError as exc:
            fail(f"PHP CLI not installed: {exc}")
    return 1


def main() -> int:
    parser = argparse.ArgumentParser(description="Run standalone Engage package tests with an isolated host platform")
    parser.add_argument("package", help="engage-* checkout basename or absolute directory")
    parser.add_argument("--platform", choices=("auto", *PLATFORMS), default="auto")
    parser.add_argument("--workspace", help="Engage checkouts' parent directory (default: Core sibling directory)")
    parser.add_argument("--configuration", help="Host-relative PHPUnit configuration, if not phpunit.xml")
    parser.add_argument("--test", action="append", default=[], help="Package-relative test file/directory under tests/ (repeatable)")
    parser.add_argument("--dry-run", action="store_true")
    supplied = sys.argv[1:]
    if "--" in supplied:
        boundary = supplied.index("--")
        runner_args = supplied[:boundary]
        phpunit_args = supplied[boundary + 1:]
    else:
        runner_args = supplied
        phpunit_args = []
    args = parser.parse_args(runner_args)
    args.phpunit_args = phpunit_args
    try:
        return run_tests(args)
    except PackageTestError as exc:
        print(f"ERROR: {exc}", file=sys.stderr)
        return 2


if __name__ == "__main__":
    sys.exit(main())