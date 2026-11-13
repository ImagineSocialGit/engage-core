"""Development-only source creation guard: no external tools or GitHub calls."""

import argparse
import importlib.util
import os
from pathlib import Path
import subprocess
import sys
import tempfile
import unittest
from unittest import mock

LIB = Path(__file__).resolve().parents[1] / "lib"
SCRIPT = Path(__file__).resolve().parents[2] / "create-client.sh"


def load(filename: str, name: str):
    spec = importlib.util.spec_from_file_location(name, LIB / filename)
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


guard = load("creation_environment.py", "creation_environment")
workflow = load("package_workflow.py", "package_workflow")


class CreationEnvironmentTest(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name) / "engage-core"
        self.root.mkdir()
        (self.root / "artisan").touch()
        (self.root / "bootstrap").mkdir()
        (self.root / "bootstrap/app.php").touch()

    def set_env(self, value):
        if value is None:
            (self.root / ".env").unlink(missing_ok=True)
        else:
            (self.root / ".env").write_text(value)

    def test_local_accepts_standard_and_quoted_env(self):
        for data in ("APP_ENV=local\n", "# APP_ENV=production\nAPP_ENV='local'\n", 'APP_ENV="local"\n'):
            with self.subTest(data=data):
                self.set_env(data)
                guard.require_local_creation(self.root, "Package creation")

    def test_non_local_missing_and_ambiguous_fail_closed(self):
        for data in (None, "", "# APP_ENV=local\n", "APP_ENV=production\n",
                     "APP_ENV=staging\n", "APP_ENV=testing\n",
                     "APP_ENV=local\nAPP_ENV=local\n", "APP_ENV=LOCAL\n"):
            with self.subTest(data=data):
                self.set_env(data)
                with self.assertRaises(guard.CreationEnvironmentError):
                    guard.require_local_creation(self.root, "Package creation")

    def test_process_env_override_does_not_bypass_checkout(self):
        self.set_env("APP_ENV=production\n")
        with mock.patch.dict(os.environ, {"APP_ENV": "local"}):
            with self.assertRaises(guard.CreationEnvironmentError):
                guard.require_local_creation(self.root, "Client creation")

    def test_package_creator_rejects_non_local_before_external_actions(self):
        self.set_env("APP_ENV=production\n")
        args = argparse.Namespace(name="engage-seo-sample", type="vertical", platform="seo",
                                  owner="ImagineSocialGit", description="Sample", parent=None,
                                  modules=None, dry_run=False)
        with mock.patch.object(workflow, "ROOT", self.root), \
             mock.patch.object(workflow, "run", side_effect=AssertionError("external action")), \
             mock.patch.object(workflow, "require_command", side_effect=AssertionError("external check")):
            with self.assertRaises(workflow.WorkflowError) as caught:
                workflow.create(args)
        self.assertIn("APP_ENV", str(caught.exception))
        self.assertFalse((self.root.parent / args.name).exists())

    def test_package_dry_run_remains_read_only_without_env(self):
        self.set_env(None)
        args = argparse.Namespace(name="engage-seo-sample", type="vertical", platform="seo",
                                  owner="ImagineSocialGit", description="Sample", parent=None,
                                  modules=None, dry_run=True)
        with mock.patch.object(workflow, "ROOT", self.root), \
             mock.patch.object(workflow, "run", side_effect=AssertionError("external action")), \
             mock.patch.object(workflow, "require_command", side_effect=AssertionError("external check")):
            workflow.create(args)
        self.assertFalse((self.root.parent / args.name).exists())

    def test_client_creator_rejects_non_local_before_external_actions(self):
        # The script resolves its checkout relative to its own path. Use a
        # fake checkout with only this script + shared guard, and an invalid
        # environment; rejection must occur before any runtime prerequisites.
        self.set_env("APP_ENV=production\n")
        fake_scripts = self.root / "scripts"
        fake_scripts.mkdir()
        (fake_scripts / "operations/lib").mkdir(parents=True)
        (fake_scripts / "create-client.sh").write_bytes(SCRIPT.read_bytes())
        (fake_scripts / "operations/lib/creation_environment.py").write_bytes(
            (LIB / "creation_environment.py").read_bytes()
        )
        result = subprocess.run(
            ["bash", str(fake_scripts / "create-client.sh"), "sample-client", "America/Chicago"],
            capture_output=True, text=True, env={**os.environ, "APP_ENV": "local"},
        )
        self.assertNotEqual(0, result.returncode)
        self.assertIn("APP_ENV", result.stderr)
        self.assertFalse((self.root / "client").exists())

    def test_client_help_is_available_without_env(self):
        self.set_env(None)
        result = subprocess.run(["bash", str(SCRIPT), "--help"], capture_output=True, text=True)
        self.assertEqual(0, result.returncode)
        self.assertIn("Usage:", result.stdout)


if __name__ == "__main__":
    unittest.main()