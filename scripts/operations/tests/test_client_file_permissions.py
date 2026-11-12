"""Selected-root inheritance is part of creation/bootstrap, without .git rewrites."""

import importlib.util
import os
from pathlib import Path
import stat
import subprocess
import tempfile
import unittest
from unittest.mock import patch

SPEC = importlib.util.spec_from_file_location('client_permissions_fixture', Path(__file__).resolve().parents[1] / 'lib/client_file_permissions.py')
permissions = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(permissions)


class ClientFilePermissionsTest(unittest.TestCase):
    def setUp(self):
        work = tempfile.TemporaryDirectory()
        self.addCleanup(work.cleanup)
        self.root = Path(work.name) / 'platform'
        self.client = self.root / 'client/fixture-core'
        (self.client / 'config').mkdir(parents=True)
        (self.client / '.git').mkdir()
        self.private_git = self.client / '.git/private-index'
        self.private_git.write_text('unchanged')
        self.private_git.chmod(0o600)
        self.manifest = self.client / 'composer.json'
        self.manifest.write_text('{}\n')
        self.manifest.chmod(0o600)
        self.env = self.client / '.env'
        self.env.write_text('APP_URL=http://fixture.test\n')
        self.env.chmod(0o640)
        self.client.chmod(0o750)
        for name in ('client.php', 'client_packages.php'):
            (self.client / 'config' / name).write_text('<?php return [];\n')
        patcher = patch.object(permissions, 'policy', return_value=('fixture-runtime', os.getegid(), 'fixture-web'))
        patcher.start()
        self.addCleanup(patcher.stop)
        patcher = patch.object(permissions, 'check_web_access')
        self.web_access = patcher.start()
        self.addCleanup(patcher.stop)

    def test_creation_and_managed_package_preparation_set_root_inheritance(self):
        for action in ('prepare-config', 'prepare-managed'):
            with self.subTest(action=action):
                self.client.chmod(0o750)
                self.assertEqual(0, permissions.main(['permissions', action, str(self.client)]))
                self.assertEqual(0o2750, stat.S_IMODE(self.client.stat().st_mode))
                self.assertEqual(os.getegid(), self.client.stat().st_gid)
                self.assertEqual(0o600, stat.S_IMODE(self.private_git.stat().st_mode))
                self.assertEqual('unchanged', self.private_git.read_text())
                self.assertEqual(0o600, stat.S_IMODE(self.manifest.stat().st_mode))
                self.assertEqual('{}\n', self.manifest.read_text())

    def test_verify_requires_inheritance_and_does_not_repair_it(self):
        for action in ('verify-config', 'verify-managed'):
            with self.subTest(action=action), patch.object(permissions.subprocess, 'run') as run:
                with self.assertRaises(permissions.PermissionsError):
                    permissions.main(['permissions', action, str(self.client)])
                run.assert_not_called()
                self.assertEqual(0o750, stat.S_IMODE(self.client.stat().st_mode))

    def test_prepare_env_repairs_only_the_selected_client_parent(self):
        root_env = self.root / '.env'
        root_env.write_text('APP_ENV=local\n')
        root_env.chmod(0o640)
        mode = stat.S_IMODE(self.root.stat().st_mode)
        self.assertEqual(0, permissions.main(['permissions', 'prepare-env', str(root_env), str(self.env)]))
        self.assertEqual(mode, stat.S_IMODE(self.root.stat().st_mode))
        self.assertEqual(0o2750, stat.S_IMODE(self.client.stat().st_mode))
        self.assertEqual(0o640, stat.S_IMODE(self.env.stat().st_mode))
        self.assertEqual('APP_URL=http://fixture.test\n', self.env.read_text())
        self.assertEqual(0o600, stat.S_IMODE(self.private_git.stat().st_mode))

    def test_nonmember_setgid_retry_targets_the_exact_selected_directory(self):
        original = Path.chmod
        dropped = []
        def chmod(path, mode, *args, **kwargs):
            if path == self.client and not dropped:
                dropped.append(path)
                return original(path, mode & ~stat.S_ISGID, *args, **kwargs)
            return original(path, mode, *args, **kwargs)
        def sudo(args, **kwargs):
            self.assertEqual(['sudo', 'chmod', '--', '2750', str(self.client)], args)
            original(self.client, 0o2750)
            return subprocess.CompletedProcess(args, 0)
        with patch.object(Path, 'chmod', new=chmod), patch.object(permissions.subprocess, 'run', side_effect=sudo) as run:
            permissions.main(['permissions', 'prepare-managed', str(self.client)])
        self.assertEqual([self.client], dropped)
        self.assertEqual(1, run.call_count)
        self.assertEqual(0o2750, stat.S_IMODE(self.client.stat().st_mode))

    def test_selected_root_symlink_is_rejected(self):
        alias = self.client.parent / 'alias-core'
        alias.symlink_to(self.client, target_is_directory=True)
        with patch.object(permissions.subprocess, 'run') as run:
            with self.assertRaises(permissions.PermissionsError):
                permissions.main(['permissions', 'prepare-managed', str(alias)])
            run.assert_not_called()


if __name__ == '__main__':
    unittest.main()