#!/usr/bin/env python3
"""Block obvious sensitive file paths in tracked files and require project memory docs.

This is not a full content-based secret scanner; run dedicated secret scanning too.
"""
from pathlib import Path, PurePosixPath
import subprocess
import sys

BASE = Path(__file__).resolve().parents[1]
REQUIRED = [
    'README.md', 'AGENTS.md', 'docs/00_PROJECT_CONTEXT.md',
    'docs/01_CURRENT_STATUS.md', 'docs/02_MASTER_PLAN.md',
    'docs/03_LEGACY_AUDIT.md', 'docs/04_IMPORT_EXPORT_SPEC.md',
    'docs/05_DATA_MIGRATION.md', 'docs/06_API_CONTRACT.md',
    'docs/07_LOCAL_SETUP.md', 'docs/08_SECURITY.md', 'docs/09_PLESK_DEPLOYMENT.md',
    'docs/10_DECISIONS.md', 'docs/11_WORK_LOG.md', 'docs/12_HANDOFF.md',
    'docs/13_CHANGELOG.md', 'docs/14_GITHUB_BOOTSTRAP.md', 'docs/15_QUALITY_GATES.md',
]
FORBIDDEN_EXTS = ('.sql', '.dump', '.bak', '.rar', '.zip', '.7z', '.sqlite', '.sqlite3', '.db',
                  '.pem', '.key', '.pfx', '.p12', '.jks', '.keystore', '.crt', '.log',
                  '.tar', '.gz', '.tgz', '.bz2', '.xz', '.zst', '.backup', '.pyc')
FORBIDDEN_DIRS = {
    'vendor', 'node_modules', '.venv', '__pycache__', 'backups', 'dumps',
    'exports', 'uploads', 'private-data', 'private_data',
}


def tracked_paths():
    completed = subprocess.run(['git', 'ls-files', '-z'], cwd=BASE, capture_output=True)
    if completed.returncode != 0:
        print('ERROR: must run from a valid Git repository', file=sys.stderr)
        sys.exit(2)
    return [p.decode('utf-8', errors='surrogateescape')
            for p in completed.stdout.split(b'\0') if p]


def forbidden(name):
    # Git uses '/', but callers and Windows-style input must follow the same rules.
    low = name.replace('\\', '/').lower()
    path = PurePosixPath(low)
    base = path.name
    if low in ('.env.example', '.env.testing.example'):
        return False
    if (base.startswith('.env')
            or base.startswith(('id_rsa', 'id_ed25519', 'id_ecdsa'))):
        return True
    if any(part in FORBIDDEN_DIRS for part in path.parts[:-1]):
        return True
    if 'logs' in path.parts[:-1] and base != '.gitignore':
        return True
    if low.endswith('.php') and (low.startswith('bootstrap/cache/')
                                or '/bootstrap/cache/' in low):
        return True
    if base != '.gitignore' and any(
            low.startswith(prefix) or '/' + prefix in low
            for prefix in ('storage/app/', 'storage/framework/cache/',
                           'storage/framework/sessions/', 'storage/framework/views/',
                           'storage/framework/testing/')):
        return True
    if low.endswith(FORBIDDEN_EXTS):
        return True
    if any(term in base for term in ('providers.sql', 'providers.rar', 'credentials', 'production-backup',
                                     'production_export', 'production-export',
                                     'db_backup', 'db-backup', 'providers-export',
                                     'providers_export')):
        return True
    return False


def main():
    # Avoid Windows console code pages crashing on tracked multilingual filenames.
    sys.stdout.reconfigure(encoding='utf-8', errors='backslashreplace')
    tracked = tracked_paths()
    absent = [p for p in REQUIRED if p not in tracked or not (BASE / p).is_file()]
    bad = [p for p in tracked if forbidden(p)]
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
