"""Static safety checks for the owner-UAT PowerShell scripts (they cannot run on Linux CI).

They guard the properties the scripts rely on: one hard-coded UAT database shared with
config/uat.php, protected databases never targeted, an exact confirmation phrase for resets,
process stopping by our own marker only (never by port), and no credentials in the scripts.
"""

import re
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
COMMON = ROOT / "scripts" / "uat" / "uat-common.ps1"
START = ROOT / "start-uat.ps1"
STOP = ROOT / "stop-uat.ps1"
RESET = ROOT / "reset-uat.ps1"
CONFIG = ROOT / "config" / "uat.php"


def read(path: Path) -> str:
    return path.read_text(encoding="utf-8")


class UatScriptSafetyTest(unittest.TestCase):
    def test_single_uat_database_shared_with_the_application_config(self):
        ps = re.search(r"\$Script:UatDatabase\s*=\s*'([^']+)'", read(COMMON))
        php = re.search(r"'database'\s*=>\s*'([^']+)'", read(CONFIG))
        self.assertIsNotNone(ps)
        self.assertIsNotNone(php)
        self.assertEqual(ps.group(1), "euro_assist_v2_uat")
        self.assertEqual(ps.group(1), php.group(1))

    def test_protected_databases_match_and_exclude_the_uat_database(self):
        ps = set(re.findall(r"'(euro_assist_[a-z0-9_]+)'", re.search(r"\$Script:UatProtected\s*=\s*@\(([^)]*)\)", read(COMMON)).group(1)))
        php_block = re.search(r"'protected_databases'\s*=>\s*\[(.*?)\]", read(CONFIG), re.S).group(1)
        php = set(re.findall(r"'(euro_assist_[a-z0-9_]+)'", php_block))
        self.assertEqual(ps, php)
        self.assertIn("euro_assist_v2_local", ps)
        self.assertIn("euro_assist_legacy_audit_20261121", ps)
        self.assertNotIn("euro_assist_v2_uat", ps)

    def test_reset_refuses_other_targets_and_needs_the_exact_phrase(self):
        text = read(RESET)
        self.assertRegex(text, r"\$Target -cne \$Script:UatDatabase")
        self.assertIn('$Phrase = "RESET $($Script:UatDatabase)"', text)
        self.assertRegex(text, r"\$Confirm -cne \$Phrase")
        self.assertIn("The UAT application is running", text)
        self.assertIn("does not match its recorded SHA-256", text)
        # The only database ever dropped is the UAT constant.
        drops = re.findall(r"DROP DATABASE[^;]*", text)
        self.assertTrue(drops)
        for statement in drops:
            self.assertIn("$($Script:UatDatabase)", statement)

    def test_start_verifies_the_target_before_starting_anything(self):
        text = read(START)
        self.assertLess(text.index("uat:check"), text.index("Start-UatBackgroundPhp"))
        self.assertIn("Test-UatPort $candidate", text)

    def test_background_processes_do_not_inherit_console_handles(self):
        common = read(COMMON)
        # ShellExecute (no handle inheritance) so a caller's pipes are never held open.
        self.assertIn("$psi.UseShellExecute = $true", common)
        self.assertNotIn("-RedirectStandardOutput", read(START))

    def test_processes_are_stopped_only_by_our_marker_never_by_port(self):
        common = read(COMMON)
        self.assertIn("CommandLine.Contains($Marker)", common)
        for script in (COMMON, START, STOP, RESET):
            text = read(script)
            self.assertNotRegex(text, r"Get-NetTCPConnection[^\n]*\|[^\n]*Stop-Process")
            self.assertNotIn("taskkill", text.lower())

    def test_no_credentials_or_global_changes_in_scripts(self):
        for script in (COMMON, START, STOP, RESET):
            text = read(script)
            self.assertNotRegex(text, r"(?i)password\s*=\s*['\"][^'\"$]+['\"]")
            self.assertNotIn("SetEnvironmentVariable", text)  # never changes the machine/user PATH
            self.assertNotIn("New-Service", text)
            self.assertNotRegex(text, r"(?i)Write-(Host|Output)[^\n]*DB_PASSWORD")


if __name__ == "__main__":
    unittest.main()
