#!/usr/bin/env python3
"""Block obvious sensitive file paths in tracked files and require project memory docs.

This is not a full content-based secret scanner; run dedicated secret scanning too.
"""
from pathlib import Path
import subprocess
import sys

BASE = Path(__file__).resolve().parents[1]
REQUIRED = [
    'README.md', 'AGENTS.md', 'docs/00_PROJECT_CONTEXT.md',
    'docs/01_CURRENT_STATUS.md', 'docs/02_MASTER_PLAN.md',
    'docs/10_DECISIONS.md', 'docs/11_WORK_LOG.md', 'docs/12_HANDOFF.md',
]
FORBIDDEN_EXTS = ('.sql', '.dump', '.bak', '.rar', '.zip', '.7z', '.sqlite', '.sqlite3', '.db',
                  '.pem', '.key', '.pfx', '.p12', '.jks', '.keystore')


def tracked_paths():
    completed = subprocess.run(['git', 'ls-files', '-z'], cwd=BASE, capture_output=True)
    if completed.returncode != 0:
        print('ERROR: must run from a valid Git repository', file=sys.stderr)
        sys.exit(2)
    return [Path(p.decode('utf-8')) for p in completed.stdout.split(b'\0') if p]


def forbidden(name):
    low = name.lower()
    base = Path(low).name
    if low in ('.env.example', '.env.testing.example'):
        return False
    if base == '.env' or base.startswith('.env.') or base.startswith('id_rsa') or base.startswith('id_ed25519'):
        return True
    if low.startswith('vendor/') or low.startswith('node_modules/') or low.startswith('storage/logs/'):
        return True
    if low.endswith(FORBIDDEN_EXTS) or low.endswith(('.sql.gz', '.tar.gz', '.tgz')):
        return True
    if any(term in base for term in ('providers.sql', 'providers.rar', 'credentials', 'production-backup',
                                     'production_export', 'db_backup')):
        return True
    return False


def main():
    absent = [p for p in REQUIRED if not (BASE / p).is_file()]
    bad = [str(p) for p in tracked_paths() if forbidden(p.as_posix())]
    if absent or bad:
        for p in absent:
            print(f'MISSING required project memory file: {p}')
        for p in bad:
            print(f'FORBIDDEN tracked path: {p}')
        sys.exit(1)
    print('PASS: required project docs present; no obviously forbidden tracked file paths.')
    print('NOTE: still run secret/content scanning and code review before publishing.')


if __name__ == '__main__':
    main()
