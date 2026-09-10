#!/usr/bin/env python3
from __future__ import annotations

import argparse
import getpass
import hashlib
import json
import os
import re
import stat
import sys
import tempfile
from pathlib import Path
from typing import Any
from urllib.parse import urlparse


def fail(message: str) -> None:
    raise SystemExit(message)


def normalize_domain(value: str) -> str:
    raw = value.strip().lower()
    if not raw:
        fail('Root domain cannot be blank.')

    if '://' in raw:
        parsed = urlparse(raw)
        if parsed.scheme not in {'http', 'https'} or not parsed.hostname:
            fail(f'Invalid root domain/origin: {value}')
        if parsed.path not in {'', '/'} or parsed.query or parsed.fragment or parsed.username or parsed.password:
            fail(f'Root domain must not contain credentials, path, query, or fragment: {value}')
        raw = parsed.hostname

    raw = raw.rstrip('.')
    if len(raw) > 253 or not re.fullmatch(r'[a-z0-9](?:[a-z0-9.-]*[a-z0-9])?', raw):
        fail(f'Invalid root domain: {value}')

    labels = raw.split('.')
    if len(labels) < 2 or any(len(label) > 63 or label.startswith('-') or label.endswith('-') for label in labels):
        fail(f'Invalid root domain: {value}')

    return raw


def repo_slug(value: str) -> str:
    raw = value.strip().rstrip('/')
    if not raw:
        fail('Client repository cannot be blank.')

    tail = raw.rsplit('/', 1)[-1]
    if '/' not in raw and ':' in raw:
        tail = raw.rsplit(':', 1)[-1]
    elif ':' in tail and raw.startswith('git@'):
        tail = tail.rsplit(':', 1)[-1]

    if tail.endswith('.git'):
        tail = tail[:-4]

    slug = re.sub(r'[^a-z0-9_-]+', '-', tail.lower()).strip('-_')
    slug = re.sub(r'-{2,}', '-', slug)
    slug = re.sub(r'_{2,}', '_', slug)

    if not slug:
        fail(f'Unable to derive a client key from repository [{value}].')

    return slug


def runtime_stem(client_key: str) -> str:
    stem = re.sub(r'[^a-z0-9]+', '_', client_key.lower()).strip('_')
    stem = re.sub(r'_{2,}', '_', stem)
    return stem or 'client'


def bounded_identifier(value: str, maximum: int, separator: str = '_') -> str:
    if len(value) <= maximum:
        return value

    digest = hashlib.sha256(value.encode('utf-8')).hexdigest()[:8]
    room = maximum - len(separator) - len(digest)
    if room < 1:
        fail(f'Identifier limit [{maximum}] is too small for deterministic hashing.')

    prefix = value[:room].rstrip('._-') or value[:room]
    return f'{prefix}{separator}{digest}'


def _derived_runtime_identity(environment: str, client_key: str, root_domain: str) -> dict[str, Any]:
    if environment not in {'staging', 'production'}:
        fail('Environment must be staging or production.')

    domain = normalize_domain(root_domain)
    key = client_key.strip()
    if not re.fullmatch(r'[a-z0-9][a-z0-9_-]*', key):
        fail(f'Invalid client key [{key}]. Expected lowercase letters, numbers, hyphens, and underscores.')

    stem = runtime_stem(key)
    namespaced_stem = stem if environment == 'production' else f'{stem}_{environment}'
    database = bounded_identifier(namespaced_stem, 64)
    database_user = bounded_identifier(namespaced_stem, 32)

    return {
        'environment': environment,
        'client_key': key,
        'root_domain': domain,
        'runtime_stem': stem,
        'runtime_prefix_stem': namespaced_stem,
        'app_path': f'/var/www/{domain}/engage-core',
        'client_path': f'/var/www/{domain}/engage-core/client/{key}',
        'crm_host': f'crm.{domain}',
        'webhooks_host': f'webhooks.{domain}',
        'webinar_host': f'webinar.{domain}',
        'messaging_host': f'messaging.{domain}',
        'scheduling_default_host': f'booking.{domain}',
        'database_name': database,
        'database_user': database_user,
        'cache_prefix': f'{namespaced_stem}_cache_',
        'redis_prefix': f'{namespaced_stem}_',
        'horizon_prefix': f'{namespaced_stem}_horizon:',
        'horizon_program': f'{domain}-horizon',
        'nginx_site_name': f'{domain}-core',
        'scheduler_marker': f'engage-core:{domain}',
    }


