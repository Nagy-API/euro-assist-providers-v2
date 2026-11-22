#!/usr/bin/env python3
"""Build the Euro Assist Providers V2 IT delivery ZIP from a clean Git commit.

The ZIP mirrors the practical layout of the legacy handoff (application folder + SQL file + notes)
while keeping Laravel safe: the web server document root must be `providers/public`.

    python scripts/release/build_release.py --version 2.0.0-rc.1 \
        --data-sql <path to EuroAssist_Providers_V2_DATA_<version>.sql> --out <private output dir>

What it does (and nothing else):
  1. refuses a dirty working tree (the package must equal a commit);
  2. exports the commit with `git archive` (tracked files only: no .env, no data, no caches);
  3. installs production Composer dependencies (--no-dev, optimized autoloader) and builds the
     frontend with npm, then removes node_modules (Node.js is NOT needed on the server);
  4. removes development-only files (tests, CI, UAT launchers, synthetic seeder, QA configs);
  5. adds the IT documents from deploy/it-package/, the optional data SQL, MANIFEST.json and
     SHA256SUMS.txt; then writes a deterministic ZIP and prints its SHA-256.

The data SQL is never read from or written to the repository. The output directory must be outside it.
"""
from __future__ import annotations

import argparse
import hashlib
import json
import os
import shutil
import stat
import subprocess
import sys
import zipfile
from datetime import datetime, timezone
from pathlib import Path

REPO = Path(__file__).resolve().parents[2]
APP_DIR = 'providers'

# Development-only paths removed from the production application folder.
DEV_ONLY = [
    'tests', '.github', 'scripts/uat', 'start-uat.ps1', 'stop-uat.ps1', 'reset-uat.ps1',
    'phpunit.xml', 'phpunit.mysql.xml', 'phpstan.neon', '.editorconfig',
    'database/seeders/SyntheticProvidersSeeder.php', 'node_modules', '.claude',
]
FORBIDDEN_SUFFIXES = ('.sql', '.sqlite', '.db', '.bak', '.dump', '.env', '.pem', '.key', '.log', '.xlsx', '.csv')


def run(cmd: list[str], cwd: Path, env: dict[str, str] | None = None) -> None:
    print('  $', ' '.join(cmd), flush=True)
    subprocess.run(cmd, cwd=cwd, check=True, env=env)


def sha256(path: Path) -> str:
    h = hashlib.sha256()
    with path.open('rb') as fh:
        for chunk in iter(lambda: fh.read(1 << 20), b''):
            h.update(chunk)
    return h.hexdigest()


def rmtree(path: Path) -> None:
    def onerror(func, p, _exc):
        os.chmod(p, stat.S_IWRITE)
        func(p)

    if path.is_dir():
        shutil.rmtree(path, onerror=onerror)
    elif path.exists():
        path.unlink()


def check_data_sql(path: Path) -> None:
    """Refuse a data dump that could overwrite a database or carries authentication material."""
    import re
    text = path.read_text(encoding='utf-8')
    rules = {
        'authentication/transient table rows': r'INSERT INTO `(users|sessions|personal_access_tokens|password_reset_tokens|jobs|failed_jobs|job_batches|cache|cache_locks)`',
        'DROP statement': r'(?im)^\s*DROP\s',
        'CREATE DATABASE statement': r'(?im)^\s*CREATE\s+DATABASE',
        'USE statement': r'(?im)^\s*USE\s',
        'password hash': r'\$2[aby]\$\d\d\$|\$argon2',
    }
    for label, pattern in rules.items():
        if re.search(pattern, text):
            sys.exit(f'Refusing the data SQL: it contains a {label}.')


