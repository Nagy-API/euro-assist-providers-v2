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


# Fixed, documented placeholder of migrated accounts awaiting activation (App\Models\User::ACTIVATION_REQUIRED_PASSWORD).
# It can never verify; it is the ONLY password value a release SQL may contain.
ACTIVATION_PLACEHOLDER = '$2y$12$EuroAssistV2ActivationRequiredNoPasswordSetYet.LocKED'


TRANSIENT_TABLES = ('sessions', 'personal_access_tokens', 'password_reset_tokens', 'jobs', 'failed_jobs', 'job_batches', 'cache', 'cache_locks')

# Session variables a dump may set (mysqldump / mariadb-dump headers and footers). Nothing else.
_SET_VARIABLES = (
    'NAMES', 'character_set_client', 'character_set_results', 'collation_connection', 'TIME_ZONE', 'time_zone',
    'UNIQUE_CHECKS', 'FOREIGN_KEY_CHECKS', 'SQL_MODE', 'SQL_NOTES', 'autocommit', 'NOTE_VERBOSITY',
)
# Values a dump header/footer assigns: saved user variables, 0/1/NULL, utf8mb4 and the two literal
# values mariadb-dump writes. Anything else (e.g. NO_BACKSLASH_ESCAPES, ANSI_QUOTES, another charset)
# could desynchronize this parser from the server and is refused (review R6).
_SET_VALUE = r"(?:@[A-Za-z_]+|NULL|[01]|utf8mb4|utf8mb4_[a-z0-9_]+|'NO_AUTO_VALUE_ON_ZERO'|'\+00:00')"


def _split_statements(text: str) -> list[str]:
    """Split SQL into statements on ';' outside quotes/backticks/comments. `-- ` line comments are dropped;
    /* */ comments stay inside the statement text so the allowlist can refuse them."""
    out: list[str] = []
    buf: list[str] = []
    i, n = 0, len(text)
    quote: str | None = None
    while i < n:
        c = text[i]
        if quote:
            buf.append(c)
            if c == '\\' and quote != '`' and i + 1 < n:
                buf.append(text[i + 1])
                i += 2
                continue
            if c == quote:
                if i + 1 < n and text[i + 1] == quote:
                    buf.append(text[i + 1])
                    i += 2
                    continue
                quote = None
            i += 1
            continue
        if c in "'\"`":
            quote = c
            buf.append(c)
        elif text.startswith('--', i) and (i + 2 >= n or text[i + 2] in ' \t\r\n'):
            j = text.find('\n', i)
            i = n if j < 0 else j
            continue
        elif text.startswith('/*', i):
            j = text.find('*/', i + 2)
            if j < 0:
                sys.exit('Refusing the data SQL: unterminated comment.')
            buf.append(text[i:j + 2])
            i = j + 2
            continue
        elif c == '#':
            sys.exit('Refusing the data SQL: # comments are not allowed.')
        elif c == '\\':
            sys.exit('Refusing the data SQL: a backslash outside a quoted value (mysql client command) is not allowed.')
        elif c == ';':
            stmt = ''.join(buf).strip()
            if stmt:
                out.append(stmt)
            buf = []
        else:
            buf.append(c)
        i += 1
    if quote:
        sys.exit('Refusing the data SQL: unterminated quoted value.')
    rest = ''.join(buf).strip()
    if rest:
        out.append(rest)
    return out