def derive_identity(environment: str, client_repo: str, root_domain: str, client_key_override: str | None, var_root: str) -> dict[str, Any]:
    key = client_key_override.strip() if client_key_override else repo_slug(client_repo)
    identity = _derived_runtime_identity(environment, key, root_domain)
    domain = identity['root_domain']
    state_slug = bounded_identifier(f'{domain}-{environment}', 120, '-')
    identity.update({
        'client_repo_url': client_repo,
        'repo_slug': repo_slug(client_repo),
        'state_file': str(Path(var_root).expanduser() / f'{state_slug}.json'),
    })
    return identity


def derive_audit_identity(environment: str, client_key: str, root_domain: str) -> dict[str, Any]:
    return _derived_runtime_identity(environment, client_key, root_domain)

def dotenv_serialize(value: str) -> str:
    if value != '' and re.fullmatch(r'[A-Za-z0-9_./:@%+,-]+', value):
        return value

    escaped = value.replace('\\', '\\\\').replace('"', '\\"').replace('\n', '\\n')
    return f'"{escaped}"'


def env_values(path: Path) -> dict[str, str]:
    if not path.exists():
        return {}

    values: dict[str, str] = {}
    for raw in path.read_text().splitlines():
        line = raw.strip()
        if not line or line.startswith('#') or '=' not in line:
            continue
        key, value = line.split('=', 1)
        key = key.strip()
        if not re.fullmatch(r'[A-Z][A-Z0-9_]*', key):
            continue
        value = value.strip()
        if len(value) >= 2 and value[0] == value[-1] and value[0] in {'"', "'"}:
            value = value[1:-1]
            if raw.strip().split('=', 1)[1].strip().startswith('"'):
                value = value.replace('\\n', '\n').replace('\\"', '"').replace('\\\\', '\\')
        values[key] = value
    return values


def env_write(path: Path, key: str, value: str) -> None:
    if not re.fullmatch(r'[A-Z][A-Z0-9_]*', key):
        fail(f'Invalid environment key [{key}].')

    path.parent.mkdir(parents=True, exist_ok=True)
    if path.exists():
        original = path.read_text()
        mode = stat.S_IMODE(path.stat().st_mode)
    else:
        original = '# Runtime environment\n'
        mode = 0o640

    replacement = f'{key}={dotenv_serialize(value)}'
    lines = original.splitlines()
    matched = False
    output: list[str] = []
    pattern = re.compile(rf'^{re.escape(key)}=')

    for line in lines:
        if pattern.match(line):
            if not matched:
                output.append(replacement)
                matched = True
            continue
        output.append(line)

    if not matched:
        if output and output[-1] != '':
            output.append('')
        output.append(replacement)

    content = '\n'.join(output).rstrip() + '\n'
    fd, temp_name = tempfile.mkstemp(prefix='.engage-env-', dir=str(path.parent))
    try:
        os.close(fd)
        temp = Path(temp_name)
        temp.write_text(content)
        os.chmod(temp, mode or 0o640)
        os.replace(temp, path)
    finally:
        if os.path.exists(temp_name):
            os.unlink(temp_name)


def env_remove(path: Path, key: str) -> None:
    if not path.exists():
        return
    lines = [line for line in path.read_text().splitlines() if not line.startswith(f'{key}=')]
    path.write_text('\n'.join(lines).rstrip() + '\n')


def load_state(path: Path) -> dict[str, Any]:
    if not path.exists():
        return {'completed_phases': [], 'completed_setup_steps': {}}
    try:
        data = json.loads(path.read_text())
    except json.JSONDecodeError as exc:
        fail(f'Invalid launch state JSON [{path}]: {exc}')
    if not isinstance(data, dict):
        fail(f'Invalid launch state JSON [{path}].')
    data.setdefault('completed_phases', [])
    data.setdefault('completed_setup_steps', {})
    return data