def main() -> int:
    ap = argparse.ArgumentParser()
    ap.add_argument('--version', required=True)
    ap.add_argument('--out', required=True, type=Path)
    ap.add_argument('--data-sql', type=Path)
    ap.add_argument('--php', default='php')
    ap.add_argument('--composer', required=True, help='path to composer.phar')
    ap.add_argument('--npm', default='npm')
    args = ap.parse_args()
    args.npm = shutil.which(args.npm) or args.npm  # resolves npm.cmd on Windows

    out = args.out.resolve()
    if REPO in out.parents or out == REPO:
        sys.exit('Refusing: the output directory must be outside the repository.')
    status = subprocess.run(['git', 'status', '--porcelain'], cwd=REPO, capture_output=True, text=True, check=True).stdout
    if status.strip():
        sys.exit('Refusing: the working tree is not clean; commit first so the package equals a commit.')
    commit = subprocess.run(['git', 'rev-parse', 'HEAD'], cwd=REPO, capture_output=True, text=True, check=True).stdout.strip()

    import re
    if not re.fullmatch(r'[0-9A-Za-z][0-9A-Za-z.\-]{0,40}', args.version):
        sys.exit('Refusing: --version may contain only letters, digits, dots and hyphens.')
    name = f'EuroAssist_Providers_V2_{args.version}'
    stage = out / f'{name}.staging'
    rmtree(stage)
    root = stage / name
    app = root / APP_DIR
    app.mkdir(parents=True)

    print('[1/5] Exporting commit', commit)
    tar = stage / 'src.tar'
    run(['git', 'archive', '--format=tar', '-o', str(tar), 'HEAD'], REPO)
    import tarfile
    with tarfile.open(tar) as tf:
        tf.extractall(app, filter='data')
    tar.unlink()

    print('[2/5] Production dependencies and frontend build')
    # APP_ENV other than "production": the production boot guard needs a real .env, which the build has not.
    env = dict(os.environ, COMPOSER_NO_INTERACTION='1', APP_ENV='packaging')
    run([args.php, args.composer, 'install', '--no-dev', '--optimize-autoloader', '--no-interaction', '--no-progress', '--prefer-dist'], app, env)
    run([args.npm, 'ci', '--no-audit', '--no-fund', '--prefer-offline'], app)
    run([args.npm, 'run', 'build'], app)

    print('[3/5] Removing development-only files')
    for rel in DEV_ONLY:
        rmtree(app / rel)
    # Keep the environment-independent package manifest (packages.php, services.php); drop anything
    # environment-specific so the server builds its own config/route/event caches.
    for cached in ('config.php', 'routes-v7.php', 'events.php'):
        (app / 'bootstrap' / 'cache' / cached).unlink(missing_ok=True)

    print('[4/5] Adding IT documents and data')
    # IT documents come from the exported COMMIT (tracked files only), never from the working tree:
    # a gitignored file such as a filled-in .env next to them must not reach the ZIP.
    docs = app / 'deploy' / 'it-package'
    for item in sorted(docs.iterdir()):
        target = root / item.name
        if item.is_dir():
            shutil.copytree(item, target)
        else:
            shutil.copy2(item, target)
    data_rel = None
    if args.data_sql:
        check_data_sql(args.data_sql)
        (root / 'database').mkdir(exist_ok=True)
        shutil.copy2(args.data_sql, root / 'database' / args.data_sql.name)
        data_rel = f'database/{args.data_sql.name}'

    # Safety net over the WHOLE package: nothing secret or data-like except the checked data SQL.
    for p in root.rglob('*'):
        if p.is_file():
            rel = p.relative_to(root).as_posix()
            low = p.name.lower()
            if rel == data_rel or 'vendor' in p.parts:
                continue
            if low == '.env' or (low.startswith('.env.') and not low.endswith('.example')) or low.endswith(FORBIDDEN_SUFFIXES):
                sys.exit(f'Refusing: unexpected file in the package: {rel}')
    if any(part == '.git' for p in root.rglob('*') for part in p.relative_to(root).parts):
        sys.exit('Refusing: .git found in the package.')

    files = sorted(p for p in root.rglob('*') if p.is_file())
    manifest = {
        'product': 'Euro Assist Providers V2',
        'version': args.version,
        'git_commit': commit,
        'built_at_utc': datetime.now(timezone.utc).strftime('%Y-%m-%dT%H:%M:%SZ'),
        'document_root': f'{APP_DIR}/public',
        'php_required': '>=8.3 (tested 8.3.37 and 8.4.28)',
        'data_sql': f'database/{args.data_sql.name}' if args.data_sql else None,
        'file_count': len(files),
        'files': [{'path': p.relative_to(root).as_posix(), 'bytes': p.stat().st_size, 'sha256': sha256(p)} for p in files],
    }
    (root / 'MANIFEST.json').write_text(json.dumps(manifest, indent=1, ensure_ascii=False) + '\n', encoding='utf-8', newline='\n')
    with (root / 'SHA256SUMS.txt').open('w', encoding='utf-8', newline='\n') as fh:
        for f in manifest['files']:
            fh.write(f"{f['sha256']}  {f['path']}\n")
        fh.write(f"{sha256(root / 'MANIFEST.json')}  MANIFEST.json\n")

    print('[5/5] Writing ZIP')
    zpath = out / f'{name}.zip'
    if zpath.exists():
        zpath.unlink()
    with zipfile.ZipFile(zpath, 'w', compression=zipfile.ZIP_DEFLATED, compresslevel=9) as zf:
        for p in sorted(root.rglob('*')):
            if p.is_file():
                info = zipfile.ZipInfo(f'{name}/' + p.relative_to(root).as_posix(), date_time=(2026, 11, 22, 0, 0, 0))
                info.compress_type = zipfile.ZIP_DEFLATED
                info.external_attr = 0o644 << 16
                with p.open('rb') as fh:
                    zf.writestr(info, fh.read())
    digest = sha256(zpath)
    (out / f'{name}.zip.sha256').write_text(f'{digest}  {zpath.name}\n', encoding='utf-8', newline='\n')
    rmtree(stage)
    print(f'ZIP: {zpath}\nSHA-256: {digest}\nFiles: {len(files) + 2}\nCommit: {commit}')
    return 0


if __name__ == '__main__':
    raise SystemExit(main())
