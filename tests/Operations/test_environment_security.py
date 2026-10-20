"""Local, non-privileged regression checks for deployment secret-file writes."""

from __future__ import annotations

import importlib.util
import json
import os
from pathlib import Path
import shutil
import stat
import subprocess
import sys
import tempfile
import unittest
from unittest.mock import patch

ROOT = Path(__file__).resolve().parents[2]
HELPER = ROOT / 'scripts/operations/lib/launch_client_environment.py'
LAUNCHER = ROOT / 'scripts/operations/launch-client-environment.sh'
LOGGING = ROOT / 'scripts/operations/configure-client-logging.sh'

spec = importlib.util.spec_from_file_location('engage_launch_helper_under_test', HELPER)
assert spec is not None and spec.loader is not None
module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)


class SecureEnvironmentWriterTest(unittest.TestCase):
    def setUp(self) -> None:
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name)
        self.path = self.root / '.env'
        self.path.write_text('CLIENT_KEY=tenant\nEXISTING=secret\n')
        self.path.chmod(0o640)

    def test_atomic_write_preserves_existing_owner_group_and_mode(self) -> None:
        previous = self.path.stat()
        module.env_write(self.path, 'APP_ENV', 'staging')
        changed = self.path.stat()
        self.assertEqual((previous.st_uid, previous.st_gid), (changed.st_uid, changed.st_gid))
        self.assertEqual(stat.S_IMODE(changed.st_mode), 0o640)
        self.assertEqual(module.env_values(self.path)['EXISTING'], 'secret')
        self.assertEqual(module.env_values(self.path)['APP_ENV'], 'staging')
        self.assertFalse(any(p.name.startswith('.engage-env-') for p in self.root.iterdir()))

    def test_remove_is_atomic_and_preserves_permissions(self) -> None:
        previous = self.path.stat()
        module.env_remove(self.path, 'EXISTING')
        changed = self.path.stat()
        self.assertEqual((previous.st_uid, previous.st_gid), (changed.st_uid, changed.st_gid))
        self.assertEqual(stat.S_IMODE(changed.st_mode), 0o640)
        self.assertNotIn('EXISTING=', self.path.read_text())

    def test_world_access_and_group_write_fail_closed_without_modification(self) -> None:
        for mode in (0o644, 0o664, 0o660, 0o666):
            with self.subTest(mode=oct(mode)):
                self.path.chmod(mode)
                before = self.path.read_bytes()
                with self.assertRaises(SystemExit):
                    module.env_write(self.path, 'APP_ENV', 'production')
                self.assertEqual(self.path.read_bytes(), before)
        self.path.chmod(0o640)

    def test_symlink_refuses_secret_write_and_remove(self) -> None:
        link = self.root / 'client-env'
        link.symlink_to(self.path)
        for action in (lambda: module.env_write(link, 'APP_ENV', 'staging'),
                       lambda: module.env_remove(link, 'EXISTING')):
            with self.assertRaises(SystemExit):
                action()
        self.assertIn('EXISTING=secret', self.path.read_text())

    def test_metadata_restore_failure_does_not_replace_original(self) -> None:
        before = self.path.read_bytes()
        with patch.object(module.os, 'chown', side_effect=PermissionError('denied')):
            with patch.object(module.subprocess, 'run', side_effect=subprocess.CalledProcessError(1, 'sudo')):
                with self.assertRaises(SystemExit):
                    module.env_write(self.path, 'APP_ENV', 'production')
        self.assertEqual(self.path.read_bytes(), before)
        self.assertFalse(any(p.name.startswith('.engage-env-') for p in self.root.iterdir()))

    def test_effective_posix_acl_is_retained_when_supported(self) -> None:
        if not shutil.which('setfacl') or not shutil.which('getfacl'):
            self.skipTest('POSIX ACL utilities not installed')
        # A write-capable named ACL raises the effective mask to rw and makes
        # st_mode report group-write (0660), which the writer must reject.
        # This fixture exercises *permitted* read-only ACL preservation.
        command = subprocess.run(['setfacl', '-m', f'u:{os.getuid()}:r', str(self.path)],
                                 capture_output=True, text=True)
        if command.returncode:
            self.skipTest('filesystem does not permit POSIX ACLs')
        self.assertEqual(stat.S_IMODE(self.path.stat().st_mode), 0o640)
        before = subprocess.check_output(['getfacl', '-cp', str(self.path)], text=True)
        module.env_write(self.path, 'APP_ENV', 'staging')
        after = subprocess.check_output(['getfacl', '-cp', str(self.path)], text=True)
        self.assertEqual(before, after)
        self.assertEqual(stat.S_IMODE(self.path.stat().st_mode), 0o640)

    def test_write_capable_posix_acl_is_rejected_when_supported(self) -> None:
        if not shutil.which('setfacl') or not shutil.which('getfacl'):
            self.skipTest('POSIX ACL utilities not installed')
        command = subprocess.run(['setfacl', '-m', f'u:{os.getuid()}:rw', str(self.path)],
                                 capture_output=True, text=True)
        if command.returncode:
            self.skipTest('filesystem does not permit POSIX ACLs')
        # The Linux ACL mask is reflected in the group mode bits.
        self.assertTrue(stat.S_IMODE(self.path.stat().st_mode) & 0o020)
        previous_content = self.path.read_bytes()
        previous_acl = subprocess.check_output(['getfacl', '-cp', str(self.path)], text=True)
        with self.assertRaises(SystemExit):
            module.env_write(self.path, 'APP_ENV', 'production')
        self.assertEqual(self.path.read_bytes(), previous_content)
        self.assertEqual(subprocess.check_output(['getfacl', '-cp', str(self.path)], text=True), previous_acl)

    def test_env_cli_accepts_secret_over_stdin_without_argv_secret(self) -> None:
        secret = 'test-sensitive-value with spaces and quotes "'
        result = subprocess.run([sys.executable, str(HELPER), 'env-set', '--file',
                                 str(self.path), '--key', 'TEST_SECRET', '--stdin-value'],
                                input=secret, text=True, capture_output=True, check=True)
        self.assertEqual(result.stdout, '')
        self.assertEqual(module.env_values(self.path)['TEST_SECRET'], secret)

    def test_plan_reader_supports_stdin_without_creating_file(self) -> None:
        plan = {'environment_requirements': [
            {'status': 'mismatch', 'requirement': 'defaulted'},
            {'status': 'unresolved', 'requirement': 'required'},
            {'status': 'present', 'requirement': 'required'},
        ]}
        result = subprocess.run([sys.executable, str(HELPER), 'plan-blocking-count', '--plan', '-'],
                                input=json.dumps(plan), text=True, capture_output=True, check=True)
        self.assertEqual(result.stdout.strip(), '2')

    def test_launcher_and_logging_scripts_parse(self) -> None:
        for script in (LAUNCHER, LOGGING):
            subprocess.run(['bash', '-n', str(script)], check=True)

    def test_logging_dry_run_never_echoes_secrets_or_creates_backup(self) -> None:
        (self.root / 'artisan').touch()
        result = subprocess.run(['bash', str(LOGGING), '--env-file', str(self.path),
                                 '--app-path', str(self.root)],
                                capture_output=True, text=True, check=True)
        self.assertNotIn('secret', result.stdout + result.stderr)
        self.assertEqual(self.path.read_text(), 'CLIENT_KEY=tenant\nEXISTING=secret\n')
        self.assertEqual([p for p in self.root.iterdir() if p.suffix == '.bak'], [])


if __name__ == '__main__':
    unittest.main()