def save_state(path: Path, data: dict[str, Any]) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    fd, temp_name = tempfile.mkstemp(prefix='.engage-state-', dir=str(path.parent))
    try:
        os.close(fd)
        temp = Path(temp_name)
        temp.write_text(json.dumps(data, indent=2, sort_keys=True) + '\n')
        os.chmod(temp, 0o600)
        os.replace(temp, path)
    finally:
        if os.path.exists(temp_name):
            os.unlink(temp_name)


def plan_requirements(plan: dict[str, Any]) -> list[dict[str, Any]]:
    requirements = plan.get('environment_requirements', [])
    return [item for item in requirements if isinstance(item, dict)]


def blocks(requirement: dict[str, Any]) -> bool:
    status = requirement.get('status')
    if status in {'mismatch', 'invalid'}:
        return True
    return requirement.get('requirement') == 'required' and status in {'missing', 'unresolved'}


def related_setup_keys(plan: dict[str, Any]) -> set[str]:
    keys: set[str] = set()
    for step in plan.get('setup_steps', []):
        if not isinstance(step, dict):
            continue
        for key in step.get('environment_keys', []):
            if isinstance(key, str):
                keys.add(key)
    return keys


def requirement_path(requirement: dict[str, Any], root_env: Path, client_env: Path) -> Path:
    return root_env if requirement.get('scope') == 'root' else client_env


def prompt_value(requirement: dict[str, Any], current: str | None, optional: bool = False) -> str | None:
    key = str(requirement.get('key', ''))
    secret = bool(requirement.get('secret', False))
    allowed = [str(value) for value in requirement.get('allowed_values', []) if isinstance(value, (str, int, float))]
    expected = requirement.get('expected_value')
    reason = str(requirement.get('reason', '')).strip()

    if reason:
        print(f'\n{key}: {reason}')

    if expected is not None and not secret:
        expected_text = str(expected)
        if current == expected_text:
            return current
        print(f'Expected value: {expected_text}')
        answer = input(f'Use expected value for {key}? [Y/n]: ').strip().lower()
        if answer in {'', 'y', 'yes'}:
            return expected_text

    if allowed:
        print('Allowed values: ' + ', '.join(allowed))

    suffix = ' (optional; blank keeps it unset)' if optional else ''
    if secret:
        value = getpass.getpass(f'{key}{suffix}: ')
    else:
        default = f' [{current}]' if current else ''
        value = input(f'{key}{default}{suffix}: ')

    value = value.strip()
    if value == '' and current:
        return current
    if value == '' and optional:
        return None
    if value == '':
        print(f'{key} is required.')
        return prompt_value(requirement, current, optional=False)
    if allowed and value not in allowed:
        print(f'Value must be one of: {", ".join(allowed)}')
        return prompt_value(requirement, current, optional=optional)
    return value


def resolve_requirements(plan_path: Path, root_env: Path, client_env: Path, mode: str) -> int:
    plan = json.loads(plan_path.read_text())
    setup_keys = related_setup_keys(plan)
    changed = 0

    for requirement in plan_requirements(plan):
        if not blocks(requirement):
            continue
        key = str(requirement.get('key', ''))
        if mode == 'non-setup' and key in setup_keys:
            continue
        path = requirement_path(requirement, root_env, client_env)
        current = env_values(path).get(key)
        value = prompt_value(requirement, current, optional=False)
        if value is None:
            continue
        if current != value:
            env_write(path, key, value)
            changed += 1
            print(f'Wrote {key} -> {path}')

    return changed


