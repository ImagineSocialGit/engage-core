"""Core module-group and legacy manifest adoption safety checks."""

import contextlib
import importlib.util
import io
import json
from pathlib import Path
import subprocess
import sys
import tempfile
import unittest
from unittest import mock

MODULE_PATH = Path(__file__).resolve().parents[1] / "lib" / "package_workflow.py"
SPEC = importlib.util.spec_from_file_location("package_workflow", MODULE_PATH)
workflow = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(workflow)


class GroupDescriptorTest(unittest.TestCase):
    def setUp(self):
        self.group = workflow.make_descriptor(
            "engage-animal-services", "vertical-group", "ImagineSocialGit", "core",
            ["pet_profiles", "pet_bookings"],
        )

    def test_group_manifest_registers_independent_modules_and_selected_schema_only(self):
        package = json.loads(json.dumps(self.group))
        single = workflow.make_descriptor("engage-donations", "vertical", "ImagineSocialGit", "core")
        package["modules"][1]["migration_scope"] = True
        registry = {"schema": 1, "packages": [single, package]}
        rendered = workflow.manifest_php(registry)
        self.assertIn("\\Modules\\PetProfilesPackage::moduleDefinition()", rendered)
        self.assertIn("\\Modules\\PetBookingsPackage::moduleDefinition()", rendered)
        self.assertIn("\\Modules\\PetBookingsPackage::migrationScope()", rendered)
        self.assertNotIn("\\Modules\\PetProfilesPackage::migrationScope()", rendered)
        self.assertIn("\\EngageDonations\\DonationsPackage::moduleDefinition()", rendered)
        self.assertNotIn("'providers' => [\n        \\ImagineSocial\\EngageAnimalServices", rendered)

    def test_group_scaffold_has_valid_per_module_classes_providers_tests_and_is_not_enabled(self):
        generated = workflow.package_contents(self.group, "Testing package")
        self.assertIn("src/Modules/PetProfilesPackage.php", generated)
        self.assertIn("src/Modules/PetBookingsPackage.php", generated)
        self.assertIn("src/Providers/PetProfilesModuleServiceProvider.php", generated)
        self.assertIn("database/migrations/pet_profiles/.gitkeep", generated)
        self.assertIn("database/migrations/pet_bookings/.gitkeep", generated)
        self.assertIn("pet_profiles", generated["tests/Unit/PackageScaffoldTest.php"])
        self.assertIn("pet_bookings", generated["tests/Unit/PackageScaffoldTest.php"])
        self.assertNotIn("'always_on' => true", "".join(generated.values()))
        with tempfile.TemporaryDirectory() as temp:
            for rel, contents in generated.items():
                destination = Path(temp) / rel
                destination.parent.mkdir(parents=True, exist_ok=True)
                destination.write_text(contents)
                if destination.suffix == ".php":
                    result = subprocess.run(["php", "-l", str(destination)], capture_output=True, text=True)
                    self.assertEqual(0, result.returncode, result.stderr)

    def test_source_descriptor_requires_each_opted_in_schema_directory_to_have_real_migrations(self):
        with tempfile.TemporaryDirectory() as temp:
            source = Path(temp) / "engage-animal-services"
            for name, contents in workflow.package_contents(self.group, "Testing package").items():
                file = source / name
                file.parent.mkdir(parents=True, exist_ok=True)
                file.write_text(contents)
            self.assertEqual(self.group, workflow.validated_descriptor(source))
            self.group["modules"][1]["migration_scope"] = True
            (source / "engage-package.json").write_text(json.dumps(self.group))
            with self.assertRaises(workflow.WorkflowError):
                workflow.validated_descriptor(source)
            migration = source / "database/migrations/pet_bookings/2099_01_01_000000_create_pet_bookings.php"
            migration.write_text("<?php return [];\n")
            self.assertEqual(self.group, workflow.validated_descriptor(source))

    def test_group_scaffold_cli_dry_run_never_creates_package_or_runs_remote_commands(self):
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary) / "engage-core"
            (root / "bootstrap").mkdir(parents=True)
            (root / "bootstrap/app.php").write_text("<?php\n")
            (root / "artisan").write_text("fixture")
            output = io.StringIO()
            with mock.patch.object(workflow, "ROOT", root), \
                 mock.patch.object(sys, "argv", [
                     "package_workflow.py", "create", "engage-animal-services", "--platform", "core",
                     "--type", "vertical-group", "--modules", "pet_profiles,pet_bookings",
                     "--description", "Test group", "--dry-run",
                 ]), contextlib.redirect_stdout(output), \
                 mock.patch.object(workflow, "run", side_effect=AssertionError("must not invoke external commands")):
                self.assertEqual(0, workflow.main())
            self.assertIn("pet_profiles, pet_bookings", output.getvalue())
            self.assertFalse((root.parent / "engage-animal-services").exists())

    def test_bad_modules_rejected_and_mixed_singular_group_metadata_rejected(self):
        for keys in ([], ["one"], ["one", "one"], ["one", "Invalid-Key"], ["foo_bar", "foo__bar"]):
            with self.subTest(keys=keys), self.assertRaises(workflow.WorkflowError):
                workflow.make_descriptor("engage-animal-services", "vertical-group", "Org", "core", keys)
        with self.assertRaises(workflow.WorkflowError):
            workflow.make_descriptor("engage-seo-animals", "vertical-group", "Org", "seo", ["one", "two"])
        with self.assertRaises(workflow.WorkflowError):
            workflow.make_descriptor("engage-animals", "vertical", "Org", "core", ["one", "two"])
        invalid = json.loads(json.dumps(self.group))
        invalid["module_key"] = "pet_profiles"
        with self.assertRaises(workflow.WorkflowError):
            workflow.validate_registry({"schema": 1, "packages": [invalid]})

    def test_collisions_across_singular_and_group_are_fatal(self):
        existing = workflow.make_descriptor("engage-pet-profiles", "vertical", "Org", "core")
        with self.assertRaises(workflow.WorkflowError):
            workflow.manifest_php({"schema": 1, "packages": [existing, self.group]})
        other = json.loads(json.dumps(self.group))
        other["name"] = "imagine-social/engage-other"
        with self.assertRaises(workflow.WorkflowError):
            workflow.validate_registry({"schema": 1, "packages": [self.group, other]})


    def test_group_verification_checks_each_child_module_class(self):
        with tempfile.TemporaryDirectory() as temporary:
            client = Path(temporary)
            vendor_package = client / "vendor" / self.group["name"]
            vendor_package.mkdir(parents=True)
            seen = []

            def record(command, **kwargs):
                seen.append(command)
                return ""

            with mock.patch.object(workflow, "ROOT", client), \
                 mock.patch.object(workflow, "run", side_effect=record):
                workflow.verify_installed(client, self.group)
            classes = [command[-1] for command in seen if command[:2] == ["php", "-r"]]
            self.assertEqual(
                [module["module_class"] for module in self.group["modules"]], classes,
            )
            self.assertEqual(1, len([command for command in seen if command[0] == "composer"]))



