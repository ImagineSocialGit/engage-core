"""Local Composer revision update/recovery contract, without network or credentials."""

from __future__ import annotations

import contextlib
import io
import json
from pathlib import Path
import subprocess
import sys
import tempfile
import unittest
from unittest import mock

LIB = Path(__file__).resolve().parents[1] / "lib"
sys.path.insert(0, str(LIB))
import package_workflow as core
import package_revision_workflow as revisions


NAME = "imagine-social/engage-example"
OLD = "a" * 40
NEW = "b" * 40
URL = "git@github.com:ImagineSocialGit/engage-example.git"


class PackageRevisionWorkflowTest(unittest.TestCase):
    def setUp(self):
        temporary = tempfile.TemporaryDirectory()
        self.addCleanup(temporary.cleanup)
        self.root = Path(temporary.name) / "engage-core"
        self.root.mkdir()
        (self.root / "artisan").write_text("fixture")
        (self.root / "bootstrap").mkdir()
        (self.root / "bootstrap" / "app.php").write_text("<?php\n")
        self.client = self.root / "client" / "fixture"
        (self.client / "config").mkdir(parents=True)
        (self.client / "config" / "client.php").write_text("<?php return [];\n")
        (self.root / ".env").write_text("APP_ENV=local\nCLIENT_KEY=fixture\n")
        self.write_json(self.client / "composer.json", {
            "name": "imagine-social/client-fixture",
            "require": {"php": "^8.3", NAME: "dev-main"},
            "repositories": [{"type": "vcs", "url": URL}],
        })
        self.write_lock(OLD)
        self.package = self.client / "vendor" / NAME
        self.package.mkdir(parents=True)
        (self.client / "vendor" / "autoload.php").write_text("<?php\n")
        self.write_installed(OLD)
        root_patch = mock.patch.object(core, "ROOT", self.root)
        root_patch.start()
        self.addCleanup(root_patch.stop)
        self.permissions = mock.patch.object(core, "verify_managed_runtime_permissions")
        self.permissions.start()
        self.addCleanup(self.permissions.stop)
        self.prepare_permissions = mock.patch.object(core, "ensure_managed_runtime_permissions")
        self.prepare_permissions.start()
        self.addCleanup(self.prepare_permissions.stop)
        self.require_command = mock.patch.object(core, "require_command")
        self.require_command.start()
        self.addCleanup(self.require_command.stop)

    def write_json(self, path: Path, payload: dict):
        path.parent.mkdir(parents=True, exist_ok=True)
        path.write_text(json.dumps(payload, indent=2) + "\n")

    def write_lock(self, reference: str, *, other_reference: str | None = None):
        packages = [{"name": NAME, "version": "dev-main", "source": {"type": "git", "url": URL, "reference": reference}}]
        if other_reference is not None:
            packages.append({"name": "vendor/other", "version": "1.0", "source": {"reference": other_reference}})
        self.write_json(self.client / "composer.lock", {"packages": packages, "packages-dev": []})

    def write_installed(self, reference: str):
        self.write_json(self.client / "vendor/composer/installed.json", {
            "packages": [{"name": NAME, "version": "dev-main", "source": {"reference": reference}}]
        })

    def fake_run(self, command, *, cwd=None, capture=False):
        if command[:2] == ["composer", "update"]:
            self.write_lock(NEW)
            self.write_installed(NEW)
        elif command[:2] == ["composer", "install"]:
            reference = revisions.locked_source(revisions.lock_packages(
                json.loads((self.client / "composer.lock").read_text()))[NAME])[1]
            self.write_installed(reference)
        elif command[0] != "composer":
            raise AssertionError(f"unexpected command {command}")
        return ""

    def test_plan_is_read_only_without_client_env_or_composer_execution(self):
        watched = [self.client / "composer.json", self.client / "composer.lock",
                   self.client / "vendor/composer/installed.json"]
        before = {str(p): (p.read_bytes(), p.stat().st_mtime_ns) for p in watched}
        with mock.patch.object(core, "validate_client", side_effect=AssertionError("must not read environment")), \
             mock.patch.object(core, "run", side_effect=AssertionError("must not execute commands")):
            result = revisions.inspect("fixture", NAME, "update")
        self.assertEqual("dist_match", result["installed"]["state"])
        self.assertFalse(result["client_source_files_written"])
        self.assertEqual(before, {str(p): (p.read_bytes(), p.stat().st_mtime_ns) for p in watched})

    def test_successful_target_only_update_and_idempotent_followup(self):
        plan = revisions.inspect("fixture", NAME, "update")
        with mock.patch.object(core, "run", side_effect=self.fake_run):
            result = revisions.reconcile("fixture", NAME, "update", plan["approval_fingerprint"])
        self.assertEqual(NEW, result["installed_revision"])
        self.assertTrue(result["revision_changed"])
        self.assertTrue(result["installed_revision_verified"])
        self.assertEqual("dist_match", revisions.inspect("fixture", NAME, "update")["installed"]["state"])

    def test_matching_lock_pinned_repair_preserves_lock(self):
        lock = (self.client / "composer.lock").read_bytes()
        plan = revisions.inspect("fixture", NAME, "repair")
        with mock.patch.object(core, "run", side_effect=self.fake_run):
            result = revisions.reconcile("fixture", NAME, "repair", plan["approval_fingerprint"])
        self.assertFalse(result["revision_changed"])
        self.assertEqual(lock, (self.client / "composer.lock").read_bytes())

    def test_missing_dist_installed_metadata_is_repairable_not_updateable(self):
        (self.client / "vendor/composer/installed.json").unlink()
        with self.assertRaisesRegex(revisions.RevisionError, "repair operation first"):
            revisions.inspect("fixture", NAME, "update")
        plan = revisions.inspect("fixture", NAME, "repair")
        self.assertEqual("unverified_dist", plan["installed"]["state"])
        with mock.patch.object(core, "run", side_effect=self.fake_run):
            revisions.reconcile("fixture", NAME, "repair", plan["approval_fingerprint"])
        self.assertEqual("dist_match", revisions.inspect("fixture", NAME, "update")["installed"]["state"])

    def test_missing_vendor_autoload_requires_repair_not_update(self):
        (self.client / "vendor" / "autoload.php").unlink()
        with self.assertRaisesRegex(revisions.RevisionError, "repair operation first"):
            revisions.inspect("fixture", NAME, "update")
        self.assertEqual("autoload_missing", revisions.inspect("fixture", NAME, "repair")["installed"]["state"])

    def test_package_local_symlink_is_never_replaced(self):
        self.package.rmdir()
        checkout = self.root.parent / "checkout"
        checkout.mkdir()
        self.package.symlink_to(checkout, target_is_directory=True)
        with self.assertRaisesRegex(revisions.RevisionError, "symlinked"):
            revisions.inspect("fixture", NAME, "repair")
        self.assertTrue(self.package.is_symlink())

    def test_wrong_or_missing_package_cannot_mutate_another_client(self):
        for name in ("vendor/unrelated", "imagine-social/engage-unselected"):
            with self.subTest(name=name), self.assertRaises(revisions.RevisionError):
                revisions.inspect("fixture", name, "update")
        with self.assertRaises(revisions.RevisionError):
            revisions.inspect("other", NAME, "update")

    def test_wrong_fingerprint_and_stale_review_block_before_composer(self):
        plan = revisions.inspect("fixture", NAME, "update")
        with mock.patch.object(core, "run", side_effect=AssertionError("must not mutate")):
            with self.assertRaisesRegex(revisions.RevisionError, "exact fingerprint"):
                revisions.reconcile("fixture", NAME, "update", "not-reviewed")
        self.write_json(self.client / "composer.json", {"require": {NAME: "dev-main"}, "description": "changed"})
        with self.assertRaisesRegex(revisions.RevisionError, "exact fingerprint"):
            revisions.reconcile("fixture", NAME, "update", plan["approval_fingerprint"])

    def test_wrong_root_selected_client_rejected_before_composer(self):
        plan = revisions.inspect("fixture", NAME, "update")
        (self.root / ".env").write_text("APP_ENV=production\nCLIENT_KEY=fixture\n")
        with mock.patch.object(core, "run", side_effect=AssertionError("must not mutate")):
            with self.assertRaises(core.WorkflowError):
                revisions.reconcile("fixture", NAME, "update", plan["approval_fingerprint"])

    def test_unrelated_lock_change_causes_verified_rollback(self):
        self.write_lock(OLD, other_reference="x")
        original = (self.client / "composer.lock").read_bytes()
        plan = revisions.inspect("fixture", NAME, "update")
        def changed(command, *, cwd=None, capture=False):
            if command[:2] == ["composer", "update"]:
                self.write_lock(NEW, other_reference="y")
                self.write_installed(NEW)
            elif command[:2] == ["composer", "install"]:
                self.write_installed(OLD)
            elif command[0] != "composer":
                raise AssertionError(command)
            return ""
        with mock.patch.object(core, "run", side_effect=changed):
            with self.assertRaisesRegex(revisions.RevisionError, "original lock and installed package were recovered"):
                revisions.reconcile("fixture", NAME, "update", plan["approval_fingerprint"])
        self.assertEqual(original, (self.client / "composer.lock").read_bytes())
        self.assertEqual("dist_match", revisions.inspect("fixture", NAME, "update")["installed"]["state"])

    def test_incomplete_rollback_fails_closed(self):
        plan = revisions.inspect("fixture", NAME, "update")
        def broken(command, *, cwd=None, capture=False):
            if command[:2] == ["composer", "update"]:
                self.write_lock(NEW)
                raise core.WorkflowError("update interrupted")
            if command[:2] == ["composer", "install"]:
                raise core.WorkflowError("recover interrupted")
            return ""
        with mock.patch.object(core, "run", side_effect=broken):
            with self.assertRaisesRegex(revisions.RevisionError, "recovery is not verified"):
                revisions.reconcile("fixture", NAME, "update", plan["approval_fingerprint"])
        self.assertEqual(OLD, json.loads((self.client / "composer.lock").read_text())["packages"][0]["source"]["reference"])

    def test_source_git_checkout_missing_composer_remote_repaired_from_lock(self):
        self.assertEqual(0, subprocess.run(["git", "init", "-q", str(self.package)], capture_output=True).returncode)
        (self.package / "composer.json").write_text('{"name": "' + NAME + '"}\n')
        subprocess.run(["git", "-C", str(self.package), "add", "."], check=True, capture_output=True)
        subprocess.run(["git", "-C", str(self.package), "-c", "user.name=Fixture", "-c", "user.email=f@example.test",
                        "commit", "-qm", "fixture"], check=True, capture_output=True)
        head = subprocess.run(["git", "-C", str(self.package), "rev-parse", "HEAD"], check=True,
                              capture_output=True, text=True).stdout.strip()
        self.write_lock(head)
        self.write_installed(head)
        plan = revisions.inspect("fixture", NAME, "repair")
        self.assertTrue(plan["remote_repair_required"])
        self.assertEqual("source_match", plan["installed"]["state"])
        def run_with_git(command, *, cwd=None, capture=False):
            if command[0] == "git":
                subprocess.run(command, check=True, capture_output=True)
            return ""
        with mock.patch.object(core, "run", side_effect=run_with_git):
            revisions.reconcile("fixture", NAME, "repair", plan["approval_fingerprint"])
        self.assertEqual(URL, subprocess.run(["git", "-C", str(self.package), "remote", "get-url", "composer"],
                                             check=True, capture_output=True, text=True).stdout.strip())

    def test_conflicting_composer_remote_refused_without_repair(self):
        subprocess.run(["git", "init", "-q", str(self.package)], check=True)
        (self.package / "composer.json").write_text('{"name": "' + NAME + '"}\n')
        subprocess.run(["git", "-C", str(self.package), "add", "."], check=True)
        subprocess.run(["git", "-C", str(self.package), "-c", "user.name=Fixture", "-c", "user.email=f@example.test",
                        "commit", "-qm", "fixture"], check=True)
        head = subprocess.run(["git", "-C", str(self.package), "rev-parse", "HEAD"], check=True,
                              capture_output=True, text=True).stdout.strip()
        self.write_lock(head)
        subprocess.run(["git", "-C", str(self.package), "remote", "add", "composer", "git@github.com:other/wrong.git"], check=True)
        with self.assertRaisesRegex(revisions.RevisionError, "differs from composer.lock"):
            revisions.inspect("fixture", NAME, "repair")

    def test_repair_refuses_symlinked_composer_metadata_and_vendor_namespace(self):
        metadata = self.client / "vendor/composer/installed.json"
        original = metadata.read_bytes()
        metadata.unlink()
        external = self.root.parent / "metadata.json"
        external.write_bytes(original)
        metadata.symlink_to(external)
        with self.assertRaisesRegex(revisions.RevisionError, "metadata path is unsafe"):
            revisions.inspect("fixture", NAME, "repair")
        metadata.unlink()
        metadata.write_bytes(original)
        self.package.rmdir()
        (self.client / "vendor/imagine-social").rmdir()
        checkout = self.root.parent / "external-packages"
        (checkout / "engage-example").mkdir(parents=True)
        (self.client / "vendor/imagine-social").symlink_to(checkout, target_is_directory=True)
        with self.assertRaisesRegex(revisions.RevisionError, "unsafe symlink"):
            revisions.inspect("fixture", NAME, "repair")

    def test_changed_composer_json_during_update_is_restored_on_rollback(self):
        original_composer = (self.client / "composer.json").read_bytes()
        review = revisions.inspect("fixture", NAME, "update")
        def changed(command, *, cwd=None, capture=False):
            if command[:2] == ["composer", "update"]:
                self.write_json(self.client / "composer.json", {"description": "unwanted change"})
                self.write_lock(NEW)
                self.write_installed(NEW)
            elif command[:2] == ["composer", "install"]:
                self.write_installed(OLD)
            return ""
        with mock.patch.object(core, "run", side_effect=changed):
            with self.assertRaisesRegex(revisions.RevisionError, "original lock and installed package were recovered"):
                revisions.reconcile("fixture", NAME, "update", review["approval_fingerprint"])
        self.assertEqual(original_composer, (self.client / "composer.json").read_bytes())
        self.assertEqual(OLD, json.loads((self.client / "composer.lock").read_text())["packages"][0]["source"]["reference"])

    def test_runtime_readiness_guard_is_checked_before_composer_update(self):
        review = revisions.inspect("fixture", NAME, "update")
        with mock.patch.object(core, "verify_managed_runtime_permissions", side_effect=core.WorkflowError("unsafe runtime")):
            with mock.patch.object(core, "run", side_effect=AssertionError("must not run")):
                with self.assertRaisesRegex(core.WorkflowError, "unsafe runtime"):
                    revisions.reconcile("fixture", NAME, "update", review["approval_fingerprint"])

    def test_cli_plan_output_does_not_include_git_credentials_or_provider_values(self):
        output = io.StringIO()
        with contextlib.redirect_stdout(output):
            code = revisions.main(["update", "fixture", NAME, "--plan", "--json"])
        self.assertEqual(0, code)
        self.assertNotIn(URL, output.getvalue())
        self.assertNotIn("STRIPE", output.getvalue())
        self.assertEqual("package_revision_plan", json.loads(output.getvalue())["status"])


if __name__ == "__main__":
    unittest.main()