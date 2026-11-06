"""Local tooling tests; run with Python unittest, no Laravel database needed."""

import importlib.util
import json
from pathlib import Path
import tempfile
import unittest

MODULE_PATH = Path(__file__).resolve().parents[1] / "lib" / "package_test_workflow.py"
SPEC = importlib.util.spec_from_file_location("package_test_workflow", MODULE_PATH)
runner = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(runner)


class PackageTestWorkflowTest(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.addCleanup(self.tmp.cleanup)
        self.workspace = Path(self.tmp.name)
        self.package = self.workspace / "engage-seo-nonprofit"
        (self.package / "src").mkdir(parents=True)
        (self.package / "tests" / "Feature").mkdir(parents=True)
        (self.package / "tests" / "Feature" / "ExampleTest.php").write_text("<?php\n")
        (self.package / "composer.json").write_text(json.dumps({
            "name": "imagine-social/engage-seo-nonprofit",
            "autoload": {"psr-4": {"ImagineSocial\\EngageSeoNonprofit\\": "src/"}},
            "autoload-dev": {"psr-4": {"ImagineSocial\\EngageSeoNonprofit\\Tests\\": "tests/"}},
        }))
        self.host = self.workspace / "engage-seo"
        (self.host / "vendor" / "bin").mkdir(parents=True)
        (self.host / "vendor" / "autoload.php").write_text("<?php\n")
        (self.host / "vendor" / "bin" / "phpunit").write_text("placeholder")
        (self.host / "tests").mkdir()
        (self.host / "tests" / "bootstrap.php").write_text("<?php\n")
        (self.host / "artisan").write_text("placeholder")
        (self.host / "phpunit.xml").write_text('<phpunit/>')

    def test_legacy_seo_package_resolves_correct_host_without_client(self):
        composer = runner.json_object(self.package / "composer.json")
        self.assertEqual("seo", runner.resolve_platform(self.package, composer, "auto"))
        bootstrap = runner.build_bootstrap(self.host, self.package, composer)
        self.assertIn("ImagineSocial\\\\EngageSeoNonprofit\\\\", bootstrap)
        self.assertIn(str(self.package / "src"), bootstrap)
        self.assertIn(str(self.host / "tests" / "bootstrap.php"), bootstrap)
        self.assertNotIn("holly-hatcher", bootstrap)
        self.assertEqual([self.package / "tests"], runner.test_targets(self.package, []))

    def test_descriptor_platform_wins_and_explicit_override_is_supported(self):
        composer = runner.json_object(self.package / "composer.json")
        (self.package / "engage-package.json").write_text(json.dumps({"platform": "artist"}))
        self.assertEqual("artist", runner.resolve_platform(self.package, composer, "auto"))
        self.assertEqual("core", runner.resolve_platform(self.package, composer, "core"))

    def test_reject_package_psr4_escape_from_package_checkout(self):
        composer = runner.json_object(self.package / "composer.json")
        composer["autoload"]["psr-4"]["ImagineSocial\\EngageSeoNonprofit\\"] = "../../secrets"
        with self.assertRaises(runner.PackageTestError):
            runner.mappings(composer, self.package)

    def test_test_paths_cannot_escape_tests_folder(self):
        with self.assertRaises(runner.PackageTestError):
            runner.test_targets(self.package, ["../composer.json"])

    def test_dry_run_requires_existing_host_and_package_tests(self):
        from argparse import Namespace
        args = Namespace(package="engage-seo-nonprofit", platform="auto",
                         workspace=str(self.workspace), configuration=None,
                         test=[], dry_run=True, phpunit_args=[])
        self.assertEqual(0, runner.run_tests(args))


if __name__ == "__main__":
    unittest.main()