"""Scaffolder regression checks without network, GitHub, or database calls."""

import importlib.util
import json
from pathlib import Path
import subprocess
import tempfile
import unittest

MODULE_PATH = Path(__file__).resolve().parents[1] / "lib" / "package_workflow.py"
SPEC = importlib.util.spec_from_file_location("package_workflow", MODULE_PATH)
workflow = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(workflow)


class PackageScaffolderContractsTest(unittest.TestCase):
    def scaffold(self, platform: str, kind: str, slug: str):
        descriptor = workflow.make_descriptor(slug, kind, "ImagineSocialGit", platform)
        files = workflow.package_contents(descriptor, "Isolated sample package")
        return descriptor, files

    def test_core_vertical_keeps_module_contract_and_adds_generic_tests(self):
        descriptor, files = self.scaffold("core", "vertical", "engage-sample")
        self.assertEqual("sample", descriptor["module_key"])
        self.assertFalse(descriptor["migration_scope"])
        self.assertIn("src/SamplePackage.php", files)
        self.assertIn("tests/Unit/PackageScaffoldTest.php", files)
        composer = json.loads(files["composer.json"])
        self.assertEqual("tests/", composer["autoload-dev"]["psr-4"]["ImagineSocial\\EngageSample\\Tests\\"])

    def test_seo_vertical_uses_client_registered_provider_not_a_core_module(self):
        descriptor, files = self.scaffold("seo", "vertical", "engage-seo-nonprofit")
        self.assertEqual("nonprofit", descriptor["vertical_key"])
        self.assertEqual("ImagineSocial\\EngageSeoNonprofit\\SeoNonprofitServiceProvider", descriptor["service_provider"])
        self.assertNotIn("module_class", descriptor)
        self.assertNotIn("migration_scope", descriptor)
        self.assertIn("src/SeoNonprofitServiceProvider.php", files)
        self.assertIn("verticals.available.nonprofit", files["src/SeoNonprofitServiceProvider.php"])
        self.assertNotIn("client/holly-hatcher", "".join(files.values()).lower())

    def test_artist_vertical_has_provider_without_core_module_contract(self):
        descriptor, files = self.scaffold("artist", "vertical", "engage-artist-events")
        self.assertEqual("events", descriptor["vertical_key"])
        self.assertIn("src/ArtistEventsServiceProvider.php", files)
        self.assertNotIn("module_class", descriptor)
        self.assertNotIn("migration_scope", descriptor)

    def test_library_and_integration_shapes_include_package_tests(self):
        for platform, kind, slug in (
            ("core", "library", "engage-qr-rendering"),
            ("core", "integration", "engage-integration-sample"),
            ("seo", "library", "engage-seo-common"),
            ("seo", "integration", "engage-seo-integration-payments"),
            ("artist", "integration", "engage-artist-integration-messaging"),
        ):
            with self.subTest(platform=platform, kind=kind):
                _, files = self.scaffold(platform, kind, slug)
                self.assertIn("tests/Unit/PackageScaffoldTest.php", files)

    def test_invalid_platform_name_combinations_fail_before_remote_actions(self):
        with self.assertRaises(workflow.WorkflowError):
            workflow.make_descriptor("engage-donations", "vertical", "ImagineSocialGit", "seo")
        with self.assertRaises(workflow.WorkflowError):
            workflow.make_descriptor("engage-seo-donations", "integration", "ImagineSocialGit", "seo")

    def test_every_php_scaffold_has_valid_syntax(self):
        for platform, kind, slug in (
            ("core", "vertical", "engage-sample"),
            ("core", "integration", "engage-integration-sample"),
            ("seo", "vertical", "engage-seo-sample"),
            ("artist", "vertical", "engage-artist-sample"),
            ("seo", "library", "engage-seo-library"),
            ("seo", "integration", "engage-seo-integration-sample"),
            ("artist", "integration", "engage-artist-integration-sample"),
        ):
            _, files = self.scaffold(platform, kind, slug)
            with tempfile.TemporaryDirectory() as temp:
                for filename, data in files.items():
                    target = Path(temp) / filename
                    target.parent.mkdir(parents=True, exist_ok=True)
                    target.write_text(data)
                    if target.suffix == ".php":
                        result = subprocess.run(["php", "-l", str(target)], text=True, capture_output=True)
                        self.assertEqual(0, result.returncode, f"{filename}: {result.stderr}")


if __name__ == "__main__":
    unittest.main()