def _literal_tuples(body: str) -> list[list[str | None]]:
    """Parse `(v,...),(v,...)` where every value is a quoted string, NULL or a plain number.
    Hex/bit literals, X'..', charset introducers, functions, expressions and adjacent string
    concatenation are refused."""
    import re
    rows: list[list[str | None]] = []
    i, n = 0, len(body)
    number = re.compile(r'-?\d+(?:\.\d+)?(?:[eE][-+]?\d+)?')
    escapes = {'0': '\0', 'n': '\n', 'r': '\r', 't': '\t', 'Z': '\x1a', 'b': '\b'}
    while True:
        while i < n and body[i] in ' \t\r\n':
            i += 1
        if i >= n or body[i] != '(':
            raise ValueError('expected (')
        i += 1
        row: list[str | None] = []
        while True:
            while i < n and body[i] in ' \t\r\n':
                i += 1
            if i < n and body[i] == "'":
                i += 1
                val: list[str] = []
                closed = False
                while i < n:
                    c = body[i]
                    if c == '\\' and i + 1 < n:
                        val.append(escapes.get(body[i + 1], body[i + 1]))
                        i += 2
                        continue
                    if c == "'":
                        if i + 1 < n and body[i + 1] == "'":
                            val.append("'")
                            i += 2
                            continue
                        i += 1
                        closed = True
                        break
                    val.append(c)
                    i += 1
                if not closed:
                    raise ValueError('unterminated string')
                row.append(''.join(val))
            else:
                m = number.match(body, i)
                if body.startswith('NULL', i) and (i + 4 >= n or body[i + 4] in ' \t\r\n,)'):
                    row.append(None)
                    i += 4
                elif m and (m.end() >= n or body[m.end()] in ' \t\r\n,)'):
                    row.append(m.group(0))
                    i = m.end()
                else:
                    raise ValueError('only quoted strings, NULL and plain numbers are allowed')
            while i < n and body[i] in ' \t\r\n':
                i += 1
            if i < n and body[i] == ',':
                i += 1
                continue
            if i < n and body[i] == ')':
                i += 1
                break
            raise ValueError('expected , or ) after a value')
        rows.append(row)
        while i < n and body[i] in ' \t\r\n':
            i += 1
        if i >= n:
            return rows
        if body[i] != ',':
            raise ValueError('expected , between rows')
        i += 1


def _column_names(body: str) -> list[str]:
    """Lower-cased column names of a CREATE TABLE body: top-level comma-separated items that start with a
    backticked name (keys and constraints start with a keyword and are skipped)."""
    items: list[str] = []
    depth, quote, buf = 0, None, []
    for c in body:
        if quote:
            buf.append(c)
            if c == quote:
                quote = None
            continue
        if c in "'\"`":
            quote = c
        elif c == '(':
            depth += 1
        elif c == ')':
            depth -= 1
        elif c == ',' and depth == 0:
            items.append(''.join(buf))
            buf = []
            continue
        buf.append(c)
    items.append(''.join(buf))
    names = []
    for item in items:
        item = item.strip()
        if item.startswith('`'):
            names.append(item[1:item.index('`', 1)].lower())
    return names


