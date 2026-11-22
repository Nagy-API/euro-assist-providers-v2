#!/usr/bin/env python3
"""Block obvious sensitive file paths in tracked files and require project memory docs.

This is not a full content-based secret scanner; run dedicated secret scanning too.
"""
from pathlib import Path, PurePosixPath
import re
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
                  '.pem', '.key', '.pfx', '.p12', '.p8', '.ppk', '.jks', '.keystore', '.crt',
                  '.kdbx', '.gpg', '.mdb', '.accdb', '.log',
                  '.tar', '.gz', '.tgz', '.bz2', '.xz', '.zst', '.backup', '.pyc')
# Spreadsheet/contact data may only be committed as synthetic fixtures under tests/fixtures/.
DATA_EXTS = ('.csv', '.tsv', '.xlsx', '.xls', '.xlsm', '.xlsb', '.ods', '.vcf', '.ndjson', '.jsonl')
FIXTURE_PREFIX = 'tests/fixtures/'
# Dependency/cache directories are never source, at any depth.
FORBIDDEN_DIRS = {'vendor', 'node_modules', '.venv', '__pycache__'}
# Data-location directories: anything non-source inside is blocked. Plain source files (for
# example app/Exports/ProviderExport.php or app/Models/Uploads/Logo.php) stay allowed.
DATA_DIRS = {'backups', 'dumps', 'exports', 'uploads', 'private-data', 'private_data'}
SOURCE_EXTS = ('.php', '.py', '.js', '.ts', '.md', '.css')
# Filename terms that only matter for non-source files (CredentialsController.php is fine).
SENSITIVE_NAME_TERMS = ('providers.sql', 'providers.rar', 'credentials', 'production-backup',
                        'production_export', 'production-export', 'db_backup', 'db-backup',
                        'providers-export', 'providers_export')
SENSITIVE_JSON = ('auth.json', 'secret', 'secrets', 'service-account', 'adminsdk', 'credential')
SENSITIVE_BASENAMES = ('.htpasswd', '.netrc', '.pgpass', '.git-credentials', 'contacts.txt', 'providers.json')


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
    is_source = low.endswith(SOURCE_EXTS)
    if (base.startswith('.env') or base.endswith('.env') or base.startswith('env.')
            or base.startswith(('id_rsa', 'id_ed25519', 'id_ecdsa'))):
        return True
    parents = path.parts[:-1]
    if any(part in FORBIDDEN_DIRS for part in parents):
        return True
    if 'logs' in parents and base != '.gitignore':
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
    if low.endswith(FORBIDDEN_EXTS) or re.search(r'\.(zip|rar|7z|tar|gz)\.\d+$', low):
        return True
    if base in SENSITIVE_BASENAMES:
        return True
    if low.endswith(DATA_EXTS) and not low.startswith(FIXTURE_PREFIX):
        return True
    if (base.endswith('.json') and any(term in base for term in SENSITIVE_JSON)
            and not low.startswith(FIXTURE_PREFIX)):
        return True
    if base != '.gitignore' and parents[:1] and parents[0] in DATA_DIRS:
        return True  # repo-root data directories hold data only, whatever the extension
    if base != '.gitignore' and not is_source and any(part in DATA_DIRS for part in parents):
        return True
    if base != '.gitignore' and parents[:1] == ('public',) and 'uploads' in parents:
        return True
    if not is_source and any(term in base for term in SENSITIVE_NAME_TERMS):
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
