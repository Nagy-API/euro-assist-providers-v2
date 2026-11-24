#!/usr/bin/env python3
"""Build the INCREMENTAL IT update package (files only, never a database dump).

    python scripts/release/build_update_package.py --version 2.0.0-rc.6 --baseline ab96a23 \
        --php "<path>/php.exe" --out <private output dir outside the repository>

Contents of the ZIP:
  files/                      only the runtime files that differ from the delivered baseline (git diff), the rebuilt
                              public/build and release-manifest.json (sha256 of every code file, read by `ops:check`)
  removed-files.txt           runtime files that no longer exist
  apply-update.ps1            backup + copy + delete helper (never touches .env, storage, vendor, database)
  UPDATE_GUIDE.md             backup, upgrade, migrate, cache refresh, queue setup, verification, rollback
  postman/                    API compatibility collection + local environment (no credentials)
  MANIFEST.json, SHA256SUMS.txt

Refuses: a dirty working tree, any .sql/.env/key/log/backup file, any path outside the allow-list.
"""
from __future__ import annotations

import argparse
import hashlib
import json
import shutil
import subprocess
import sys
import zipfile
from datetime import datetime, timezone
from pathlib import Path

REPO = Path(__file__).resolve().parents[2]
ALLOW = ('app/', 'bootstrap/app.php', 'config/', 'database/migrations/', 'database/seeders/DatabaseSeeder.php', 'lang/', 'resources/', 'routes/', 'public/favicon', 'public/apple-touch-icon.png', 'public/brand/')
FORBIDDEN = ('.sql', '.sqlite', '.db', '.bak', '.dump', '.env', '.pem', '.key', '.log', '.xlsx', '.csv')


def sh(cmd, cwd=REPO, capture=False):
    print('  $', ' '.join(map(str, cmd)), flush=True)
    r = subprocess.run(cmd, cwd=cwd, check=True, text=True, capture_output=capture)
    return r.stdout if capture else ''


def sha256(p: Path) -> str:
    h = hashlib.sha256()
    h.update(p.read_bytes())
    return h.hexdigest()


def main() -> int:
    ap = argparse.ArgumentParser()
    ap.add_argument('--version', required=True)
    ap.add_argument('--baseline', required=True, help='commit of the delivered baseline (rc.5 code: ab96a23)')
    ap.add_argument('--php', required=True)
    ap.add_argument('--out', required=True)
    ap.add_argument('--skip-build', action='store_true')
    a = ap.parse_args()

    out = Path(a.out).resolve()
    if REPO in out.parents or out == REPO:
        sys.exit('The output directory must be outside the repository.')
    if sh(['git', 'status', '--porcelain'], capture=True).strip():
        sys.exit('Working tree is not clean: the package must equal a commit.')
    head = sh(['git', 'rev-parse', 'HEAD'], capture=True).strip()

    name = f'EuroAssist_Providers_V2_UPDATE_{a.version}'
    stage = out / name
    if stage.exists():
        shutil.rmtree(stage)
    files = stage / 'files'
    files.mkdir(parents=True)

    changed, removed = [], []
    for line in sh(['git', 'diff', '--name-status', '--no-renames', a.baseline, 'HEAD'], capture=True).splitlines():
        status, path = line.split('\t', 1)
        if not path.startswith(ALLOW):
            continue
        (removed if status == 'D' else changed).append(path)
    if any(p.lower().endswith(FORBIDDEN) for p in changed):
        sys.exit('A forbidden file type is part of the update.')

    for p in changed:
        dst = files / p
        dst.parent.mkdir(parents=True, exist_ok=True)
        dst.write_bytes((REPO / p).read_bytes())
    (stage / 'removed-files.txt').write_text('# runtime files that no longer exist (relative to the application folder)\n' + '\n'.join(removed) + '\n', encoding='utf8')

    if not a.skip_build:
        sh(['npm', 'run', 'build'], cwd=REPO) if shutil.which('npm') else sys.exit('npm not found')
        shutil.copytree(REPO / 'public' / 'build', files / 'public' / 'build')

    sh([a.php, 'artisan', 'ops:manifest', a.version, f'--path={files / "release-manifest.json"}'])

    for f in ('UPDATE_GUIDE.md', 'apply-update.ps1'):
        shutil.copy(REPO / 'deploy' / 'it-update' / f, stage / f)
    (stage / 'postman').mkdir()
    for f in (REPO / 'docs' / 'postman').glob('*.json'):
        shutil.copy(f, stage / 'postman' / f.name)

    bad = [p for p in stage.rglob('*') if p.is_file() and p.suffix.lower() in FORBIDDEN]
    if bad:
        sys.exit(f'Forbidden files in package: {bad[:3]}')

    entries = sorted(p for p in stage.rglob('*') if p.is_file())
    manifest = {
        'product': 'Euro Assist Providers V2 - incremental update', 'version': a.version, 'git_commit': head, 'baseline_commit': a.baseline,
        'built_at_utc': datetime.now(timezone.utc).strftime('%Y-%m-%dT%H:%M:%SZ'), 'database_dump_included': False,
        'changed_files': len(changed), 'removed_files': len(removed),
        'files': [{'path': str(p.relative_to(stage)).replace('\\', '/'), 'bytes': p.stat().st_size, 'sha256': sha256(p)} for p in entries],
    }
    (stage / 'MANIFEST.json').write_text(json.dumps(manifest, indent=1) + '\n', encoding='utf8')
    (stage / 'SHA256SUMS.txt').write_text(''.join(f"{m['sha256']}  {m['path']}\n" for m in manifest['files']), encoding='utf8')

    zip_path = out / f'{name}.zip'
    with zipfile.ZipFile(zip_path, 'w', zipfile.ZIP_DEFLATED) as z:
        for p in sorted(stage.rglob('*')):
            if p.is_file():
                zi = zipfile.ZipInfo(f'{name}/' + str(p.relative_to(stage)).replace('\\', '/'), (2026, 11, 25, 0, 0, 0))
                zi.compress_type = zipfile.ZIP_DEFLATED
                zi.external_attr = 0o644 << 16
                z.writestr(zi, p.read_bytes())
    digest = sha256(zip_path)
    (out / f'{name}.zip.sha256').write_text(f'{digest}  {zip_path.name}\n')
    print(f'\n{zip_path}\nSHA-256 {digest}\n{len(changed)} changed, {len(removed)} removed files; no database dump.')
    return 0


if __name__ == '__main__':
    raise SystemExit(main())
