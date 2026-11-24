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
        users_table = ("CREATE TABLE `users` (\n  `id` bigint NOT NULL,\n  `name` varchar(255),\n  `email` varchar(255),\n"
                       "  `email_verified_at` timestamp NULL,\n  `password` varchar(255),\n  `role` varchar(20),\n"
                       "  `remember_token` varchar(100),\n  PRIMARY KEY (`id`)\n) ENGINE=InnoDB;\n")
        placeholder = builder.ACTIVATION_PLACEHOLDER
        good_user = f"(2,'Admin','admin@example.test',NULL,'{placeholder}','admin',NULL)"
        with tempfile.TemporaryDirectory() as tmp:
            ok = Path(tmp) / "ok.sql"
            ok.write_text("-- no DROP or CREATE DATABASE statements here\nCREATE TABLE `providers` (`id` int) ENGINE=InnoDB;\n"
                          "INSERT INTO `providers` VALUES (1,'text; with a semicolon and the word use inside');\n"
                          + users_table + f"INSERT INTO `users` VALUES {good_user};\n",
                          encoding="utf-8")
            builder.check_data_sql(ok)
            usable = "$2y$12$" + "b" * 53  # any bcrypt string other than the placeholder
            for bad in (users_table + f"INSERT INTO `users` VALUES (2,'A','a@example.test',NULL,'{usable}','admin',NULL);",
                        users_table + f"INSERT INTO `users` VALUES {good_user},(3,'B','b@example.test',NULL,'plain-text','admin',NULL);",
                        users_table + f"INSERT INTO `users` VALUES (2,'A','a@example.test',NULL,'{placeholder}','admin','remember-me');",
                        f"INSERT INTO `users` VALUES {good_user};",  # unknown column layout
                        "INSERT INTO `sessions` VALUES (1);", "INSERT INTO `personal_access_tokens` VALUES (1);",
                        "insert ignore into sessions values (1);", "REPLACE INTO `providers` VALUES (1);",
                        "INSERT INTO `providers` VALUES (1); DROP TABLE `providers`;",
                        "DROP TABLE `providers`;", "drop table providers;", "CREATE DATABASE prod;", "USE prod;",
                        "SELECT 1; use prod;", "GRANT ALL ON *.* TO 'x'@'%';", "DELETE FROM `providers`;",
                        "UPDATE `users` SET role='admin';", "TRUNCATE `providers`;",
                        "SELECT * INTO OUTFILE '/tmp/x' FROM providers;",
                        "SELECT '$2y$12$abcdefghijklmnopqrstuv';", "SELECT '$2x$10$abcdefghijklmnopqrstuv';",
                        r"SELECT '\$2y\$12\$abc';", "SELECT 0x243279243132;", "SELECT '$argon2id$v=19$m=65536';"):
                f = Path(tmp) / "bad.sql"
                f.write_text("CREATE TABLE `t` (`id` int);\n" + bad + "\n", encoding="utf-8")
                with self.assertRaises(SystemExit, msg=bad):
                    builder.check_data_sql(f)

    def test_data_sql_guard_is_an_allowlist(self):
        """Review R6: every bypass found by the security review is refused; real dump shapes pass."""
        spec = importlib.util.spec_from_file_location("builder", BUILDER)
        builder = importlib.util.module_from_spec(spec)
        spec.loader.exec_module(builder)
        placeholder = builder.ACTIVATION_PLACEHOLDER
        users_table = ("CREATE TABLE `users` (\n  `id` bigint NOT NULL,\n  `email` varchar(255),\n  `password` varchar(255),\n"
                       "  `remember_token` varchar(100),\n  PRIMARY KEY (`id`)\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;\n")
        good = ("/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;\n/*!40101 SET NAMES utf8mb4 */;\n"
                "/*M!100616 SET @OLD_NOTE_VERBOSITY=@@NOTE_VERBOSITY, NOTE_VERBOSITY=0 */;\n"
                "/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;\n"
                "-- a comment line\nCREATE TABLE `providers` (\n  `id` int NOT NULL,\n  `name` text\n) ENGINE=InnoDB;\n"
                "LOCK TABLES `providers` WRITE;\n/*!40000 ALTER TABLE `providers` DISABLE KEYS */;\n"
                "INSERT INTO `providers` VALUES (1,'text; with a semicolon -- and; use DROP words'),(2,NULL),(3,'it''s \\'quoted\\''),(4,-1.5);\n"
                "/*!40000 ALTER TABLE `providers` ENABLE KEYS */;\nUNLOCK TABLES;\n"
                + users_table + f"INSERT INTO `users` VALUES (1,'a@example.test','{placeholder}',NULL);\n")
        hash_ = "$2y$12$" + "c" * 53
        bad_cases = [
            "/* x */ DROP TABLE providers;", "/*!40101 DROP DATABASE euro */;", "DROP/**/TABLE providers;",
            "DELETE/**/FROM providers;", "UPDATE `euro`.`users` SET password='x';", "UPDATE users u SET password='x';",
            "UPDATE LOW_PRIORITY users SET password='x';", "ALTER TABLE providers ADD x int;",
            "CREATE TRIGGER t BEFORE INSERT ON providers FOR EACH ROW SET NEW.id=1;",
            "INSERT INTO `providers` VALUES (1,'a') ON DUPLICATE KEY UPDATE name='b';",
            "SET @s=CONCAT('DR','OP TABLE providers'); PREPARE q FROM @s; EXECUTE q;",
            "INSERT INTO `euro`.`sessions` VALUES (1);", "INSERT INTO /**/`sessions` VALUES (1);",
            "insert ignore into sessions values (1);", "REPLACE INTO `providers` VALUES (1);",
            users_table + f"INSERT INTO `users` VALUE (1,'a@example.test','{placeholder}','remember');",
            users_table + "INSERT INTO users SET password=CONCAT(CHAR(36),'2y');",
            users_table + f"INSERT INTO `euro`.`users` VALUES (1,'a@example.test','{hash_}',NULL);",
            users_table + "INSERT INTO `users` SELECT * FROM other;",
            "INSERT INTO `providers` VALUES (1,X'24327924');", "INSERT INTO `providers` VALUES (1,0x24327924);",
            "INSERT INTO `providers` VALUES (1,UNHEX('24327924'));", "INSERT INTO `providers` VALUES (1,FROM_BASE64('JDJ5'));",
            "INSERT INTO `providers` VALUES (1,'$2' 'y$12$abc');", "INSERT INTO `providers` VALUES (1,_binary 'x');",
            f"INSERT INTO `providers` VALUES (1,'{hash_}');", "INSERT INTO `providers` VALUES (1,'$argon2id$v=19');",
            users_table + "INSERT INTO `users` VALUES (1,'a@example.test','plain',NULL);",
            users_table + f"INSERT INTO `users` VALUES (1,'a@example.test','{placeholder}','tok');",
            "CREATE TABLE `t` (`id` int) ENGINE=InnoDB AS SELECT 1;", "USE prod;", "CREATE DATABASE prod;",
            "GRANT ALL ON *.* TO 'x'@'%';", "SELECT * INTO OUTFILE '/tmp/x' FROM providers;", "SET PASSWORD = 'x';",
            "SET GLOBAL general_log = 1;", "# comment\nINSERT INTO `providers` VALUES (1);", "TRUNCATE `providers`;",
            # re-review R6b
            "CREATE TABLE `t` (`a` int) ENGINE=InnoDB \\g DROP TABLE `providers` \\g CREATE TABLE `u` (`b` int) ENGINE=InnoDB;",
            "CREATE TABLE `t` (`a` int) ENGINE=InnoDB \\! whoami\n;",
            "SET SQL_MODE='NO_BACKSLASH_ESCAPES';", "SET @m='ANSI_QUOTES';", "SET NAMES gbk;", "SET character_set_client=sjis;",
            "CREATE TABLE `t` (`p` varchar(255) DEFAULT '" + hash_ + "') ENGINE=InnoDB;",
            "CREATE TABLE `t` (`p` varchar(255) DEFAULT (concat('a','b'))) ENGINE=InnoDB;",
            "CREATE TABLE `t` (`p` int GENERATED ALWAYS AS (1) VIRTUAL) ENGINE=InnoDB;",
            "CREATE TABLE `t` (`p` int) ENGINE=FEDERATED;",
            "CREATE TABLE `t` (`p` int) ENGINE=InnoDB COMMENT='mysql://x';",
            users_table + f"INSERT INTO `users` (`id`,`email`,`password`,`Remember_Token`) VALUES (1,'a@example.test','{placeholder}','tok');",
        ]
        with tempfile.TemporaryDirectory() as tmp:
            ok = Path(tmp) / "ok.sql"
            ok.write_text(good, encoding="utf-8")
            builder.check_data_sql(ok)
            for bad in bad_cases:
                f = Path(tmp) / "bad.sql"
                f.write_text(good + bad + "\n", encoding="utf-8")
                with self.assertRaises(SystemExit, msg=bad):
                    builder.check_data_sql(f)

    def test_it_documents_are_clean_and_match_the_verified_release_data(self):
        """The IT-facing documents shipped inside the ZIP: no control characters (a backslash-a once became a BEL in a path),
        no counts or statements left over from an earlier candidate, and the verified rc.3 data figures present."""
        docs = sorted(PKG.glob("*.md"))
        self.assertGreaterEqual(len(docs), 7)
        for doc in docs:
            text = read(doc)
            self.assertEqual([c for c in text if ord(c) < 32 and c not in (chr(9), chr(10), chr(13))], [], f"control character in {doc.name}")
            for stale in ("2,185", "334 ", "2,188", "RELEASE_VALIDATION", "D8 pending", "billing", "not migrated", "rc.2"):
                self.assertNotIn(stale, text, f"stale text {stale!r} in {doc.name}")
        for doc in sorted((ROOT / "docs").glob("*.md")) + [ROOT / "README.md", ROOT / "AGENTS.md"]:
            bad = [c for c in read(doc) if ord(c) < 32 and c not in (chr(9), chr(10), chr(13))]
            self.assertEqual(bad, [], f"control character in {doc.name}")
        imp = read(PKG / "DATABASE_IMPORT.md")
        for fact in ("1,402", "790", "2,192", "2,999", "-- 195", "-- 2192", "-- 1402", "-- 5"):
            self.assertIn(fact, imp)
        self.assertIn("1,402", read(PKG / "VERIFY_AND_SMOKE_TEST.md"))
        fresh = read(PKG / "FRESH_DATA_SYNC.md")
        for needed in ("legacy:apply-review-manifest", "legacy:import-media", "--media-dir", "uploads/logos"):
            self.assertIn(needed, fresh)
        self.assertIn("storage/app/private", read(PKG / "BACKUP_AND_ROLLBACK.md"))

    def test_media_folder_guard(self):
        spec = importlib.util.spec_from_file_location("builder", BUILDER)
        builder = importlib.util.module_from_spec(spec)
        spec.loader.exec_module(builder)
        png = b"\x89PNG\r\n\x1a\n" + b"\x00" * 32
        name = "0f0e0d0c-0b0a-4908-8706-050403020100.png"
        with tempfile.TemporaryDirectory() as tmp:
            media = Path(tmp) / "media"
            (media / "provider-photos").mkdir(parents=True)
            (media / "provider-photos" / name).write_bytes(png)
            sql = Path(tmp) / "d.sql"
            sql.write_text(f"INSERT INTO `providers` VALUES (1,'provider-photos/{name}');\n", encoding="utf-8")
            self.assertEqual(builder.check_media(media, sql), [f"provider-photos/{name}"])
            for bad_name, content in (("not-a-uuid.png", png), ("1f0e0d0c-0b0a-4908-8706-050403020100.php", png),
                                      ("2f0e0d0c-0b0a-4908-8706-050403020100.png", b"<?php echo 1;")):
                f = media / "provider-photos" / bad_name
                f.write_bytes(content)
                with self.assertRaises(SystemExit, msg=bad_name):
                    builder.check_media(media, sql)
                f.unlink()
            (media / "uploads").mkdir()
            with self.assertRaises(SystemExit):
                builder.check_media(media, sql)
            (media / "uploads").rmdir()
            sql.write_text("INSERT INTO `providers` VALUES (1,'provider-photos/3f0e0d0c-0b0a-4908-8706-050403020100.png');\n", encoding="utf-8")
            with self.assertRaises(SystemExit):
                builder.check_media(media, sql)  # referenced but missing / present but unreferenced

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
