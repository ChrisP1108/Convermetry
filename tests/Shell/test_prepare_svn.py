#!/usr/bin/env python3
"""Release integration tests against disposable local SVN repositories only."""

import json
import os
from pathlib import Path
import shutil
import stat
import subprocess
import tempfile
import unittest
import zipfile


ROOT = Path(__file__).resolve().parents[2]
SVN = shutil.which("svn")
SVNADMIN = shutil.which("svnadmin")
# Deliberate shell metacharacters: these must stay literal and off argv/env.
PASSWORD = 'test$()`touch SHOULD_NOT_EXIST`!#= password'


@unittest.skipUnless(SVN and SVNADMIN, "svn and svnadmin are required")
class ReleaseTests(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory(prefix="convermetry-svn-test-")
        self.addCleanup(self.temporary.cleanup)
        self.base = Path(self.temporary.name).resolve()
        self.project = self.base / "plugin with spaces"
        (self.project / "bin").mkdir(parents=True)
        (self.project / "build").mkdir()
        for name in ("prepare-svn.sh", "prepare_svn.py"):
            shutil.copyfile(ROOT / "bin" / name, self.project / "bin" / name)
        self.staging = self.base / "temporary workspaces"
        self.staging.mkdir()
        self.config = self.base / "svn config"
        self.config.mkdir()
        self.repository = self.base / "repository"
        subprocess.run([SVNADMIN, "create", str(self.repository)], check=True, capture_output=True)
        self.url = self.repository.as_uri()
        seed = self.base / "seed"
        files = self.contents("1.0.2")
        files.update({"obsolete.php": b"remove me", "file-to-directory": b"old file",
                      "directory-to-file/old.php": b"old child", "space @ name.php": b"old",
                      "removed @ file.php": b"remove me too"})
        for branch in ("trunk", "tags/1.0.2"):
            for name, data in files.items():
                target = seed / branch / name
                target.parent.mkdir(parents=True, exist_ok=True)
                target.write_bytes(data)
        (seed / "assets").mkdir()
        (seed / "assets" / "icon.png").write_bytes(b"directory artwork")
        self.svn("import", seed, self.url, "-m", "Initial test release")
        self.calls = self.base / "svn-calls.jsonl"
        shim = self.base / "tools"
        shim.mkdir()
        # Real local SVN underneath a recorder. Never record the password itself.
        (shim / "svn").write_text(
            "#!/usr/bin/env python3\n"
            "import json, os, subprocess, sys\n"
            "from pathlib import Path\n"
            "args = sys.argv[1:]\n"
            "data = sys.stdin.read() if '--password-from-stdin' in args else None\n"
            "entry = {'args': args, 'password_matches': data == " + repr(PASSWORD + "\n") + ", "
            "'inherited_password': 'SVN_PASSWORD' in os.environ}\n"
            "with open(" + repr(str(self.calls)) + ", 'a') as log:\n"
            "    log.write(json.dumps(entry) + '\\n')\n"
            "if args[0] == 'commit' and Path(" + repr(str(self.base / "fail-commit")) + ").exists():\n"
            "    sys.exit(1)\n"
            "sys.exit(subprocess.run([" + repr(SVN) + ", '--config-dir', " + repr(str(self.config)) + "] + args, "
            "input=data, text=True).returncode)\n"
        )
        (shim / "svn").chmod(0o755)
        self.env = os.environ.copy()
        self.env.pop("SVN_PASSWORD", None)
        self.env.pop("SVN_USERNAME", None)
        self.env.update(CVMTRY_SVN_URL=self.url, TMPDIR=str(self.staging),
                        PATH=str(shim) + os.pathsep + self.env["PATH"], PYTHONDONTWRITEBYTECODE="1")

    def svn(self, *args):
        result = subprocess.run([SVN, "--config-dir", str(self.config), *map(str, args)],
                                check=True, text=True, capture_output=True)
        return result.stdout

    def revision(self):
        return self.svn("info", "--show-item", "revision", self.url).strip()

    @staticmethod
    def contents(version):
        return {"convermetry.php": ("<?php\n/**\n * Version: %s\n */\n" % version).encode(),
                "readme.txt": ("=== Convermetry ===\nStable tag: %s\n" % version).encode(),
                "uninstall.php": b"<?php\n", "LICENSE": b"GPL\n", "assets/style.css": b"new CSS"}

    def archive(self, version="1.0.3", extra=None, remove=(), name=None):
        files = self.contents(version)
        files.update(extra or {})
        for path in remove:
            del files[path]
        target = self.project / "build" / (name or "convermetry-%s.zip" % version)
        with zipfile.ZipFile(target, "w") as archive:
            for name, data in files.items():
                archive.writestr("convermetry/" + name, data)
        return target

    def run_script(self, *args, success=True):
        result = subprocess.run(["bash", str(self.project / "bin/prepare-svn.sh"), *map(str, args)],
                                cwd=str(self.base), env=self.env, text=True, capture_output=True)
        self.assertNotIn(PASSWORD, result.stdout + result.stderr)
        self.assertEqual(result.returncode == 0, success, result.stdout + result.stderr)
        return result

    def records(self):
        return [json.loads(line) for line in self.calls.read_text().splitlines()] if self.calls.exists() else []

    def workspace(self):
        return next(self.staging.glob("convermetry-svn-*"))

    def test_preparation_selects_latest_and_syncs_deletions_and_type_changes(self):
        newer_version = self.archive("1.0.4")
        selected = self.archive(extra={"file-to-directory/new.php": b"new child",
                                       "directory-to-file": b"new file", "space @ name.php": b"new"})
        os.utime(newer_version, (100, 100))
        os.utime(selected, (200, 200))
        revision = self.revision()
        result = self.run_script()
        self.assertIn("Version: 1.0.3", result.stdout)
        self.assertEqual(self.revision(), revision)
        wc = self.workspace() / "svn"
        for branch in ("trunk", "tags/1.0.3"):
            self.assertFalse((wc / branch / "obsolete.php").exists())
            self.assertFalse((wc / branch / "removed @ file.php").exists())
            self.assertEqual((wc / branch / "directory-to-file").read_bytes(), b"new file")
            self.assertEqual((wc / branch / "file-to-directory/new.php").read_bytes(), b"new child")
            self.assertEqual((wc / branch / "space @ name.php").read_bytes(), b"new")
        self.assertFalse(any(r["args"][0] == "commit" for r in self.records()))

    def test_publish_uses_literal_file_credentials_and_preserves_old_release_and_artwork(self):
        self.archive()
        credentials = self.project / ".env.svn"
        credentials.write_text("SVN_USERNAME=release-tester\nSVN_PASSWORD='" + PASSWORD + "'\n")
        credentials.chmod(0o600)
        self.run_script("--publish")
        self.assertIn("1.0.3", self.svn("cat", self.url + "/trunk/readme.txt"))
        self.assertIn("1.0.2", self.svn("cat", self.url + "/tags/1.0.2/readme.txt"))
        self.assertIn("1.0.3", self.svn("cat", self.url + "/tags/1.0.3/readme.txt"))
        self.assertEqual(self.svn("cat", self.url + "/assets/icon.png"), "directory artwork")
        self.assertFalse(list(self.staging.glob("convermetry-svn-*")))
        self.assertFalse(list(self.base.rglob("SHOULD_NOT_EXIST")))
        calls = self.records()
        self.assertNotIn(PASSWORD, json.dumps(calls))
        commit = next(r for r in calls if r["args"][0] == "commit")
        self.assertTrue(commit["password_matches"])
        self.assertFalse(commit["inherited_password"])
        self.assertIn("--no-auth-cache", commit["args"])
        self.assertIn("release-tester", commit["args"])

    def test_exported_credentials_override_custom_file(self):
        archive = self.archive()
        credentials = self.base / "private credentials"
        credentials.write_text("SVN_USERNAME=from-file\nSVN_PASSWORD=wrong\n")
        credentials.chmod(0o600)
        self.env.update(SVN_PASSWORD=PASSWORD, SVN_USERNAME="from-environment")
        self.run_script("--publish", "--env-file", credentials, archive)
        commit = next(r for r in self.records() if r["args"][0] == "commit")
        self.assertTrue(commit["password_matches"])
        self.assertIn("from-environment", commit["args"])
        self.assertTrue(all(not r["inherited_password"] for r in self.records()))

    def test_existing_version_and_downgrade_are_rejected(self):
        for version in ("1.0.2", "1.0.1"):
            with self.subTest(version=version):
                revision = self.revision()
                result = self.run_script("--publish", self.archive(version), success=False)
                expected = "already exists" if version == "1.0.2" else "not newer"
                self.assertIn(expected, result.stderr)
                self.assertEqual(self.revision(), revision)
        self.assertFalse(any(r["args"][0] == "commit" for r in self.records()))

    def test_numeric_version_order_allows_1_0_10_after_1_0_2(self):
        self.run_script("--publish", self.archive("1.0.10"))
        self.assertIn("1.0.10", self.svn("cat", self.url + "/trunk/readme.txt"))

    def test_invalid_archive_never_reaches_svn(self):
        cases = [
            ({"readme.txt": b"Stable tag: 2.0.0"}, ()),
            ({"../../escaped": b"bad"}, ()),
            ({".env.svn": b"SVN_PASSWORD=do-not-ship"}, ()),
            ({".svn/metadata": b"bad"}, ()),
            ({"Foo.php": b"1", "foo.php": b"2"}, ()),
            ({}, ("LICENSE",)),
        ]
        for extra, remove in cases:
            with self.subTest(extra=extra, remove=remove):
                self.run_script(self.archive(extra=extra, remove=remove), success=False)
        archive = self.archive()
        with zipfile.ZipFile(archive, "a") as bundle:
            entry = zipfile.ZipInfo("convermetry/link")
            entry.create_system = 3
            entry.external_attr = (stat.S_IFLNK | 0o777) << 16
            bundle.writestr(entry, "../../outside")
        self.run_script(archive, success=False)
        self.assertEqual(self.records(), [])
        self.assertFalse(list(self.base.rglob("escaped")))

    def test_missing_zip_and_equal_timestamps_require_explicit_selection(self):
        self.run_script(success=False)
        a = self.archive("1.0.3")
        b = self.archive("1.0.4")
        for archive in (a, b):
            os.utime(archive, (100, 100))
        self.run_script(success=False)
        self.assertEqual(self.records(), [])
        result = self.run_script(a)
        self.assertIn("Version: 1.0.3", result.stdout)

    def test_credentials_file_must_be_private_and_is_not_executed(self):
        self.archive()
        credentials = self.project / ".env.svn"
        credentials.write_text("SVN_PASSWORD=private-test-value\n")
        credentials.chmod(0o644)
        result = self.run_script(success=False)
        self.assertIn("chmod 600", result.stderr)
        self.assertNotIn("private-test-value", result.stderr)
        credentials.chmod(0o600)
        credentials.write_text("touch SHOULD_NOT_EXIST\n")
        self.run_script(success=False)
        self.assertEqual(self.records(), [])
        self.assertFalse(list(self.base.rglob("SHOULD_NOT_EXIST")))

    def test_failed_publish_retains_workspace_without_committing(self):
        self.archive()
        (self.base / "fail-commit").touch()
        revision = self.revision()
        result = self.run_script("--publish", success=False)
        self.assertIn("Workspace retained:", result.stderr)
        self.assertTrue((self.workspace() / "svn/tags/1.0.3/convermetry.php").is_file())
        self.assertEqual(self.revision(), revision)

    def test_source_and_original_archive_are_preserved_and_temp_inside_source_is_rejected(self):
        archive = self.archive()
        original = archive.read_bytes()
        (self.project / "convermetry.php").write_text("unreleased source edits")
        self.run_script()
        self.assertEqual(archive.read_bytes(), original)
        self.assertEqual((self.project / "convermetry.php").read_text(), "unreleased source edits")
        self.env["TMPDIR"] = str(self.project)
        self.run_script(success=False)
        self.assertFalse(list(self.project.glob("convermetry-svn-*")))

    def test_credentials_are_gitignored_and_excluded_even_when_force_tracked(self):
        for name in (".gitignore", ".distignore", ".env.svn.example"):
            shutil.copyfile(ROOT / name, self.project / name)
        shutil.copyfile(ROOT / "bin/build-zip.sh", self.project / "bin/build-zip.sh")
        for name, data in self.contents("1.0.3").items():
            target = self.project / name
            target.parent.mkdir(parents=True, exist_ok=True)
            target.write_bytes(data)
        with (self.project / "convermetry.php").open("a") as output:
            output.write("define('CVMTRY_VERSION', '1.0.3');\n")
        (self.project / "README.md").write_text("- **Version:** 1.0.3\n")
        (self.project / ".env.svn").write_text("SVN_PASSWORD=must-not-ship\n")
        (self.project / ".env.svn.local").write_text("SVN_PASSWORD=must-not-ship-either\n")
        subprocess.run(["git", "init", "-q", str(self.project)], check=True, capture_output=True)
        ignored = subprocess.run(["git", "check-ignore", ".env.svn"], cwd=self.project, capture_output=True)
        self.assertEqual(ignored.returncode, 0)
        subprocess.run(["git", "add", "."], cwd=self.project, check=True, capture_output=True)
        subprocess.run(["git", "add", "-f", ".env.svn", ".env.svn.local"], cwd=self.project, check=True, capture_output=True)
        subprocess.run(["bash", "bin/build-zip.sh"], cwd=self.project, env=self.env, check=True, capture_output=True)
        with zipfile.ZipFile(self.project / "build/convermetry-1.0.3.zip") as archive:
            self.assertFalse(any(".env" in name for name in archive.namelist()))


if __name__ == "__main__":
    unittest.main(verbosity=2)
