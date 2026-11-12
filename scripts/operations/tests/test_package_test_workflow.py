"""Local tooling tests; run with Python unittest, no Laravel database needed."""

import importlib.util
import json
from pathlib import Path
import tempfile
import unittest
import subprocess
from unittest import mock
from argparse import Namespace

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

    def test_bootstrap_loads_package_dependencies_without_selected_client(self):
        composer = runner.json_object(self.package / "composer.json")
        package_vendor = self.package / "vendor"
        package_vendor.mkdir()
        (package_vendor / "autoload.php").write_text("<?php\n")
        bootstrap = runner.build_bootstrap(self.host, self.package, composer)
        self.assertIn(str(package_vendor / "autoload.php"), bootstrap)
        self.assertLess(bootstrap.index(str(self.host / "vendor" / "autoload.php")),
                        bootstrap.index(str(package_vendor / "autoload.php")))
        self.assertLess(bootstrap.index(str(package_vendor / "autoload.php")),
                        bootstrap.index("$engageHostLoader->register(true);"))
        self.assertLess(bootstrap.index("$engageHostLoader->register(true);"),
                        bootstrap.index("$engageHostLoader->addPsr4"))
        self.assertNotIn("client/", bootstrap)

    def test_bootstrap_runs_with_package_dependencies_and_prefers_checkout(self):
        # A tiny Composer loader substitute tests the generated PHP bootstrapping
        # without executing Composer, Laravel, or any selected-client configuration.
        composer = runner.json_object(self.package / "composer.json")
        (self.package / "src" / "Thing.php").write_text(
            "<?php namespace ImagineSocial\\EngageSeoNonprofit; class Thing { const SOURCE = 'checkout'; }\n")
        package_vendor = self.package / "vendor"
        package_vendor.mkdir()
        (package_vendor / "Dependency.php").write_text(
            "<?php namespace ThirdParty; class Dependency {}\n")
        stale = package_vendor / "stale"
        stale.mkdir()
        (stale / "Thing.php").write_text(
            "<?php namespace ImagineSocial\\EngageSeoNonprofit; class Thing { const SOURCE = 'vendor'; }\n")
        host_loader = self.host / "vendor" / "autoload.php"
        host_loader.write_text(r"""<?php
namespace Composer\Autoload;
class ClassLoader {
    private array $prefixes = [];
    private $callback = null;
    public function addPsr4(string $prefix, string $path, bool $prepend = false): void {
        if ($prepend) { array_unshift($this->prefixes, [$prefix, $path]); }
        else { $this->prefixes[] = [$prefix, $path]; }
    }
    public function register(bool $prepend = false): void {
        if ($this->callback !== null) { spl_autoload_unregister($this->callback); }
        $this->callback = function(string $class): void {
            foreach ($this->prefixes as [$prefix, $root]) {
                if (str_starts_with($class, $prefix)) {
                    $file = $root . '/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
                    if (is_file($file)) { require $file; return; }
                }
            }
        };
        spl_autoload_register($this->callback, true, $prepend);
    }
}
return (function() {
    $loader = new ClassLoader();
    $loader->register();
    return $loader;
})();
""")
        (package_vendor / "autoload.php").write_text(r"""<?php
$loader = new \Composer\Autoload\ClassLoader();
$loader->addPsr4('ThirdParty\\', __DIR__);
$loader->addPsr4('ImagineSocial\\EngageSeoNonprofit\\', __DIR__.'/stale');
$loader->register(true);
return $loader;
""")
        bootstrap = self.workspace / "bootstrap.php"
        bootstrap.write_text(runner.build_bootstrap(self.host, self.package, composer))
        result = subprocess.run([
            "php", "-r", "require "+runner.php_string(str(bootstrap))+"; "
            "exit(class_exists('ThirdParty\\Dependency') && "
            "class_exists('ImagineSocial\\EngageSeoNonprofit\\Thing') && "
            "\\ImagineSocial\\EngageSeoNonprofit\\Thing::SOURCE === 'checkout' ? 0 : 1);",
        ], capture_output=True, text=True)
        self.assertEqual(0, result.returncode, result.stderr)

    def test_no_package_vendor_retains_existing_host_only_bootstrap(self):
        composer = runner.json_object(self.package / "composer.json")
        bootstrap = runner.build_bootstrap(self.host, self.package, composer)
        self.assertNotIn("$engageDependencyLoader", bootstrap)
        self.assertIn("$engageHostLoader->addPsr4", bootstrap)

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


    def make_core_vertical_package(self):
        package = self.workspace / "engage-fixture-vertical"
        (package / "src").mkdir(parents=True)
        (package / "tests" / "Feature").mkdir(parents=True)
        (package / "tests" / "Feature" / "FixtureTest.php").write_text("<?php\n")
        (package / "database" / "migrations").mkdir(parents=True)
        (package / "database" / "migrations" / "2099_01_01_000000_create_fixture_records.php").write_text("<?php\n")
        composer = {
            "name": "imagine-social/engage-fixture-vertical",
            "autoload": {"psr-4": {"ImagineSocial\\FixtureVertical\\": "src/"}},
        }
        (package / "composer.json").write_text(json.dumps(composer))
        (package / "engage-package.json").write_text(json.dumps({
            "schema": 1,
            "name": composer["name"],
            "kind": "vertical",
            "platform": "core",
            "namespace": "ImagineSocial\\FixtureVertical",
            "module_key": "fixture_vertical",
            "module_class": "ImagineSocial\\FixtureVertical\\FixtureVerticalPackage",
            "migration_scope": True,
        }))
        return package, composer

    def test_core_package_bootstrap_registers_source_module_and_migrations_without_client(self):
        package, composer = self.make_core_vertical_package()
        statements = runner.core_package_registration(package, composer)
        bootstrap = runner.build_bootstrap(self.host, package, composer, platform="core")
        self.assertIn("ClientPackageRuntime::replace", bootstrap)
        self.assertIn("FixtureVerticalPackage", bootstrap)
        self.assertIn("fixture_vertical", bootstrap)
        self.assertIn(str(package / "database" / "migrations"), bootstrap)
        self.assertNotIn("client/", bootstrap)
        self.assertNotIn("holly-hatcher", bootstrap)
        self.assertTrue(statements)
        generated = self.workspace / "core-bootstrap.php"
        generated.write_text(bootstrap)
        lint = subprocess.run(["php", "-l", str(generated)], capture_output=True, text=True)
        self.assertEqual(0, lint.returncode, lint.stderr)

    def test_vertical_test_registration_rejects_missing_migrations_and_descriptor_drift(self):
        package, composer = self.make_core_vertical_package()
        migrations = package / "database" / "migrations"
        next(migrations.glob("*.php")).unlink()
        with self.assertRaises(runner.PackageTestError):
            runner.core_package_registration(package, composer)
        (migrations / "2099_01_01_000000_create_fixture_records.php").write_text("<?php\n")
        descriptor = json.loads((package / "engage-package.json").read_text())
        descriptor["module_class"] = "Foreign\\Module"
        (package / "engage-package.json").write_text(json.dumps(descriptor))
        with self.assertRaises(runner.PackageTestError):
            runner.core_package_registration(package, composer)
        descriptor["module_class"] = "ImagineSocial\\FixtureVertical\\FixtureVerticalPackage"
        descriptor["name"] = "imagine-social/another-package"
        (package / "engage-package.json").write_text(json.dumps(descriptor))
        with self.assertRaises(runner.PackageTestError):
            runner.core_package_registration(package, composer)

    def test_package_test_run_activates_only_package_owned_modules_and_clears_client(self):
        package, _ = self.make_core_vertical_package()
        core = self.workspace / "engage-core"
        (core / "vendor" / "bin").mkdir(parents=True)
        (core / "vendor" / "autoload.php").write_text("<?php\n")
        (core / "vendor" / "bin" / "phpunit").write_text("placeholder")
        (core / "phpunit.xml").write_text("<phpunit/>")
        (core / "artisan").write_text("placeholder")
        args = Namespace(package=package.name, platform="core", workspace=str(self.workspace),
                         configuration=None, test=[], dry_run=False, phpunit_args=[])
        observed = {}
        def fake_phpunit_run(command, cwd, env, check):
            observed["env"] = env.copy()
            observed["code"] = Path(command[command.index("--bootstrap") + 1]).read_text()
            return type("Result", (), {"returncode": 0})()
        with mock.patch.object(runner.subprocess, "run", side_effect=fake_phpunit_run):
            self.assertEqual(0, runner.run_tests(args))
        self.assertEqual("testing", observed["env"]["APP_ENV"])
        self.assertEqual("", observed["env"]["CLIENT_KEY"])
        self.assertEqual("fixture_vertical", observed["env"]["ENGAGE_PACKAGE_TEST_MODULES"])
        self.assertIn("ClientPackageRuntime::replace", observed["code"])
        self.assertNotIn("client/holly-hatcher", observed["code"])

    def test_cached_host_configuration_blocks_neutral_package_test_run(self):
        package, _ = self.make_core_vertical_package()
        core = self.workspace / "engage-core"
        (core / "vendor" / "bin").mkdir(parents=True)
        (core / "vendor" / "autoload.php").write_text("<?php\n")
        (core / "vendor" / "bin" / "phpunit").write_text("placeholder")
        (core / "phpunit.xml").write_text("<phpunit/>")
        (core / "artisan").write_text("placeholder")
        (core / "bootstrap" / "cache").mkdir(parents=True)
        (core / "bootstrap" / "cache" / "config.php").write_text("<?php\n")
        args = Namespace(package=package.name, platform="core", workspace=str(self.workspace),
                         configuration=None, test=[], dry_run=False, phpunit_args=[])
        with mock.patch.object(runner.subprocess, "run", side_effect=AssertionError("must not execute")):
            with self.assertRaises(runner.PackageTestError):
                runner.run_tests(args)

    def test_client_config_test_host_ignores_real_client_key(self):
        config = Path(__file__).resolve().parents[3] / "config" / "client.php"
        source = r"""function env($key, $default = null) { $value = getenv($key); return $value === false ? $default : $value; }
function base_path($path) { return '/neutral-host/'.$path; }
$result = require $argv[1];
if ($result['key'] !== 'default' || $result['config_path'] !== null || $result['views_path'] !== null || $result['env_path'] !== null) { exit(1); }
"""
        result = subprocess.run(["php", "-r", source, str(config)],
                                env={"APP_ENV": "testing", "CLIENT_KEY": "holly-hatcher-ef-core"},
                                capture_output=True, text=True)
        self.assertEqual(0, result.returncode, result.stderr)

if __name__ == "__main__":
    unittest.main()