class AdoptionWorkflowTest(unittest.TestCase):
    def setUp(self):
        directory = tempfile.TemporaryDirectory()
        self.addCleanup(directory.cleanup)
        self.root = Path(directory.name) / "engage-core"
        (self.root / "bootstrap").mkdir(parents=True)
        (self.root / "artisan").write_text("fixture")
        (self.root / "bootstrap/app.php").write_text("<?php\n")
        self.client = self.root / "client/fixture"
        (self.client / "config").mkdir(parents=True)
        (self.client / "config/client.php").write_text("<?php return [];\n")
        self.manifest = self.client / "config/client_packages.php"
        self.manifest.write_text("<?php\n// legacy client-owned behavior\nreturn ['modules' => []];\n")
        self.descriptor = workflow.make_descriptor("engage-pet-services", "vertical", "Org", "core")
        self.registry = {"schema": 1, "packages": [self.descriptor]}
        self.draft = self.root.parent / "reviewed-registry.json"
        self.draft.write_text(json.dumps(self.registry))
        (self.client / "composer.json").write_text(json.dumps({
            "name": "imagine-social/client-fixture",
            "require": {"php": "^8.3", self.descriptor["name"]: "dev-main"},
        }))
        (self.client / "composer.lock").write_text(json.dumps({
            "packages": [{"name": self.descriptor["name"], "version": "dev-main"}],
            "packages-dev": [],
        }))
        self.root_patch = mock.patch.object(workflow, "ROOT", self.root)
        self.root_patch.start()
        self.addCleanup(self.root_patch.stop)

    def argv(self, *args):
        return mock.patch.object(sys, "argv", ["package_workflow.py", "adopt", "fixture", "--registry", str(self.draft), *args])

    def test_adoption_review_is_read_only_without_env_or_php_execution(self):
        before = {p: (p.read_bytes(), p.stat().st_mtime_ns) for p in
                  (self.manifest, self.draft, self.client / "composer.json", self.client / "composer.lock")}
        output = io.StringIO()
        with self.argv(), contextlib.redirect_stdout(output), \
             mock.patch.object(workflow, "run", side_effect=AssertionError("must not execute")), \
             mock.patch.object(workflow, "read_client_env_value", side_effect=AssertionError("must not read .env")):
            self.assertEqual(0, workflow.main())
        plan = workflow.adoption_plan(self.client, self.draft)
        self.assertIn("legacy client-owned behavior", output.getvalue())
        self.assertIn(plan["fingerprint"], output.getvalue())
        self.assertEqual(before, {p: (p.read_bytes(), p.stat().st_mtime_ns) for p in before})
        self.assertFalse((self.client / "config" / workflow.REGISTRY_NAME).exists())

    def test_explicit_apply_has_active_local_client_guard_and_requires_fingerprint(self):
        p = workflow.adoption_plan(self.client, self.draft)
        with self.argv("--apply", "--confirm-plan", p["fingerprint"]), contextlib.redirect_stdout(io.StringIO()):
            self.assertEqual(1, workflow.main())  # root .env deliberately absent
        self.assertFalse((self.client / "config" / workflow.REGISTRY_NAME).exists())
        (self.root / ".env").write_text("APP_ENV=local\nCLIENT_KEY=fixture\n")
        with self.argv("--apply", "--confirm-plan", "bad"), contextlib.redirect_stdout(io.StringIO()):
            self.assertEqual(1, workflow.main())
        self.assertEqual(p["original_manifest"], self.manifest.read_bytes())

    def test_reviewed_adoption_writes_exact_managed_files_without_composer(self):
        (self.root / ".env").write_text("APP_ENV=local\nCLIENT_KEY=fixture\n")
        plan = workflow.adoption_plan(self.client, self.draft)
        calls = []

        def safe_run(command, **kwargs):
            calls.append(command)
            if command[:2] == ["php", "-l"]:
                return ""
            if command[0] == sys.executable and "client_file_permissions.py" in command[1]:
                return ""
            raise AssertionError(f"unexpected command: {command}")

        with self.argv("--apply", "--confirm-plan", plan["fingerprint"]), \
             contextlib.redirect_stdout(io.StringIO()), mock.patch.object(workflow, "run", side_effect=safe_run):
            self.assertEqual(0, workflow.main())
        self.assertEqual(plan["manifest_text"], self.manifest.read_text())
        self.assertEqual(self.registry, json.loads((self.client / "config" / workflow.REGISTRY_NAME).read_text()))
        self.assertTrue(workflow.safe_registry(self.client)[1])
        self.assertEqual(2, len(calls))

    def test_source_drift_invalidates_review_fingerprint(self):
        plan = workflow.adoption_plan(self.client, self.draft)
        self.registry["packages"][0]["environment"] = {"PET_API": {"owner": "pet", "secret": True}}
        self.draft.write_text(json.dumps(self.registry))
        (self.root / ".env").write_text("APP_ENV=local\nCLIENT_KEY=fixture\n")
        with self.argv("--apply", "--confirm-plan", plan["fingerprint"]), contextlib.redirect_stdout(io.StringIO()):
            self.assertEqual(1, workflow.main())
        self.assertFalse((self.client / "config" / workflow.REGISTRY_NAME).exists())

    def test_reject_partial_registry_and_unlocked_requirements(self):
        self.draft.write_text(json.dumps({"schema": 1, "packages": []}))
        with self.assertRaises(workflow.WorkflowError):
            workflow.adoption_plan(self.client, self.draft)
        self.draft.write_text(json.dumps(self.registry))
        (self.client / "composer.lock").write_text('{"packages": [], "packages-dev": []}')
        with self.assertRaises(workflow.WorkflowError):
            workflow.adoption_plan(self.client, self.draft)

    def test_generated_or_managed_manifest_is_not_adopted(self):
        self.manifest.write_text(workflow.manifest_php(self.registry))
        with self.assertRaises(workflow.WorkflowError):
            workflow.adoption_plan(self.client, self.draft)
        self.manifest.write_text("<?php return [];\n")
        (self.client / "config" / workflow.REGISTRY_NAME).write_text(json.dumps(self.registry))
        with self.assertRaises(workflow.WorkflowError):
            workflow.adoption_plan(self.client, self.draft)

    def test_failed_php_validation_restores_hand_maintained_manifest(self):
        (self.root / ".env").write_text("APP_ENV=local\nCLIENT_KEY=fixture\n")
        plan = workflow.adoption_plan(self.client, self.draft)
        with self.argv("--apply", "--confirm-plan", plan["fingerprint"]), \
             contextlib.redirect_stdout(io.StringIO()), \
             mock.patch.object(workflow, "run", side_effect=workflow.WorkflowError("invalid PHP")):
            self.assertEqual(1, workflow.main())
        self.assertEqual(plan["original_manifest"], self.manifest.read_bytes())
        self.assertFalse((self.client / "config" / workflow.REGISTRY_NAME).exists())


if __name__ == "__main__":
    unittest.main()