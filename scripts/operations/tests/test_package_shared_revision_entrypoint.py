"""Operator entrypoint tests: shared revision delegation never invokes real Composer."""
from __future__ import annotations

import json
import os
from pathlib import Path
import shutil
import subprocess
import tempfile
import unittest

SOURCE = Path(__file__).resolve().parents[2] / "package.sh"


class PackageRevisionEntrypointTest(unittest.TestCase):
    def setUp(self) -> None:
        self.temporary = tempfile.TemporaryDirectory()
        self.addCleanup(self.temporary.cleanup)
        self.work = Path(self.temporary.name)
        self.root = self.work / "engage-core"
        scripts = self.root / "scripts"
        scripts.mkdir(parents=True)
        (self.root / "artisan").write_text("<?php // fixture\n")
        shutil.copyfile(SOURCE, scripts / "package.sh")
        (scripts / "package.sh").chmod(0o755)
        self.infra = self.work / "engage-infrastructure"
        (self.infra / "scripts/lib").mkdir(parents=True)
        (self.infra / "scripts/lib/package_revision.py").write_text("# B51 fixture\n")
        (self.infra / "scripts/engage").write_text(
            "import json, sys\nprint(json.dumps(sys.argv[1:]))\n"
        )
        self.secret = "SENSITIVE_ENV_CANARY_DO_NOT_PRINT"
        (self.root / ".env").write_text(f"APP_KEY={self.secret}\n")
        self.package = "imagine-social/engage-example"
        if "core" == "core":
            (scripts / "operations/lib").mkdir(parents=True)
            (scripts / "operations/lib/package_revision_workflow.py").write_text(
                "import json, sys\nprint(json.dumps(['legacy', *sys.argv[1:]]))\n"
            )

    def execute(self, *arguments: str, infra: str | None = None) -> subprocess.CompletedProcess[str]:
        env = dict(os.environ)
        env["ENGAGE_INFRASTRUCTURE_ROOT"] = infra if infra is not None else str(self.infra)
        return subprocess.run(
            [str(self.root / "scripts/package.sh"), *arguments],
            cwd=self.work, env=env, capture_output=True, text=True, check=False, timeout=10,
        )

    def expected(self, action: str, mode: str) -> list[str]:
        return [
            "package", action, "--env", "local", "--platform", "core",
            "--root", str(self.root), "--client", "client-one",
            "--package", self.package, mode, "--json",
        ]

    def test_plan_routes_to_shared_b51_with_explicit_bound_selection(self) -> None:
        for action in ("update", "repair"):
            with self.subTest(action=action):
                result = self.execute(action, "client-one", self.package, "--plan", "--json")
                self.assertEqual(0, result.returncode, result.stderr)
                self.assertEqual(self.expected(action, "--plan"), json.loads(result.stdout))
                self.assertNotIn(self.secret, result.stdout + result.stderr)

    def test_apply_preserves_exact_review_fingerprint_and_exit_status(self) -> None:
        fingerprint = "a" * 64
        for action in ("update", "repair"):
            with self.subTest(action=action):
                result = self.execute(action, "client-one", self.package,
                                      "--apply", "--confirm-plan", fingerprint, "--json")
                self.assertEqual(0, result.returncode, result.stderr)
                self.assertEqual(self.expected(action, "--apply")[:-1]
                                 + ["--confirm-plan", fingerprint, "--json"], json.loads(result.stdout))

    def test_cannot_inject_platform_client_root_or_environment_options(self) -> None:
        cases = (
            ("--root", "/var/www/elsewhere"), ("--platform", "seo"),
            ("--client", "other"), ("--env", "production"),
            ("--package", "vendor/another"), ("--plan", "--apply"),
            ("--plan", "--plan"), ("--weird",),
            ("--apply", "--confirm-plan", "bad"),
        )
        for opts in cases:
            with self.subTest(opts=opts):
                args = ("update", "client-one", self.package, *opts)
                if opts[0] not in ("--plan", "--apply"):
                    args = (*args, "--plan")
                result = self.execute(*args)
                self.assertEqual(2, result.returncode)
                self.assertEqual("", result.stdout)

    def test_missing_mode_or_invalid_identity_is_refused(self) -> None:
        for args in (
            ("update", "client-one", self.package),
            ("repair", "../wrong", self.package, "--plan"),
            ("repair", "client-one", "../escape", "--plan"),
            ("update", "client-one", self.package, "--plan", "--confirm-plan", "a" * 64),
        ):
            with self.subTest(args=args):
                result = self.execute(*args)
                self.assertEqual(2, result.returncode)
                self.assertEqual("", result.stdout)

    def test_explicit_missing_or_incomplete_infrastructure_never_falls_back(self) -> None:
        for infra in (self.work / "missing", self.work / "broken"):
            if infra.name == "broken":
                infra.mkdir()
            result = self.execute("repair", "client-one", self.package, "--plan", infra=str(infra))
            self.assertNotEqual(0, result.returncode)
            self.assertEqual("", result.stdout)

    def test_symlinked_infrastructure_root_or_scripts_are_rejected(self) -> None:
        link = self.work / "linked-infrastructure"
        link.symlink_to(self.infra, target_is_directory=True)
        result = self.execute("update", "client-one", self.package, "--plan", infra=str(link))
        self.assertEqual(1, result.returncode)
        self.assertEqual("", result.stdout)
        # Recreate the directory tree to simulate a symlinked scripts/lib path.
        moved = self.infra / "separate-lib"
        (self.infra / "scripts/lib").rename(moved)
        (self.infra / "scripts/lib").symlink_to(moved, target_is_directory=True)
        result = self.execute("repair", "client-one", self.package, "--plan")
        self.assertEqual(1, result.returncode)
        self.assertEqual("", result.stdout)

    def test_regular_existing_commands_remain_unchanged(self) -> None:
        # Only the package revision dispatch was changed; other action
        # branches must still be recognizable and independently supported.
        source = (self.root / "scripts/package.sh").read_text()
        self.assertIn("link)", source)
        self.assertIn("unlink)", source)
        self.assertIn("audit", source)

    def test_missing_default_infrastructure_behavior(self) -> None:
        env = dict(os.environ)
        env.pop("ENGAGE_INFRASTRUCTURE_ROOT", None)
        env["HOME"] = str(self.work / "isolated-home")
        result = subprocess.run(
            [str(self.root / "scripts/package.sh"), "repair", "client-one", self.package, "--plan", "--json"],
            env=env, cwd=self.work, capture_output=True, text=True, timeout=10, check=False,
        )
        if "core" == "core":
            self.assertEqual(0, result.returncode, result.stderr)
            self.assertEqual(["legacy", "repair", "client-one", self.package, "--plan", "--json"],
                             json.loads(result.stdout))
        else:
            self.assertEqual(1, result.returncode)
            self.assertIn("Infrastructure B51", result.stderr)
            self.assertEqual("", result.stdout)


if __name__ == "__main__":
    unittest.main()