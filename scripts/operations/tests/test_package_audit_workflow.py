"""Read-only package evidence tests; no .env, Composer, PHP, network or database."""

import importlib.util
import io
import contextlib
import sys
import json
from pathlib import Path
import tempfile
import unittest
from unittest import mock

MODULE_PATH = Path(__file__).resolve().parents[1] / "lib" / "package_workflow.py"
SPEC = importlib.util.spec_from_file_location("package_workflow", MODULE_PATH)
workflow = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(workflow)


class PackageLifecycleAuditTest(unittest.TestCase):
    def setUp(self):
        temp = tempfile.TemporaryDirectory()
        self.addCleanup(temp.cleanup)
        self.root = Path(temp.name) / "engage-core"
        self.root.mkdir()
        (self.root / "artisan").write_text("placeholder")
        (self.root / "bootstrap").mkdir()
        (self.root / "bootstrap" / "app.php").write_text("<?php\n")
        self.client = self.root / "client" / "fixture"
        (self.client / "config").mkdir(parents=True)
        (self.client / "config" / "client.php").write_text("<?php return [];\n")
        root_patch = mock.patch.object(workflow, "ROOT", self.root)
        root_patch.start()
        self.addCleanup(root_patch.stop)

    def write_json(self, path: Path, data: dict):
        path.parent.mkdir(parents=True, exist_ok=True)
        path.write_text(json.dumps(data, indent=2) + "\n")

    def selected_package(self, name="imagine-social/engage-fixture"):
        self.write_json(self.client / "composer.json", {
            "name": "imagine-social/client-fixture",
            "require": {"php": "^8.3", name: "dev-main"},
            "repositories": {"package": {"type": "vcs", "url": "git@github.com:org/example.git"}},
        })
        self.write_json(self.client / "composer.lock", {
            "packages": [{"name": name, "version": "dev-main", "source": {"reference": "abcdef123"}}],
            "packages-dev": [],
            "content-hash": "deliberately-not-verified",
        })

    def errors(self, report):
        return {finding["code"] for finding in report["findings"] if finding["severity"] == "error"}

    def warnings(self, report):
        return {finding["code"] for finding in report["findings"] if finding["severity"] == "warning"}

    def test_empty_client_needs_no_manifests_or_composer_and_does_not_read_env(self):
        with mock.patch.object(workflow, "run", side_effect=AssertionError("must not run")):
            result = workflow.audit_client_package_lifecycle(self.client)
        self.assertEqual("absent", result["composer"]["state"])
        self.assertEqual("absent", result["manifest"]["mode"])
        self.assertEqual([], result["packages"])
        self.assertEqual(set(), self.errors(result))

    def test_hand_maintained_manifest_is_not_executed_or_rewritten(self):
        self.selected_package()
        manifest = self.client / "config" / "client_packages.php"
        manifest.write_text("<?php throw new Exception('MUST NOT EXECUTE');\n")
        files = [self.client / "composer.json", self.client / "composer.lock", manifest]
        before = {str(path): (path.read_bytes(), path.stat().st_mtime_ns) for path in files}
        with mock.patch.object(workflow, "run", side_effect=AssertionError("must not run")):
            result = workflow.audit_client_package_lifecycle(self.client)
        self.assertEqual("hand_maintained", result["manifest"]["mode"])
        self.assertEqual("not_evaluated", result["manifest"]["state"])
        self.assertEqual(set(), self.errors(result))
        self.assertEqual("abcdef123", result["lock"]["locked_packages"]["imagine-social/engage-fixture"]["source_reference"])
        self.assertEqual(before, {str(path): (path.read_bytes(), path.stat().st_mtime_ns) for path in files})

    def test_matching_managed_registry_and_generated_manifest(self):
        self.selected_package()
        descriptor = workflow.make_descriptor("engage-fixture", "vertical", "ImagineSocialGit", "core")
        self.write_json(self.client / "config" / workflow.REGISTRY_NAME,
                        {"schema": 1, "packages": [descriptor]})
        (self.client / "config" / "client_packages.php").write_text(
            workflow.manifest_php({"schema": 1, "packages": [descriptor]})
        )
        result = workflow.audit_client_package_lifecycle(self.client)
        self.assertEqual("managed", result["manifest"]["mode"])
        self.assertEqual("matches", result["manifest"]["state"])
        self.assertEqual(set(), self.errors(result))
        self.assertEqual(True, result["packages"][0]["registered_in_managed_registry"])
        self.assertEqual("absent", result["packages"][0]["vendor"])
        self.assertIn("autoload_absent", self.warnings(result))

    def test_managed_manifest_drift_and_missing_lock_are_evidence_not_repair(self):
        self.selected_package()
        (self.client / "composer.lock").unlink()
        descriptor = workflow.make_descriptor("engage-fixture", "vertical", "ImagineSocialGit", "core")
        self.write_json(self.client / "config" / workflow.REGISTRY_NAME,
                        {"schema": 1, "packages": [descriptor]})
        manifest = self.client / "config" / "client_packages.php"
        manifest.write_text("<?php return [];\n")
        snapshot = manifest.read_bytes()
        result = workflow.audit_client_package_lifecycle(self.client)
        self.assertEqual({"missing_lock", "managed_manifest_drift"}, self.errors(result))
        self.assertEqual(snapshot, manifest.read_bytes())
        self.assertFalse((self.client / "composer.lock").exists())

    def test_orphan_generated_marker_is_not_mistaken_for_custom_manifest(self):
        (self.client / "config" / "client_packages.php").write_text(
            "<?php\n\n" + workflow.GENERATED_HEADER + "\nreturn [];\n")
        result = workflow.audit_client_package_lifecycle(self.client)
        self.assertEqual("orphan_generated", result["manifest"]["mode"])
        self.assertIn("missing_managed_registry", self.errors(result))

    def test_missing_lock_entry_cannot_be_reported_as_locked(self):
        self.selected_package()
        self.write_json(self.client / "composer.lock", {"packages": [], "packages-dev": []})
        report = workflow.audit_client_package_lifecycle(self.client)
        self.assertIn("package_not_locked", self.errors(report))
        self.assertIsNone(report["packages"][0]["lock"])

    def test_source_descriptor_is_optional_and_matching_source_is_compared(self):
        self.selected_package()
        source = self.root.parent / "engage-fixture"
        source.mkdir()
        desc = workflow.make_descriptor("engage-fixture", "vertical", "ImagineSocialGit", "core")
        files = workflow.package_contents(desc, "sample")
        for name, content in files.items():
            target = source / name
            target.parent.mkdir(parents=True, exist_ok=True)
            target.write_text(content)
        report = workflow.audit_client_package_lifecycle(self.client, [source])
        self.assertEqual("valid", report["sources"][0]["descriptor"])
        self.assertEqual(True, report["sources"][0]["selected_in_client"])
        (source / "engage-package.json").unlink()
        report = workflow.audit_client_package_lifecycle(self.client, [source])
        self.assertEqual("missing", report["sources"][0]["descriptor"])
        self.assertIn("descriptor_missing", self.warnings(report))
        self.assertEqual(set(), self.errors(report))

    def test_invalid_source_descriptor_reports_error_without_invoking_installer(self):
        source = self.root.parent / "engage-fixture"
        source.mkdir()
        self.write_json(source / "composer.json", {"name": "imagine-social/engage-fixture"})
        self.write_json(source / "engage-package.json", {"name": "imagine-social/engage-fixture", "schema": 99})
        with mock.patch.object(workflow, "install", side_effect=AssertionError("install called")):
            report = workflow.audit_client_package_lifecycle(self.client, [source])
        self.assertIn("invalid_descriptor", self.errors(report))

    def test_command_json_is_machine_readable_and_returns_failure_on_missing_lock(self):
        self.selected_package()
        output = io.StringIO()
        with mock.patch.object(sys, "argv", ["package_workflow.py", "audit", "fixture", "--json"]), \
             contextlib.redirect_stdout(output):
            self.assertEqual(0, workflow.main())
        result = json.loads(output.getvalue())
        self.assertEqual("read_only", result["mode"])
        self.assertEqual("valid", result["lock"]["state"])
        (self.client / "composer.lock").unlink()
        output = io.StringIO()
        with mock.patch.object(sys, "argv", ["package_workflow.py", "audit", "fixture", "--json"]), \
             contextlib.redirect_stdout(output):
            self.assertEqual(1, workflow.main())
        self.assertIn("missing_lock", self.errors(json.loads(output.getvalue())))

    def test_no_client_or_root_env_or_commands_are_needed_by_audit(self):
        self.selected_package()
        self.assertFalse((self.root / ".env").exists())
        with mock.patch.object(workflow, "run", side_effect=AssertionError("invoked external command")), \
             mock.patch.object(workflow, "read_client_env_value", side_effect=AssertionError("read env")):
            report = workflow.audit_client_package_lifecycle(self.client)
        self.assertEqual("valid", report["composer"]["state"])
        self.assertIn("Module/feature activation and migration state", report["not_verified"])


if __name__ == "__main__":
    unittest.main()