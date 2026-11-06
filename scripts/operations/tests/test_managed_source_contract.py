"""Read-only, locally fixture-backed approved-release checks (no network/hosts)."""

from __future__ import annotations

import grp
import json
import os
from pathlib import Path
import pwd
import subprocess
import sys
import tempfile
import unittest
from types import SimpleNamespace
from unittest import mock

LIB = Path(__file__).resolve().parents[1] / "lib"
sys.path.insert(0, str(LIB))
import launch_managed_source as source  # noqa: E402


class ManagedSourceContractTest(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.workspace = Path(self.temp.name)
        self.instances = self.workspace / "instances"
        self.pools = self.workspace / "pools"
        self.instances.mkdir()
        self.pools.mkdir()
        self.instance_id = "example-core"
        self.release_id = "release-20261106-a"
        self.client_key = "example_core"
        self.deploy = pwd.getpwuid(os.getuid())
        self.group = grp.getgrgid(os.getgid())
        self.root = self.instances / self.instance_id
        self.root.mkdir(mode=0o750)
        for name in ("releases", "shared", "shared/storage", "shared/logs"):
            path = self.root / name
            path.mkdir(mode=0o750)
            path.chmod(0o2770 if name in ("shared/storage", "shared/logs") else 0o750)
        self.root.chmod(0o750)
        self.pool = self.pools / f"engage-{self.instance_id}.conf"
        self.pool.write_text(f"""[{self.instance_id}]
user = isolatedruntime
group = {self.group.gr_name}
listen = /run/php/engage-{self.instance_id}.sock
listen.owner = isolatedruntime
listen.group = www-data
listen.mode = 0660
""")
        self.pool.chmod(0o644)
        self.release = self.root / "releases" / self.release_id
        self.release.mkdir(mode=0o750)
        self.release.chmod(0o750)
        self.client_parent = self.release / "client"
        self.client_parent.mkdir(mode=0o750)
        self.client_parent.chmod(0o750)
        self.client = self.client_parent / self.client_key
        self.client.mkdir(mode=0o750)
        self.client.chmod(0o750)
        self.core_repo = "ImagineSocialGit/engage-core"
        self.client_repo = "ImagineSocialGit/example-core"
        self.core_sha = self.make_repo(
            self.release, self.core_repo,
            {"artisan": "<?php\n", "bootstrap/app.php": "<?php\n"},
        )
        self.client_sha = self.make_repo(
            self.client, self.client_repo,
            {"config/client.php": "<?php return [];\n"},
        )

    def run_git(self, directory, *args):
        return subprocess.run(
            ["git", "-C", str(directory), *args], capture_output=True,
            text=True, check=True,
            env={**os.environ, "GIT_CONFIG_NOSYSTEM": "1", "GIT_CONFIG_GLOBAL": os.devnull},
        ).stdout.strip()

    def make_repo(self, path, repo, files):
        self.run_git(path, "init", "-q")
        self.run_git(path, "config", "user.email", "test@example.invalid")
        self.run_git(path, "config", "user.name", "Fixture")
        self.run_git(path, "remote", "add", "origin", f"git@github.com:{repo}.git")
        for name, contents in files.items():
            target = path / name
            target.parent.mkdir(mode=0o750, parents=True, exist_ok=True)
            target.write_text(contents)
            target.chmod(0o640)
        self.run_git(path, "add", ".")
        self.run_git(path, "commit", "-q", "-m", "fixture")
        # A release is private to its allocated application group. Git creates
        # .git at 0755 by default, so adjust the fixture to the source contract.
        for folder in path.rglob("*"):
            if folder.is_dir() and not folder.is_symlink():
                folder.chmod(0o750)
        (path / ".git").chmod(0o750)
        return self.run_git(path, "rev-parse", "HEAD")

    def inspect(self, **override):
        values = {
            "instance_id": self.instance_id,
            "release_id": self.release_id,
            "client_key": self.client_key,
            "core_repository": self.core_repo,
            "core_commit": self.core_sha,
            "client_repository": self.client_repo,
            "client_commit": self.client_sha,
            "instances_root": self.instances,
            "pools_root": self.pools,
            "trusted_pool_owner_uid": os.getuid(),
        }
        values.update(override)
        real_lookup = pwd.getpwnam

        def lookup(user):
            if user == "isolatedruntime":
                return SimpleNamespace(pw_name=user, pw_uid=os.getuid() + 1000,
                                       pw_gid=os.getgid())
            return real_lookup(user)

        with mock.patch.object(source.pwd, "getpwnam", side_effect=lookup):
            return source.inspect_release(**values)

    def test_approved_commits_and_remotes_pass_without_writing_git_metadata(self):
        before = {path: path.read_bytes() for path in (
            self.release / ".git/index", self.client / ".git/index",
            self.release / ".git/config", self.client / ".git/config",
        )}
        actual = self.inspect()
        self.assertTrue(actual["ready"], actual["issues"])
        self.assertEqual(self.core_sha, actual["core"]["commit"])
        self.assertEqual(self.client_sha, actual["client"]["commit"])
        for path, content in before.items():
            self.assertEqual(content, path.read_bytes())

    def test_wrong_approved_sha_fails_without_fetching_or_pulling(self):
        actual = self.inspect(client_commit="f" * 40)
        self.assertFalse(actual["ready"])
        self.assertTrue(any("approved commit" in x for x in actual["issues"]))

    def test_repository_origin_mismatch_fails_even_when_commit_matches(self):
        self.run_git(self.client, "remote", "set-url", "origin", "git@github.com:Other/other.git")
        result = self.inspect()
        self.assertFalse(result["ready"])
        self.assertTrue(any("Git origin" in x for x in result["issues"]))

    def test_dirty_tracked_core_or_client_fails(self):
        (self.release / "artisan").write_text("<?php // edited\n")
        self.assertFalse(self.inspect()["ready"])
        self.run_git(self.release, "checkout", "--", "artisan")
        (self.client / "config/client.php").write_text("<?php // edited\n")
        self.assertFalse(self.inspect()["ready"])

    def test_unallocated_and_missing_release_fail_closed(self):
        self.assertFalse(self.inspect(release_id="not-created")["ready"])
        self.assertFalse(self.inspect(instance_id="unallocated")["ready"])

    def test_untrusted_symlink_and_permissions_fail_closed(self):
        self.client.rename(self.client_parent / "actual-client")
        self.client.symlink_to(self.client_parent / "actual-client", target_is_directory=True)
        self.assertFalse(self.inspect()["ready"])
        self.client.unlink()
        self.client = self.client_parent / "actual-client"
        self.release.chmod(0o755)
        self.assertFalse(self.inspect()["ready"])

    def test_invalid_ids_and_repository_shapes_never_launch_git(self):
        with mock.patch.object(source.subprocess, "run", side_effect=AssertionError("ran git")):
            for changes in (
                {"release_id": "../escape"},
                {"client_key": "../../other"},
                {"core_repository": "https://evil.invalid/repository"},
                {"client_commit": "bad-commit"},
            ):
                self.assertFalse(self.inspect(**changes)["ready"])

    def test_unapproved_https_origin_with_embedded_credentials_is_rejected(self):
        self.run_git(self.client, "remote", "set-url", "origin", "https://user:secret@github.com/ImagineSocialGit/example-core.git")
        result = self.inspect()
        self.assertFalse(result["ready"])
        self.assertNotIn("secret", json.dumps(result))

    def test_git_environment_overrides_cannot_fake_a_repository(self):
        with mock.patch.dict(os.environ, {"GIT_DIR": str(self.release / ".git"),
                                             "GIT_WORK_TREE": str(self.release)}):
            self.assertTrue(self.inspect()["ready"])

    def test_source_check_never_implicitly_accepts_different_client(self):
        result = self.inspect(client_key="other_client")
        self.assertFalse(result["ready"])
        self.assertIsNone(result["client"]["commit"])


if __name__ == "__main__":
    unittest.main()