def check_data_sql(path: Path) -> None:
    """ALLOWLIST check of a data dump (review R6): every statement must be one of the shapes a plain
    mariadb-dump/mysqldump of the release database produces, otherwise the dump is refused.

    Allowed: known session SETs (also as /*!NNNNN ... */ version comments), /*!40000 ALTER TABLE `t`
    DISABLE|ENABLE KEYS */, CREATE TABLE `t` (...) ENGINE=..., LOCK TABLES `t` WRITE, UNLOCK TABLES and
    INSERT INTO `t` [(`c`,...)] VALUES with literal-only tuples into an unqualified table that is not a
    session/token/transient table. Every value of every row is scanned for password hashes (only the
    activation placeholder may appear); users rows must carry exactly the placeholder and no remember token.
    """
    import re
    text = path.read_text(encoding='utf-8')
    ident = r'`[A-Za-z0-9_]+`|[A-Za-z0-9_]+'
    set_var = '|'.join(re.escape(v) for v in _SET_VARIABLES)
    assignment = (rf'(?:@[A-Za-z_]+\s*=\s*@@(?:{set_var})'  # save a system value into a user variable
                  rf'|(?:@@SESSION\.|SESSION\s+)?(?:{set_var})\s*=\s*{_SET_VALUE})')
    set_stmt = re.compile(rf'(?is)SET\s+(?:NAMES\s+utf8mb4(?:\s+COLLATE\s+utf8mb4_[a-z0-9_]+)?|{assignment}(?:\s*,\s*{assignment})*)')
    version = re.compile(r'(?s)/\*M?!\d{5,6}\s+(.*?)\s*\*/')  # MySQL /*!N and MariaDB /*M!N
    keys = re.compile(rf'(?is)ALTER\s+TABLE\s+(?:{ident})\s+(?:DISABLE|ENABLE)\s+KEYS')
    create = re.compile(rf"(?is)CREATE\s+TABLE\s+({ident})\s*\((.*)\)(?:\s+(?:DEFAULT\s+)?(?:ENGINE|CHARSET|CHARACTER\s+SET|COLLATE|AUTO_INCREMENT|ROW_FORMAT)\s*=\s*[A-Za-z0-9_]+)+")
    forbidden_ddl = re.compile(r"(?i)\bGENERATED\b|\bAS\s*\(|\bVIRTUAL\b|\bPERSISTENT\b|\bSTORED\b|\bPARTITION\b|\bDIRECTORY\b"
                               r"|\bCONNECTION\b|\bDEFAULT\s*\(|\b0x|\bX'|\bB'|\bUNHEX\b|\bCONCAT|\bFROM_BASE64\b|\bSELECT\b|\bTRIGGER\b")
    insert = re.compile(rf'(?is)INSERT\s+INTO\s+({ident})\s*(\((?:\s*`[A-Za-z0-9_]+`\s*,?)+\))?\s*VALUES\s*(\(.*)')

    without_placeholder = text.replace(ACTIVATION_PLACEHOLDER, '')
    if re.search(r'\$2[abxy]\$', without_placeholder) or re.search(r'(?i)\$argon2', text):
        sys.exit('Refusing the data SQL: it contains a password hash other than the activation placeholder.')

    users_cols: list[str] | None = None
    for stmt in _split_statements(text):
        m = version.fullmatch(stmt)
        if m:
            inner = m.group(1)
            if '/*' in inner or not (set_stmt.fullmatch(inner) or keys.fullmatch(inner)):
                sys.exit('Refusing the data SQL: unexpected statement inside a version comment.')
            continue
        if '/*' in stmt:
            sys.exit('Refusing the data SQL: comments inside statements are not allowed.')
        if set_stmt.fullmatch(stmt) or re.fullmatch(r'(?i)UNLOCK\s+TABLES', stmt) \
                or re.fullmatch(rf'(?i)LOCK\s+TABLES\s+(?:{ident})\s+WRITE', stmt):
            continue
        m = create.fullmatch(stmt)
        if m:
            engines = re.findall(r'(?i)\bENGINE\s*=\s*([A-Za-z0-9_]+)', stmt[stmt.rfind(')'):])
            if engines != ['InnoDB'] and [e.lower() for e in engines] != ['innodb']:
                sys.exit('Refusing the data SQL: only ENGINE=InnoDB tables are allowed.')
            if forbidden_ddl.search(re.sub(r'`[^`]*`', '``', m.group(2))):  # keywords, never column names
                sys.exit('Refusing the data SQL: generated, expression, partition or foreign-storage clauses are not allowed.')
            if m.group(1).strip('`').lower() == 'users':
                users_cols = _column_names(m.group(2))
            continue
        m = insert.fullmatch(stmt)
        if not m:
            sys.exit(f'Refusing the data SQL: statement not allowed: {stmt[:60]!r}')
        table = m.group(1).strip('`').lower()
        if table in TRANSIENT_TABLES:
            sys.exit('Refusing the data SQL: it contains session, token, reset or transient table rows.')
        try:
            rows = _literal_tuples(m.group(3))
        except ValueError as e:
            sys.exit(f'Refusing the data SQL: rows of `{table}` are not plain literals ({e}).')
        cols = [c.lower() for c in re.findall(r'`([^`]+)`', m.group(2))] if m.group(2) else None
        for row in rows:
            for value in row:
                if value is None:
                    continue
                if re.search(r'(?i)\$argon2', value):
                    sys.exit('Refusing the data SQL: it contains an argon2 password hash.')
                if re.search(r'\$2[abxy]\$', value) and value != ACTIVATION_PLACEHOLDER:
                    sys.exit('Refusing the data SQL: it contains a usable password hash (only the activation placeholder is allowed).')
            if table == 'users':
                layout = cols or users_cols
                if not layout or 'password' not in layout or len(layout) != len(row):
                    sys.exit('Refusing the data SQL: users rows without a known column layout.')
                values = dict(zip(layout, row))
                if values.get('password') != ACTIVATION_PLACEHOLDER:
                    sys.exit('Refusing the data SQL: a users row has a password other than the activation placeholder.')
                if values.get('remember_token') is not None:
                    sys.exit('Refusing the data SQL: a users row has a remember token.')