def run_setup_steps(plan_path: Path, root_env: Path, client_env: Path, state_path: Path) -> int:
    plan = json.loads(plan_path.read_text())
    state = load_state(state_path)
    stored_completed = state.get('completed_setup_steps', {})
    if isinstance(stored_completed, dict):
        completed = {str(key): str(value) for key, value in stored_completed.items()}
    elif isinstance(stored_completed, list):
        completed = {str(key): 'legacy' for key in stored_completed}
    else:
        completed = {}

    requirements = {str(item.get('key')): item for item in plan_requirements(plan)}
    completed_now = 0

    for step in plan.get('setup_steps', []):
        if not isinstance(step, dict):
            continue
        key = str(step.get('key', ''))
        fingerprint = hashlib.sha256(
            json.dumps(step, sort_keys=True, separators=(',', ':')).encode('utf-8')
        ).hexdigest()
        if not key or completed.get(key) == fingerprint:
            continue

        print('\n' + '=' * 72)
        print(str(step.get('title', key)).upper())
        print('=' * 72)
        reason = str(step.get('reason', '')).strip()
        if reason:
            print(reason)
        print()
        for index, instruction in enumerate(step.get('instructions', []), start=1):
            print(f'{index}. {instruction}')

        print('\nPress Enter when the external/dashboard work above is complete.')
        print('Use Ctrl+C to stop safely and resume later.')
        input()

        for env_key in step.get('environment_keys', []):
            if not isinstance(env_key, str):
                continue
            requirement = requirements.get(env_key)
            if not requirement:
                continue
            path = requirement_path(requirement, root_env, client_env)
            current = env_values(path).get(env_key)
            status = requirement.get('status')
            if status == 'ready' and current not in {None, ''}:
                print(f'{env_key} is already populated; leaving it unchanged.')
                continue
            optional = requirement.get('requirement') != 'required'
            value = prompt_value(requirement, current, optional=optional)
            if value is None:
                continue
            if current != value:
                env_write(path, env_key, value)
                print(f'Wrote {env_key} -> {path}')

        verification = step.get('verification', [])
        if verification:
            print('\nVerification to perform after runtime is live:')
            for item in verification:
                print(f'  - {item}')

        completed[key] = fingerprint
        state['completed_setup_steps'] = dict(sorted(completed.items()))
        save_state(state_path, state)
        completed_now += 1

    return completed_now


def audit_text(value: Any) -> str:
    return re.sub(r'\s+', ' ', str(value)).strip()


def audit_line(status: str, key: str, message: str) -> None:
    print('\t'.join([
        audit_text(status),
        audit_text(key),
        audit_text(message),
    ]))


def command_derive_audit(args: argparse.Namespace) -> None:
    print(json.dumps(
        derive_audit_identity(args.environment, args.client_key, args.root_domain),
        indent=2,
        sort_keys=True,
    ))


def command_env_collisions(args: argparse.Namespace) -> None:
    search_root = Path(args.search_root)
    exclude = Path(args.exclude).resolve() if args.exclude else None

    if not search_root.exists():
        return

    for path in sorted(search_root.rglob('.env')):
        try:
            resolved = path.resolve()
            if exclude is not None and resolved == exclude:
                continue
            values = env_values(path)
        except (OSError, UnicodeError):
            continue

        if values.get(args.key) == args.value:
            print(path)


def command_plan_modules_stdin(args: argparse.Namespace) -> None:
    try:
        plan = json.load(sys.stdin)
    except json.JSONDecodeError as exc:
        fail(f'Invalid deployment-plan JSON on stdin: {exc}')

    if not isinstance(plan, dict):
        fail('Deployment-plan JSON must decode to an object.')

    for module in plan.get('enabled_modules', []):
        if isinstance(module, str) and module.strip():
            print(module.strip())


