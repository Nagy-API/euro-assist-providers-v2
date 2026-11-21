"""Regression tests use synthetic paths and real temporary Git indexes only."""
import importlib.util
from pathlib import Path
import shutil
import subprocess
import sys
import tempfile
import unittest


ROOT = Path(__file__).resolve().parents[2]
SCRIPT = ROOT / 'scripts/check_repo_hygiene.py'
SPEC = importlib.util.spec_from_file_location('repo_hygiene', SCRIPT)
hygiene = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(hygiene)

# Hand-written project contract; do not derive fixtures from the checker.
MEMORY_FILES = (
    'README.md', 'AGENTS.md', 'docs/00_PROJECT_CONTEXT.md',
    'docs/01_CURRENT_STATUS.md', 'docs/02_MASTER_PLAN.md',
    'docs/03_LEGACY_AUDIT.md', 'docs/04_IMPORT_EXPORT_SPEC.md',
    'docs/05_DATA_MIGRATION.md', 'docs/06_API_CONTRACT.md',
    'docs/07_LOCAL_SETUP.md', 'docs/08_SECURITY.md',
    'docs/09_PLESK_DEPLOYMENT.md', 'docs/10_DECISIONS.md',
    'docs/11_WORK_LOG.md', 'docs/12_HANDOFF.md', 'docs/13_CHANGELOG.md',
    'docs/14_GITHUB_BOOTSTRAP.md', 'docs/15_QUALITY_GATES.md',
)


class ForbiddenPathTests(unittest.TestCase):
    def test_only_root_environment_templates_are_allowed(self):
        for name in ('.env', '.env.local', 'nested/.env.example',
                     'nested/.env.testing.example', r'nested\.env',
                     r'nested\.env.example', r'nested\.env.testing.example'):
            with self.subTest(name=name):
                self.assertTrue(hygiene.forbidden(name))
        for name in ('.env.example', '.env.testing.example'):
            with self.subTest(name=name):
                self.assertFalse(hygiene.forbidden(name))

    def test_nested_dependencies_are_blocked_with_either_separator(self):
        for name in ('vendor/package/source.php', 'app/vendor/package.php',
                     'tools/node_modules/pkg/index.js', r'app\vendor\source.php',
                     r'tools\node_modules\pkg\index.js'):
            with self.subTest(name=name):
                self.assertTrue(hygiene.forbidden(name))

    def test_logs_private_data_and_archives_are_blocked(self):
        for name in ('debug.log', 'nested/storage/logs/output.txt',
                     r'nested\storage\logs\output.txt', 'logs/request.txt',
                     'private-data/contacts.csv', 'nested/backups/snapshot.txt',
                     'nested/exports/contacts.csv', 'public/uploads/photo.jpg',
                     'archive.tar', 'archive.tar.xz', 'archive.gz',
                     'snapshot.backup', 'local/server.crt',
                     'local/id_ecdsa', 'nested/__pycache__/module.pyc'):
            with self.subTest(name=name):
                self.assertTrue(hygiene.forbidden(name))

    def test_case_cannot_bypass_sensitive_path_checks(self):
        for name in ('APP/VENDOR/pkg.php', r'APP\NODE_MODULES\pkg.js',
                     'Nested/.ENV.EXAMPLE', 'Archive.ZIP', 'Credentials.json'):
            with self.subTest(name=name):
                self.assertTrue(hygiene.forbidden(name))

    def test_legitimate_source_and_synthetic_fixtures_are_allowed(self):
        for name in ('app/Models/Provider.php', 'docs/08_SECURITY.md',
                     '.github/workflows/repo-hygiene.yml',
                     'tests/fixtures/synthetic-providers.csv',
                     'tests/fixtures/synthetic.xlsx', 'package-lock.json',
                     'composer.lock', 'resources/images/brand.svg'):
            with self.subTest(name=name):
                self.assertFalse(hygiene.forbidden(name))

    def test_environment_variants_and_generated_private_storage_are_blocked(self):
        for name in ('.envrc', 'nested/.environment', 'storage/app/private/report.csv',
                     r'nested\storage\app\report.csv',
                     'storage/framework/sessions/session.txt',
                     'nested/storage/framework/cache/item',
                     'tools/.venv/lib/package.py', 'production-export.csv',
                     'production-backup.json', 'providers-export.xlsx'):
            with self.subTest(name=name):
                self.assertTrue(hygiene.forbidden(name))

    def test_laravel_directory_markers_are_allowed_but_runtime_files_are_not(self):
        for name in ('storage/app/private/.gitignore', 'storage/app/public/.gitignore',
                     'storage/framework/cache/data/.gitignore',
                     'storage/framework/sessions/.gitignore',
                     'storage/framework/testing/.gitignore',
                     'storage/framework/views/.gitignore', 'storage/logs/.gitignore',
                     'bootstrap/cache/.gitignore'):
            with self.subTest(name=name):
                self.assertFalse(hygiene.forbidden(name))
        for name in ('storage/logs/runtime.txt', 'vendor/.gitignore',
                     'storage/framework/testing/synthetic-result.txt'):
            with self.subTest(name=name):
                self.assertTrue(hygiene.forbidden(name))

    def test_generated_bootstrap_php_caches_are_blocked_with_either_separator(self):
        for name in ('bootstrap/cache/config.php', 'bootstrap/cache/packages.php',
                     'nested/bootstrap/cache/services.php',
                     r'nested\bootstrap\cache\config.php',
                     'Nested/Bootstrap/Cache/Config.PHP'):
            with self.subTest(name=name):
                self.assertTrue(hygiene.forbidden(name))
        for name in ('bootstrap/cache/.gitignore',
                     'nested/bootstrap/cache/.gitignore',
                     r'nested\bootstrap\cache\.gitignore',
                     'app/Services/cache.php'):
            with self.subTest(name=name):
                self.assertFalse(hygiene.forbidden(name))


class GitIndexIntegrationTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(prefix='repo-hygiene-test-')
        self.addCleanup(self.temp.cleanup)
        self.repo = Path(self.temp.name)
        self.git('init', '--quiet')
        for name in MEMORY_FILES:
            self.write(name, '# Synthetic project memory\n')
        script = self.repo / 'scripts/check_repo_hygiene.py'
        script.parent.mkdir(parents=True)
        shutil.copyfile(SCRIPT, script)
        self.git('add', '.')

    def git(self, *args):
        return subprocess.run(['git', *args], cwd=self.repo, check=True,
                              capture_output=True)

    def write(self, name, content='synthetic fixture only\n'):
        path = self.repo / name
        path.parent.mkdir(parents=True, exist_ok=True)
        path.write_text(content, encoding='utf-8')

    def check(self):
        # Run elsewhere to catch accidental dependence on the shell's cwd.
        return subprocess.run([sys.executable,
                               str(self.repo / 'scripts/check_repo_hygiene.py')],
                              cwd=ROOT, capture_output=True, text=True,
                              encoding='utf-8')

    def test_clean_index_and_root_templates_pass(self):
        for name in ('.env.example', '.env.testing.example'):
            self.write(name, 'EXAMPLE_VALUE=\n')
        self.git('add', '.')
        result = self.check()
        self.assertEqual(result.returncode, 0, result.stdout + result.stderr)

    def test_tracked_paths_with_spaces_and_unicode_fail_safely(self):
        for name in ('nested app/node_modules/pkg/index.js',
                     'synthetic-العربية/.env.example', 'nested/debug.log'):
            self.write(name)
        self.git('add', '.')
        result = self.check()
        self.assertEqual(result.returncode, 1, result.stdout + result.stderr)
        for name in ('nested app/node_modules/pkg/index.js',
                     'synthetic-العربية/.env.example', 'nested/debug.log'):
            self.assertIn(name, result.stdout, result.stdout + result.stderr)

    def test_missing_memory_doc_is_rejected(self):
        (self.repo / 'docs/12_HANDOFF.md').unlink()
        self.git('add', '-u')
        result = self.check()
        self.assertEqual(result.returncode, 1, result.stdout + result.stderr)
        self.assertIn('docs/12_HANDOFF.md', result.stdout)

    def test_untracked_memory_doc_cannot_satisfy_the_gate(self):
        self.git('rm', '--cached', 'docs/12_HANDOFF.md')
        result = self.check()
        self.assertEqual(result.returncode, 1, result.stdout + result.stderr)
        self.assertIn('docs/12_HANDOFF.md', result.stdout)

    def test_quality_gates_doc_is_required(self):
        (self.repo / 'docs/15_QUALITY_GATES.md').unlink()
        self.git('add', '-u')
        result = self.check()
        self.assertEqual(result.returncode, 1, result.stdout + result.stderr)
        self.assertIn('docs/15_QUALITY_GATES.md', result.stdout)

    def test_ignored_untracked_local_environment_is_not_reported(self):
        self.write('.gitignore', '.env\n')
        self.write('.env', 'SYNTHETIC_LOCAL_ONLY=\n')
        self.git('add', '.gitignore')
        result = self.check()
        self.assertEqual(result.returncode, 0, result.stdout + result.stderr)

    def test_gitignore_retains_storage_markers_without_adding_runtime_files(self):
        shutil.copyfile(ROOT / '.gitignore', self.repo / '.gitignore')
        markers = ('storage/app/private/.gitignore', 'storage/app/public/.gitignore',
                   'storage/framework/cache/data/.gitignore',
                   'storage/framework/sessions/.gitignore',
                   'storage/framework/testing/.gitignore',
                   'storage/framework/views/.gitignore', 'storage/logs/.gitignore')
        for name in markers:
            self.write(name, '*\n!.gitignore\n')
        for name in ('storage/app/private/raw.csv', 'storage/logs/runtime.txt',
                     'storage/framework/cache/data/entry',
                     'storage/framework/testing/result.txt', '.env',
                     'nested/.env.example', 'nested/vendor/pkg.php'):
            self.write(name)
        self.write('.env.example', 'EXAMPLE=\n')
        self.git('add', '.')
        tracked = self.git('ls-files', '-z').stdout.decode('utf-8').split('\0')
        for name in (*markers, '.env.example'):
            self.assertIn(name, tracked)
        for name in ('storage/app/private/raw.csv', 'storage/logs/runtime.txt',
                     'storage/framework/cache/data/entry',
                     'storage/framework/testing/result.txt', '.env',
                     'nested/.env.example', 'nested/vendor/pkg.php'):
            self.assertNotIn(name, tracked)

    def test_normal_staging_keeps_source_lockfiles_and_synthetic_export_fixtures(self):
        shutil.copyfile(ROOT / '.gitignore', self.repo / '.gitignore')
        source = ('app/Providers/AppServiceProvider.php',
                  'app/Providers/RepositoryServiceProvider.php',
                  'app/Services/backup.php', 'composer.lock', 'package-lock.json',
                  'tests/fixtures/synthetic-export.csv',
                  'tests/fixtures/synthetic-export.xlsx',
                  'tests/fixtures/synthetic-backup.csv',
                  'bootstrap/cache/.gitignore')
        private = ('Providers.sql', 'Providers.rar', 'Providers/snapshot.txt',
                   'production-backup.json', 'production-export.csv',
                   'providers-export.xlsx', 'private-data/contacts.csv',
                   'exports/contacts.csv', 'storage/logs/runtime.txt',
                   'bootstrap/cache/config.php', '.env')
        for name in (*source, *private):
            self.write(name)
        self.git('add', '.')
        tracked = self.git('ls-files', '-z').stdout.decode('utf-8').split('\0')
        for name in source:
            with self.subTest(source=name):
                self.assertIn(name, tracked)
        for name in private:
            with self.subTest(private=name):
                self.assertNotIn(name, tracked)
        result = self.check()
        self.assertEqual(result.returncode, 0, result.stdout + result.stderr)

    def test_force_added_bootstrap_php_caches_are_rejected_but_markers_pass(self):
        shutil.copyfile(ROOT / '.gitignore', self.repo / '.gitignore')
        markers = ('bootstrap/cache/.gitignore',
                   'nested/bootstrap/cache/.gitignore')
        caches = ('bootstrap/cache/config.php',
                  'nested/bootstrap/cache/services.php')
        for name in markers:
            self.write(name)
        self.git('add', '.')
        result = self.check()
        self.assertEqual(result.returncode, 0, result.stdout + result.stderr)
        for name in caches:
            self.write(name, '<?php return ["synthetic" => true];\n')
        self.git('add', '--force', *caches)
        result = self.check()
        self.assertEqual(result.returncode, 1, result.stdout + result.stderr)
        for name in caches:
            self.assertIn('FORBIDDEN tracked path: ' + name, result.stdout)
        for name in markers:
            self.assertNotIn('FORBIDDEN tracked path: ' + name, result.stdout)

    def test_script_outside_git_fails_closed(self):
        with tempfile.TemporaryDirectory(prefix='repo-hygiene-no-git-') as other:
            folder = Path(other) / 'scripts'
            folder.mkdir()
            shutil.copyfile(SCRIPT, folder / 'check_repo_hygiene.py')
            result = subprocess.run([sys.executable,
                                     str(folder / 'check_repo_hygiene.py')],
                                    capture_output=True, text=True)
        self.assertEqual(result.returncode, 2, result.stdout + result.stderr)


if __name__ == '__main__':
    unittest.main()
