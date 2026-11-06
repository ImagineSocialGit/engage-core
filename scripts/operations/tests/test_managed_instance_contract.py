"""Read-only Ansible allocation contract tests; no servers or databases."""

from __future__ import annotations

import importlib.util
import grp
import json
import os
from pathlib import Path
import pwd
import subprocess
import sys
import tempfile
import unittest
from unittest import mock
from types import SimpleNamespace

HELPER = Path(__file__).resolve().parents[1] / "lib/launch_managed_instance.py"
spec = importlib.util.spec_from_file_location("launch_managed_instance", HELPER)
managed = importlib.util.module_from_spec(spec)
spec.loader.exec_module(managed)


class ManagedInstanceContractTest(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.workspace = Path(self.temp.name)
        self.instances = self.workspace / "instances"
        self.pools = self.workspace / "pools"
        self.instances.mkdir()
        self.pools.mkdir()
        self.instance_id = "example-core"
        self.root = self.instances / self.instance_id
        self.root.mkdir(mode=0o750)
        for relative in ("releases", "shared", "shared/storage", "shared/logs"):
            directory = self.root / relative
            directory.mkdir(mode=0o750)
            directory.chmod(0o2770 if relative in ("shared/storage", "shared/logs") else 0o750)
        self.root.chmod(0o750)
        self.deploy = pwd.getpwuid(os.getuid())
        self.group = grp.getgrgid(os.getgid())
        self.pool_file = self.pools / f"engage-{self.instance_id}.conf"
        self.pool_file.write_text(f"""; Ansible-managed PHP-FPM pool
[{self.instance_id}]
user = isolatedruntime
group = {self.group.gr_name}
listen = /run/php/engage-{self.instance_id}.sock
listen.owner = isolatedruntime
listen.group = www-data
listen.mode = 0660
""")
        self.pool_file.chmod(0o644)

    def inspect(self):
        real_lookup = pwd.getpwnam

        def lookup(name):
            if name == "isolatedruntime":
                return SimpleNamespace(pw_name=name, pw_uid=os.getuid() + 1000,
                                       pw_gid=os.getgid())
            return real_lookup(name)

        with mock.patch.object(managed.pwd, "getpwnam", side_effect=lookup):
            return managed.inspect_instance(
                self.instance_id,
                instances_root=self.instances,
                pools_root=self.pools,
                trusted_pool_owner_uid=os.getuid(),
            )

    def test_correct_ansible_allocation_passes_without_writes(self):
        before = self.pool_file.read_bytes()
        result = self.inspect()
        self.assertTrue(result["ready"], result["issues"])
        self.assertEqual(self.deploy.pw_name, result["deploy_user"])
        self.assertEqual(self.group.gr_name, result["group"])
        self.assertEqual("isolatedruntime", result["runtime_user"])
        self.assertEqual(before, self.pool_file.read_bytes())

    def test_invalid_id_refuses_path_traversal(self):
        for value in ("../example-core", "Example-Core", "a/b", "", "-no"):
            with self.subTest(value=value):
                result = managed.inspect_instance(value, instances_root=self.instances,
                                                  pools_root=self.pools)
                self.assertFalse(result["ready"])
                self.assertIsNone(result["instance_root"])

    def test_missing_allocation_fails_closed(self):
        result = managed.inspect_instance("missing-core", instances_root=self.instances,
                                          pools_root=self.pools)
        self.assertFalse(result["ready"])
        self.assertTrue(result["issues"])

    def test_world_access_and_directory_symlinks_are_rejected(self):
        self.root.chmod(0o755)
        self.assertFalse(self.inspect()["ready"])
        self.root.chmod(0o750)
        (self.root / "releases").rmdir()
        (self.root / "releases").symlink_to(self.root / "shared", target_is_directory=True)
        self.assertFalse(self.inspect()["ready"])

    def test_shared_storage_needs_effective_group_write(self):
        storage = self.root / "shared/storage"
        storage.chmod(0o750)
        self.assertFalse(self.inspect()["ready"])

    def test_wrong_runtime_user_and_group_are_rejected(self):
        original = self.pool_file.read_text()
        self.pool_file.write_text(original.replace("user = isolatedruntime", f"user = {self.deploy.pw_name}").replace(
            "listen.owner = isolatedruntime", f"listen.owner = {self.deploy.pw_name}"))
        self.assertFalse(self.inspect()["ready"])
        self.pool_file.write_text(original.replace(f"group = {self.group.gr_name}", "group = randomgroup"))
        self.assertFalse(self.inspect()["ready"])

    def test_untrusted_fpm_file_and_socket_mismatch_are_rejected(self):
        self.pool_file.chmod(0o666)
        self.assertFalse(self.inspect()["ready"])
        self.pool_file.chmod(0o644)
        self.pool_file.write_text(self.pool_file.read_text().replace(
            "/run/php/engage-example-core.sock", "/run/php/www.sock"))
        self.assertFalse(self.inspect()["ready"])

    def test_cli_rejects_invalid_instance_and_prints_no_secrets(self):
        run = subprocess.run([sys.executable, str(HELPER), "--instance-id", "../bad"],
                             capture_output=True, text=True)
        self.assertNotEqual(0, run.returncode)
        data = json.loads(run.stdout)
        self.assertFalse(data["ready"])
        self.assertNotIn("password", run.stdout.lower())
        self.assertEqual("", run.stderr)


if __name__ == "__main__":
    unittest.main()