def command_plan_audit(args: argparse.Namespace) -> None:
    try:
        plan = json.load(sys.stdin)
    except json.JSONDecodeError as exc:
        fail(f'Invalid deployment-plan JSON on stdin: {exc}')

    if not isinstance(plan, dict):
        fail('Deployment-plan JSON must decode to an object.')

    blocking = [item for item in plan_requirements(plan) if blocks(item)]
    if blocking:
        for item in blocking:
            key = audit_text(item.get('key', 'unknown'))
            scope = audit_text(item.get('scope', 'unknown'))
            owner = audit_text(item.get('owner', 'unknown'))
            status = audit_text(item.get('status', 'unknown'))
            reason = audit_text(item.get('reason', 'Deployment requirement is not ready.'))
            audit_line(
                'BREAKING',
                f'deployment_plan.{key}',
                f'{scope}/{owner} requirement is {status}: {reason}',
            )
    else:
        audit_line(
            'PASS',
            'deployment_plan.ready',
            'No blocking environment requirements were reported.',
        )

    modules = [
        module.strip()
        for module in plan.get('enabled_modules', [])
        if isinstance(module, str) and module.strip()
    ]
    audit_line(
        'PASS',
        'deployment_plan.modules',
        'Enabled modules: ' + (', '.join(modules) if modules else '[none]'),
    )

    unused = [
        audit_text(key)
        for key in plan.get('unused_environment_keys', [])
        if isinstance(key, str) and key.strip()
    ]
    if unused:
        audit_line(
            'WARNING',
            'deployment_plan.unused_environment',
            'Present but currently unused environment keys: ' + ', '.join(unused),
        )

    for step in plan.get('setup_steps', []):
        if not isinstance(step, dict):
            continue
        key = audit_text(step.get('key', 'external_setup'))
        title = audit_text(step.get('title', key))
        verification = [
            audit_text(item)
            for item in step.get('verification', [])
            if isinstance(item, str) and item.strip()
        ]
        suffix = ' Verification: ' + ' '.join(verification) if verification else ''
        audit_line(
            'MANUAL VERIFICATION REQUIRED',
            f'external_setup.{key}',
            f'{title} requires provider/dashboard or real-event verification.{suffix}',
        )



def command_derive(args: argparse.Namespace) -> None:
    print(json.dumps(derive_identity(
        args.environment,
        args.client_repo,
        args.root_domain,
        args.client_key,
        args.state_root,
    ), indent=2, sort_keys=True))


def command_env_set(args: argparse.Namespace) -> None:
    env_write(Path(args.file), args.key, args.value)


def command_env_get(args: argparse.Namespace) -> None:
    value = env_values(Path(args.file)).get(args.key)
    if value is None:
        raise SystemExit(1)
    print(value)


def command_env_remove(args: argparse.Namespace) -> None:
    env_remove(Path(args.file), args.key)


def command_state_init(args: argparse.Namespace) -> None:
    path = Path(args.file)
    data = load_state(path)
    supplied = json.loads(args.identity_json)
    if not isinstance(supplied, dict):
        fail('identity-json must decode to an object.')
    data.update(supplied)
    save_state(path, data)


def command_state_get(args: argparse.Namespace) -> None:
    data = load_state(Path(args.file))
    value: Any = data
    for part in args.key.split('.'):
        if not isinstance(value, dict) or part not in value:
            raise SystemExit(1)
        value = value[part]
    if isinstance(value, (dict, list)):
        print(json.dumps(value))
    elif isinstance(value, bool):
        print('true' if value else 'false')
    elif value is not None:
        print(value)


def command_state_set(args: argparse.Namespace) -> None:
    path = Path(args.file)
    data = load_state(path)
    value: Any = args.value
    if args.json_value:
        value = json.loads(args.value)
    cursor = data
    parts = args.key.split('.')
    for part in parts[:-1]:
        child = cursor.get(part)
        if not isinstance(child, dict):
            child = {}
            cursor[part] = child
        cursor = child
    cursor[parts[-1]] = value
    save_state(path, data)


def command_mark_phase(args: argparse.Namespace) -> None:
    path = Path(args.file)
    data = load_state(path)
    phases = set(str(value) for value in data.get('completed_phases', []))
    phases.add(args.phase)
    data['completed_phases'] = sorted(phases)
    save_state(path, data)


def command_phase_done(args: argparse.Namespace) -> None:
    data = load_state(Path(args.file))
    raise SystemExit(0 if args.phase in data.get('completed_phases', []) else 1)


def command_resolve_requirements(args: argparse.Namespace) -> None:
    changed = resolve_requirements(Path(args.plan), Path(args.root_env), Path(args.client_env), args.mode)
    if args.result_file:
        Path(args.result_file).write_text(str(changed) + '\n')
    else:
        print(changed)


