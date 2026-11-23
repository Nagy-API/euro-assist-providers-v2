"""Static checks for the IT delivery package sources (deploy/it-package, public/web.config, builder).

They protect the properties the IT team relies on: a safe production environment template with no real
secrets, the IIS front-controller configuration, a complete document set, and a builder that refuses to
package a dirty tree, writes outside the repository and blocks secret/data files in the application folder.
"""

import importlib.util
import re
import tempfile
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
PKG = ROOT / "deploy" / "it-package"
BUILDER = ROOT / "scripts" / "release" / "build_release.py"


def read(path: Path) -> str:
    return path.read_text(encoding="utf-8")


class ReleasePackageTest(unittest.TestCase):
    def test_required_it_documents_exist(self):
        for name in ("00_READ_ME_FIRST.md", "INSTALL_GUIDE.md", "DATABASE_IMPORT.md", "VERIFY_AND_SMOKE_TEST.md",
                     "BACKUP_AND_ROLLBACK.md", "FRESH_DATA_SYNC.md", "KNOWN_LIMITATIONS_AND_OPEN_DECISIONS.md",
                     "production.env.example"):
            self.assertTrue((PKG / name).is_file(), name)

    def test_env_template_is_production_safe_and_secret_free(self):
        env = read(PKG / "production.env.example")
        self.assertRegex(env, r"(?m)^APP_ENV=production$")
        self.assertRegex(env, r"(?m)^APP_DEBUG=false$")
        self.assertRegex(env, r"(?m)^APP_KEY=$")
        self.assertRegex(env, r"(?m)^APP_URL=https://")
        self.assertRegex(env, r"(?m)^SESSION_SECURE_COOKIE=true$")
        self.assertRegex(env, r"(?m)^SESSION_ENCRYPT=true$")
        self.assertRegex(env, r"(?m)^PROVIDERS_API_PUBLIC_READ=false$")
        self.assertNotRegex(env, r"(?m)^TRUSTED_PROXIES=\*")
        # Every credential is a placeholder, never a value.
        for key in ("DB_PASSWORD", "DB_USERNAME", "MAIL_PASSWORD"):
            m = re.search(rf"(?m)^{key}=(.*)$", env)
            if m:
                self.assertTrue(m.group(1) == "" or m.group(1).startswith("<"), key)

    def test_iis_config_routes_to_front_controller_and_hides_secrets(self):
        cfg = read(ROOT / "public" / "web.config")
        self.assertIn('url="index.php"', cfg)
        self.assertIn('<directoryBrowse enabled="false" />', cfg)
        self.assertIn('segment=".env"', cfg)
        self.assertIn('segment=".htaccess"', cfg)
        self.assertIn('<Files "web.config">', read(ROOT / "public" / ".htaccess"))

    def test_builder_guards(self):
        src = read(BUILDER)
        self.assertIn("working tree is not clean", src)
        self.assertIn("must be outside the repository", src)
        self.assertIn("'--no-dev'", src)
        self.assertIn("unexpected file in the package", src)
        self.assertIn(".git found in the package", src)
        for dev in ("'tests'", "'start-uat.ps1'", "'database/seeders/SyntheticProvidersSeeder.php'", "'node_modules'"):
            self.assertIn(dev, src)

    def test_documents_point_the_document_root_at_public(self):
        self.assertIn("providers/public", read(PKG / "00_READ_ME_FIRST.md"))
        self.assertIn("Document root = `providers-v2/public`", read(PKG / "INSTALL_GUIDE.md"))
        self.assertIn("no `DROP TABLE`", read(PKG / "DATABASE_IMPORT.md"))

    def test_data_sql_guard_refuses_overwrite_and_credentials(self):
        spec = importlib.util.spec_from_file_location("builder", BUILDER)
        builder = importlib.util.module_from_spec(spec)
        spec.loader.exec_module(builder)
        with tempfile.TemporaryDirectory() as tmp:
            ok = Path(tmp) / "ok.sql"
            placeholder = builder.ACTIVATION_PLACEHOLDER
            ok.write_text("-- no DROP or CREATE DATABASE statements here\nCREATE TABLE `providers` (`id` int);\n"
                          "INSERT INTO `providers` VALUES (1);\n"
                          f"INSERT INTO `users` VALUES (2,'Admin','admin@example.test',NULL,'{placeholder}','admin',NULL);\n",
                          encoding="utf-8")
            builder.check_data_sql(ok)
            usable = "$2y$12$" + "b" * 53  # any bcrypt string other than the placeholder
            for bad in (f"INSERT INTO `users` VALUES (2,'A','a@example.test',NULL,'{usable}','admin',NULL);",
                        "INSERT INTO `sessions` VALUES (1);", "INSERT INTO `personal_access_tokens` VALUES (1);",
                        "DROP TABLE `providers`;", "CREATE DATABASE prod;", "USE prod;",
                        "SELECT '$2y$12$abcdefghijklmnopqrstuv';", "SELECT '$argon2id$v=19$m=65536';"):
                f = Path(tmp) / "bad.sql"
                f.write_text("CREATE TABLE `t` (`id` int);\n" + bad + "\n", encoding="utf-8")
                with self.assertRaises(SystemExit, msg=bad):
                    builder.check_data_sql(f)

    def test_builder_takes_it_documents_from_the_commit_and_scans_the_whole_package(self):
        src = read(BUILDER)
        self.assertIn("docs = app / 'deploy' / 'it-package'", src)
        self.assertIn("for p in root.rglob('*')", src)

    def test_activation_placeholder_is_identical_in_builder_and_application(self):
        spec = importlib.util.spec_from_file_location("builder", BUILDER)
        builder = importlib.util.module_from_spec(spec)
        spec.loader.exec_module(builder)
        php = re.search(r"ACTIVATION_REQUIRED_PASSWORD = '([^']+)'", read(ROOT / "app" / "Models" / "User.php"))
        self.assertIsNotNone(php)
        self.assertEqual(php.group(1), builder.ACTIVATION_PLACEHOLDER)
        self.assertEqual(len(builder.ACTIVATION_PLACEHOLDER), 60)

if __name__ == "__main__":
    unittest.main()
