#!/usr/bin/env python3
from __future__ import annotations

import argparse
from pathlib import Path


def main() -> None:
    parser = argparse.ArgumentParser()
    parser.add_argument('--input', required=True)
    parser.add_argument('--output', required=True)
    parser.add_argument('--access-log', required=True)
    args = parser.parse_args()

    source = Path(args.input)
    text = source.read_text()

    plain = f'access_log {args.access_log};'
    formatted = f'access_log {args.access_log} engage_core_json;'

    if formatted not in text:
        count = text.count(plain)
        if count != 1:
            raise SystemExit(
                f'Expected exactly one access log line [{plain}], found {count}.'
            )
        text = text.replace(plain, formatted, 1)

    request_header = 'add_header X-Request-ID $request_id always;'
    if request_header not in text:
        anchor = '    charset utf-8;'
        count = text.count(anchor)
        if count != 1:
            raise SystemExit(
                f'Expected exactly one server charset anchor, found {count}.'
            )
        text = text.replace(anchor, anchor + '\n\n    ' + request_header, 1)

    fastcgi_include = 'include /etc/nginx/snippets/engage-core-request-id-fastcgi.conf;'
    if fastcgi_include not in text:
        anchor = '        include snippets/fastcgi-php.conf;'
        count = text.count(anchor)
        if count != 1:
            raise SystemExit(
                f'Expected exactly one PHP FastCGI include anchor, found {count}.'
            )
        text = text.replace(anchor, anchor + '\n        ' + fastcgi_include, 1)

    Path(args.output).write_text(text)


if __name__ == '__main__':
    main()