def command_run_setup_steps(args: argparse.Namespace) -> None:
    completed = run_setup_steps(
        Path(args.plan),
        Path(args.root_env),
        Path(args.client_env),
        Path(args.state),
    )
    if args.result_file:
        Path(args.result_file).write_text(str(completed) + '\n')
    else:
        print(completed)


def command_plan_value(args: argparse.Namespace) -> None:
    plan = json.loads(Path(args.plan).read_text())
    value: Any = plan
    for part in args.key.split('.'):
        if isinstance(value, dict):
            value = value.get(part)
        else:
            value = None
        if value is None:
            raise SystemExit(1)
    if isinstance(value, (dict, list)):
        print(json.dumps(value))
    elif isinstance(value, bool):
        print('true' if value else 'false')
    else:
        print(value)


def command_plan_modules(args: argparse.Namespace) -> None:
    plan = json.loads(Path(args.plan).read_text())
    for module in plan.get('enabled_modules', []):
        if isinstance(module, str):
            print(module)


def command_plan_blocking_count(args: argparse.Namespace) -> None:
    plan = json.loads(Path(args.plan).read_text())
    print(sum(1 for item in plan_requirements(plan) if blocks(item)))



def _nginx_server_blocks(text: str) -> list[str]:
    blocks: list[str] = []
    pattern = re.compile(r'\bserver\s*\{')
    position = 0

    while True:
        match = pattern.search(text, position)
        if match is None:
            break

        start = match.start()
        depth = 0
        end = None

        for index in range(match.end() - 1, len(text)):
            char = text[index]
            if char == '{':
                depth += 1
            elif char == '}':
                depth -= 1
                if depth == 0:
                    end = index + 1
                    break

        if end is None:
            break

        blocks.append(text[start:end])
        position = end

    return blocks


def command_nginx_host_owners(args: argparse.Namespace) -> None:
    directory = Path(args.directory)
    host = normalize_domain(args.host)
    owners: list[dict[str, str]] = []

    if not directory.exists():
        print('[]')
        return

    for path in sorted(directory.iterdir()):
        if not path.exists() or path.is_dir():
            continue

        try:
            text = path.read_text(errors='replace')
        except OSError:
            continue

        for block in _nginx_server_blocks(text):
            server_names: list[str] = []
            for match in re.finditer(r'(?m)^\s*server_name\s+([^;]+);', block):
                server_names.extend(
                    item.strip().lower().rstrip('.')
                    for item in match.group(1).split()
                    if item.strip()
                )

            if host not in server_names:
                continue

            root_match = re.search(r'(?m)^\s*root\s+([^;]+);', block)
            root = root_match.group(1).strip() if root_match else ''
            owners.append({
                'config': str(path.resolve()),
                'root': root,
            })

    unique: list[dict[str, str]] = []
    seen: set[tuple[str, str]] = set()
    for item in owners:
        identity = (item['config'], item['root'])
        if identity in seen:
            continue
        seen.add(identity)
        unique.append(item)

    print(json.dumps(unique, sort_keys=True))


def command_origin_host(args: argparse.Namespace) -> None:
    parsed = urlparse(args.origin.strip())
    if parsed.scheme not in {'http', 'https'} or not parsed.hostname:
        raise SystemExit(1)
    print(normalize_domain(parsed.hostname))


