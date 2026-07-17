#!/usr/bin/env python3
from __future__ import annotations

import argparse
import re
from pathlib import Path


def set_setting(section: str, key: str, value: str) -> str:
    pattern = re.compile(rf'(?m)^\s*{re.escape(key)}\s*=.*$')
    replacement = f'{key} = {value}'

    if pattern.search(section):
        return pattern.sub(replacement, section, count=1)

    return section.rstrip() + '\n' + replacement + '\n'


def main() -> None:
    parser = argparse.ArgumentParser()
    parser.add_argument('--input', required=True)
    parser.add_argument('--output', required=True)
    parser.add_argument('--pool-name', default='www')
    parser.add_argument('--php-version', required=True)
    args = parser.parse_args()

    text = Path(args.input).read_text()
    header = f'[{args.pool_name}]'
    start = text.find(header)

    if start < 0:
        raise SystemExit(f'Pool section {header} was not found.')

    next_section = re.search(r'(?m)^\[[^]]+\]\s*$', text[start + len(header):])
    end = len(text)
    if next_section:
        end = start + len(header) + next_section.start()

    section = text[start:end]
    settings = {
        'request_slowlog_timeout': '5s',
        'slowlog': f'/var/log/php/php{args.php_version}-fpm-slow.log',
        'catch_workers_output': 'yes',
        'decorate_workers_output': 'no',
    }

    for key, value in settings.items():
        section = set_setting(section, key, value)

    Path(args.output).write_text(text[:start] + section + text[end:])


if __name__ == '__main__':
    main()