MEDIA_DIRS = ('provider-photos', 'type-icons', 'featured-logos')
MEDIA_NAME = r'[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\.(?:jpg|png|webp)'
MEDIA_MAX_BYTES = 4 * 1024 * 1024


def _magic_ok(path: Path) -> bool:
    head = path.read_bytes()[:12]
    ext = path.suffix.lower()
    if ext == '.png':
        return head.startswith(b'\x89PNG\r\n\x1a\n')
    if ext == '.jpg':
        return head.startswith(b'\xff\xd8\xff')
    if ext == '.webp':
        return head[:4] == b'RIFF' and head[8:12] == b'WEBP'
    return False


def check_media(media: Path, data_sql: Path | None) -> list[str]:
    """Validate the private media folder (ADR-018) and return its relative file paths.

    Only the three V2 media folders, only server-generated names (uuid.jpg|png|webp), real image
    magic bytes, no links, bounded size, and EXACTLY the set of media paths the data SQL references.
    """
    import re
    if not media.is_dir():
        sys.exit('Refusing: --media-dir is not a folder.')
    found: list[str] = []
    for p in sorted(media.rglob('*')):
        rel = p.relative_to(media).as_posix()
        is_junction = getattr(p, 'is_junction', lambda: False)()
        if p.is_symlink() or is_junction or media.resolve() not in p.resolve().parents:
            sys.exit(f'Refusing: link in the media folder: {rel}')
        if p.is_file() and p.stat().st_nlink > 1:
            sys.exit(f'Refusing: hard-linked file in the media folder: {rel}')
        if p.is_dir():
            if rel not in MEDIA_DIRS:
                sys.exit(f'Refusing: unexpected folder in the media folder: {rel}')
            continue
        parts = rel.split('/')
        if len(parts) != 2 or parts[0] not in MEDIA_DIRS or not re.fullmatch(MEDIA_NAME, parts[1]):
            sys.exit(f'Refusing: unexpected file in the media folder: {rel}')
        if p.stat().st_size > MEDIA_MAX_BYTES or not _magic_ok(p):
            sys.exit(f'Refusing: not an accepted image: {rel}')
        found.append(rel)
    referenced: set[str] = set()
    if data_sql is not None:
        text = data_sql.read_text(encoding='utf-8')
        referenced = set(re.findall(rf'(?:{"|".join(MEDIA_DIRS)})/{MEDIA_NAME}', text))
    missing = sorted(referenced - set(found))
    extra = sorted(set(found) - referenced)
    if missing or extra:
        sys.exit(f'Refusing: media folder and data SQL disagree ({len(missing)} referenced but missing, {len(extra)} present but unreferenced).')
    return found


def main() -> int:
    ap = argparse.ArgumentParser()
    ap.add_argument('--version', required=True)
    ap.add_argument('--out', required=True, type=Path)
    ap.add_argument('--data-sql', type=Path)
    ap.add_argument('--php', default='php')
    ap.add_argument('--composer', required=True, help='path to composer.phar')
    ap.add_argument('--npm', default='npm')
    ap.add_argument('--media-dir', type=Path, help='private folder with provider-photos/, type-icons/, featured-logos/ (ADR-018)')
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
    media_files: list[str] = []
    if args.media_dir:
        if not args.data_sql:
            sys.exit('Refusing: --media-dir requires --data-sql (the media must match the data).')
        media_files = check_media(args.media_dir.resolve(), args.data_sql)
        target = app / 'storage' / 'app' / 'private'
        for rel in media_files:
            (target / rel).parent.mkdir(parents=True, exist_ok=True)
            shutil.copy2(args.media_dir / rel, target / rel)
    elif args.data_sql:
        import re as _re
        if _re.search(rf'(?:{"|".join(MEDIA_DIRS)})/{MEDIA_NAME}', args.data_sql.read_text(encoding='utf-8')):
            sys.exit('Refusing: the data SQL references media files; pass --media-dir.')

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
        'media_files': len(media_files),
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