def build_parser() -> argparse.ArgumentParser:
    parser = argparse.ArgumentParser(description='Engage Core deployment-launch support helper.')
    sub = parser.add_subparsers(dest='command', required=True)

    derive = sub.add_parser('derive')
    derive.add_argument('--environment', required=True, choices=['staging', 'production'])
    derive.add_argument('--client-repo', required=True)
    derive.add_argument('--root-domain', required=True)
    derive.add_argument('--client-key')
    derive.add_argument('--state-root', default='~/.local/state/engage/deployments')
    derive.set_defaults(func=command_derive)

    derive_audit = sub.add_parser('derive-audit')
    derive_audit.add_argument('--environment', required=True, choices=['staging', 'production'])
    derive_audit.add_argument('--client-key', required=True)
    derive_audit.add_argument('--root-domain', required=True)
    derive_audit.set_defaults(func=command_derive_audit)

    env_set = sub.add_parser('env-set')
    env_set.add_argument('--file', required=True)
    env_set.add_argument('--key', required=True)
    env_set.add_argument('--value', required=True)
    env_set.set_defaults(func=command_env_set)

    env_get = sub.add_parser('env-get')
    env_get.add_argument('--file', required=True)
    env_get.add_argument('--key', required=True)
    env_get.set_defaults(func=command_env_get)

    env_remove_cmd = sub.add_parser('env-remove')
    env_remove_cmd.add_argument('--file', required=True)
    env_remove_cmd.add_argument('--key', required=True)
    env_remove_cmd.set_defaults(func=command_env_remove)

    state_init = sub.add_parser('state-init')
    state_init.add_argument('--file', required=True)
    state_init.add_argument('--identity-json', required=True)
    state_init.set_defaults(func=command_state_init)

    state_get = sub.add_parser('state-get')
    state_get.add_argument('--file', required=True)
    state_get.add_argument('--key', required=True)
    state_get.set_defaults(func=command_state_get)

    state_set = sub.add_parser('state-set')
    state_set.add_argument('--file', required=True)
    state_set.add_argument('--key', required=True)
    state_set.add_argument('--value', required=True)
    state_set.add_argument('--json-value', action='store_true')
    state_set.set_defaults(func=command_state_set)

    mark_phase = sub.add_parser('mark-phase')
    mark_phase.add_argument('--file', required=True)
    mark_phase.add_argument('--phase', required=True)
    mark_phase.set_defaults(func=command_mark_phase)

    phase_done = sub.add_parser('phase-done')
    phase_done.add_argument('--file', required=True)
    phase_done.add_argument('--phase', required=True)
    phase_done.set_defaults(func=command_phase_done)

    resolve = sub.add_parser('resolve-requirements')
    resolve.add_argument('--plan', required=True)
    resolve.add_argument('--root-env', required=True)
    resolve.add_argument('--client-env', required=True)
    resolve.add_argument('--mode', choices=['non-setup', 'all'], default='all')
    resolve.add_argument('--result-file')
    resolve.set_defaults(func=command_resolve_requirements)

    setup = sub.add_parser('run-setup-steps')
    setup.add_argument('--plan', required=True)
    setup.add_argument('--root-env', required=True)
    setup.add_argument('--client-env', required=True)
    setup.add_argument('--state', required=True)
    setup.add_argument('--result-file')
    setup.set_defaults(func=command_run_setup_steps)

    plan_value = sub.add_parser('plan-value')
    plan_value.add_argument('--plan', required=True)
    plan_value.add_argument('--key', required=True)
    plan_value.set_defaults(func=command_plan_value)

    modules = sub.add_parser('plan-modules')
    modules.add_argument('--plan', required=True)
    modules.set_defaults(func=command_plan_modules)

    blocking = sub.add_parser('plan-blocking-count')
    blocking.add_argument('--plan', required=True)
    blocking.set_defaults(func=command_plan_blocking_count)

    plan_modules_stdin = sub.add_parser('plan-modules-stdin')
    plan_modules_stdin.set_defaults(func=command_plan_modules_stdin)

    plan_audit = sub.add_parser('plan-audit')
    plan_audit.set_defaults(func=command_plan_audit)

    collisions = sub.add_parser('env-collisions')
    collisions.add_argument('--search-root', required=True)
    collisions.add_argument('--key', required=True)
    collisions.add_argument('--value', required=True)
    collisions.add_argument('--exclude')
    collisions.set_defaults(func=command_env_collisions)

    nginx_host_owners = sub.add_parser('nginx-host-owners')
    nginx_host_owners.add_argument('--directory', required=True)
    nginx_host_owners.add_argument('--host', required=True)
    nginx_host_owners.set_defaults(func=command_nginx_host_owners)

    origin_host = sub.add_parser('origin-host')
    origin_host.add_argument('--origin', required=True)
    origin_host.set_defaults(func=command_origin_host)

    return parser


def main() -> None:
    args = build_parser().parse_args()
    args.func(args)


if __name__ == '__main__':
    main()