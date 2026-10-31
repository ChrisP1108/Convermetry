#!/usr/bin/env python3
"""Implementation for prepare-svn.sh; uses only the Python 3 standard library."""

import argparse
import itertools
import os
from pathlib import Path, PurePosixPath
import re
import shlex
import shutil
import stat
import subprocess
import sys
import tempfile
import urllib.parse
import xml.etree.ElementTree as ET
import zipfile


ROOT = Path(__file__).resolve().parent.parent
REPOSITORY = "https://plugins.svn.wordpress.org/convermetry"
VERSION = re.compile(r"(?:0|[1-9][0-9]*)(?:\.(?:0|[1-9][0-9]*))*\Z")


class ReleaseError(Exception):
    pass


def fail(message):
    raise ReleaseError(message)


def version_key(value):
    if not VERSION.fullmatch(value):
        fail("release versions must contain numbers separated by periods")
    return tuple(int(part) for part in value.split("."))


def not_newer(candidate, previous):
    pairs = list(itertools.zip_longest(version_key(candidate), version_key(previous), fillvalue=0))
    return tuple(a for a, _ in pairs) <= tuple(b for _, b in pairs)


def header(text, field):
    match = re.search(r"^\s*(?:\*\s*)?" + re.escape(field) + r":\s*([^\r\n]+)", text, re.M | re.I)
    return match.group(1).strip() if match else ""


def select_zip(explicit):
    if explicit:
        archive = Path(explicit).expanduser().resolve()
        if not archive.is_file():
            fail("ZIP file does not exist: " + str(archive))
        return archive
    candidates = sorted(
        (p for p in (ROOT / "build").glob("convermetry-*.zip") if p.is_file()),
        key=lambda p: p.stat().st_mtime_ns,
        reverse=True,
    )
    if not candidates:
        fail("no convermetry-*.zip found in build/; build the release first")
    if len(candidates) > 1 and candidates[0].stat().st_mtime_ns == candidates[1].stat().st_mtime_ns:
        fail("multiple builds share the newest modification time; pass a ZIP filename explicitly")
    return candidates[0].resolve()


def credentials(env_file, required=False):
    """Deliberately not a shell/dotenv evaluator: $, backticks and # are literal."""
    values = {}
    if env_file.exists():
        if not env_file.is_file():
            fail("credentials path must be a regular file")
        if stat.S_IMODE(env_file.stat().st_mode) & 0o077:
            fail("credentials file must be private; run: chmod 600 " + shlex.quote(str(env_file)))
        for number, line in enumerate(env_file.read_text(encoding="utf-8").splitlines(), 1):
            line = line.strip()
            if not line or line.startswith("#"):
                continue
            match = re.fullmatch(r"(?:export\s+)?(SVN_USERNAME|SVN_PASSWORD)\s*=(.*)", line)
            if not match:
                fail("invalid credentials entry on line %d (expected SVN_USERNAME or SVN_PASSWORD)" % number)
            key, value = match.groups()
            value = value.strip()
            if value.startswith(("'", '"')):
                if len(value) < 2 or value[-1] != value[0]:
                    fail("unclosed quote in credentials file on line %d" % number)
                value = value[1:-1]
            if key in values:
                fail("duplicate credentials entry on line %d" % number)
            values[key] = value
    elif required:
        fail("credentials file does not exist: " + str(env_file))
    for key in ("SVN_USERNAME", "SVN_PASSWORD"):
        if key in os.environ:
            values[key] = os.environ[key]
    username = values.get("SVN_USERNAME", "cpaschall1981")
    password = values.get("SVN_PASSWORD", "")
    if not username or any(c.isspace() for c in username):
        fail("SVN_USERNAME must be a nonempty WordPress.org username")
    if any(c in username + password for c in "\r\n\0"):
        fail("credentials cannot contain newlines or NUL characters")
    return username, password


def extract_release(archive, destination):
    """Validate every member before extracting into an empty, private directory."""
    with zipfile.ZipFile(archive) as bundle:
        seen = set()
        paths = {}
        for member in bundle.infolist():
            raw = member.filename.rstrip("/")
            parts = raw.split("/")
            if (not raw or parts[0] != "convermetry" or "\\" in raw
                    or any(p in ("", ".", "..") for p in parts)
                    or any(ord(c) < 32 for c in raw)):
                fail("ZIP contains an unsafe path or an unexpected top-level folder")
            if any(p.lower() in (".svn", ".git") or p.lower().startswith(".env") for p in parts):
                fail("ZIP contains repository metadata or an environment file")
            mode = stat.S_IFMT(member.external_attr >> 16)
            if mode not in (0, stat.S_IFREG, stat.S_IFDIR):
                fail("ZIP contains a symlink or another unsupported file type")
            if raw in seen:
                fail("ZIP contains duplicate paths")
            seen.add(raw)
            for index in range(1, len(parts) + 1):
                path = "/".join(parts[:index])
                kind = "dir" if index < len(parts) or member.is_dir() else "file"
                folded = path.casefold()
                if folded in paths and paths[folded] != (path, kind):
                    fail("ZIP contains conflicting file or directory names")
                paths[folded] = (path, kind)
        for name in ("convermetry.php", "readme.txt", "LICENSE", "uninstall.php"):
            if paths.get(("convermetry/" + name).casefold()) != ("convermetry/" + name, "file"):
                fail("ZIP is missing required file: " + name)
        version = header(bundle.read("convermetry/convermetry.php").decode("utf-8"), "Version")
        stable = header(bundle.read("convermetry/readme.txt").decode("utf-8"), "Stable tag")
        version_key(version)
        if stable != version:
            fail("ZIP plugin Version and readme Stable tag do not match")
        destination.mkdir()
        for member in bundle.infolist():
            target = destination.joinpath(*PurePosixPath(member.filename).parts)
            if member.is_dir():
                target.mkdir(parents=True, exist_ok=True)
            else:
                target.parent.mkdir(parents=True, exist_ok=True)
                with bundle.open(member) as source, target.open("wb") as output:
                    shutil.copyfileobj(source, output)
                target.chmod(0o755 if (member.external_attr >> 16) & 0o111 else 0o644)
    return version


class Release:
    def __init__(self, workspace, repository, username, password):
        self.workspace = workspace
        self.working_copy = workspace / "svn"
        self.repository = repository
        self.username = username
        self.password = password

    def svn(self, *arguments, capture=True, authenticate=False):
        command = ["svn", str(arguments[0])]
        child_env = os.environ.copy()
        # Exported credentials are consumed here, never inherited by SVN.
        child_env.pop("SVN_PASSWORD", None)
        child_env.pop("SVN_USERNAME", None)
        options = {"text": True, "env": child_env}
        if self.working_copy.is_dir():
            options["cwd"] = str(self.working_copy)
        if authenticate:
            command += ["--username", self.username]
            if self.password:
                command += ["--password-from-stdin", "--non-interactive", "--no-auth-cache"]
                options["input"] = self.password + "\n"
        else:
            command += ["--non-interactive", "--no-auth-cache"]
        # Global flags must precede a subcommand's optional '--' path separator.
        command.extend(map(str, arguments[1:]))
        if capture:
            options.update(stdout=subprocess.PIPE, stderr=subprocess.PIPE)
        result = subprocess.run(command, **options)
        if result.returncode:
            # Never embed a subprocess command (or its input) in an exception.
            details = (result.stderr or "").strip()
            if self.password:
                details = details.replace(self.password, "[redacted]")
            fail("svn %s failed%s" % (arguments[0], (": " + details) if details else ""))
        return result.stdout or ""

    def checkout(self, version):
        info = ET.fromstring(self.svn("info", "--xml", self.repository))
        revision = info.find("entry").attrib["revision"]
        tags = ET.fromstring(self.svn("list", "--xml", "-r", revision, self.repository + "/tags@" + revision))
        for entry in tags.findall(".//entry"):
            name = entry.findtext("name", "")
            if name == version:
                fail("release tag %s already exists; build a new version" % version)
            if VERSION.fullmatch(name) and not_newer(version, name):
                fail("selected version %s is not newer than published tag %s" % (version, name))
        print("Checking out trunk at SVN revision %s..." % revision, flush=True)
        self.svn("checkout", "--depth", "immediates", "--ignore-externals", "-r", revision,
                 self.repository + "@" + revision, self.working_copy)
        if not (self.working_copy / "trunk").is_dir() or not (self.working_copy / "tags").is_dir():
            fail("SVN repository must contain trunk/ and tags/ directories")
        self.svn("update", "--set-depth", "infinity", "--ignore-externals", "-r", revision, "trunk")
        for filename, field in (("convermetry.php", "Version"), ("readme.txt", "Stable tag")):
            current = self.working_copy / "trunk" / filename
            if current.is_file():
                previous = header(current.read_text(encoding="utf-8"), field)
                if VERSION.fullmatch(previous) and not_newer(version, previous):
                    fail("selected version %s is not newer than trunk's %s" % (version, previous))

    def sync(self, source, target):
        # Deletions go through SVN before copying. This also handles a path
        # changing from file to directory (or vice versa) without stale files.
        source_entries = {item.name: item for item in source.iterdir()}
        for old in sorted(target.iterdir()):
            if old.name == ".svn":
                continue
            new = source_entries.get(old.name)
            if new is None or old.is_symlink() or old.is_dir() != new.is_dir():
                relative = "./" + old.relative_to(self.working_copy).as_posix() + "@"
                self.svn("delete", "--force", "--", relative)
        for new in sorted(source.iterdir()):
            old = target / new.name
            if new.is_dir():
                old.mkdir(exist_ok=True)
                self.sync(new, old)
            else:
                shutil.copyfile(new, old)
                old.chmod(stat.S_IMODE(new.stat().st_mode))

    def prepare(self, source, version):
        self.checkout(version)
        self.sync(source, self.working_copy / "trunk")
        self.svn("add", "--force", "--no-ignore", "trunk")
        status = ET.fromstring(self.svn("status", "--xml", "--no-ignore", "trunk"))
        for state in status.findall(".//wc-status"):
            if state.attrib["item"] in ("conflicted", "obstructed", "missing", "incomplete", "unversioned", "ignored") or state.attrib.get("tree-conflicted") == "true":
                fail("unexpected working-copy state; inspect the retained workspace")
        self.svn("copy", "trunk", "tags/" + version)
        print("\nChanges to trunk:", flush=True)
        print(self.svn("status", "trunk"), end="", flush=True)
        print("New release tag: tags/" + version, flush=True)


def main():
    parser = argparse.ArgumentParser(prog="prepare-svn.sh", description="Prepare the newest Convermetry build ZIP for WordPress.org SVN.")
    parser.add_argument("zip", nargs="?", help="explicit ZIP; default: newest build/convermetry-*.zip by modification time")
    parser.add_argument("--publish", action="store_true", help="also commit trunk and the new tag to WordPress.org")
    parser.add_argument("--env-file", type=Path, help="credentials file; default: .env.svn in the plugin directory")
    args = parser.parse_args()
    if not shutil.which("svn"):
        fail("svn is required; install it with brew install subversion")
    archive = select_zip(args.zip)
    env_file = (args.env_file or ROOT / ".env.svn").expanduser().resolve()
    username, password = credentials(env_file, required=args.env_file is not None)
    # Only local repositories may override the production URL, for offline tests.
    repository = os.environ.get("CVMTRY_SVN_URL", REPOSITORY).rstrip("/")
    if repository != REPOSITORY and urllib.parse.urlsplit(repository).scheme != "file":
        fail("CVMTRY_SVN_URL may only override the repository with a local file:// URL")
    temporary_root = Path(tempfile.gettempdir()).resolve()
    if temporary_root == ROOT or ROOT in temporary_root.parents:
        fail("temporary directory must be outside the plugin source directory; check TMPDIR")
    os.umask(0o077)
    workspace = Path(tempfile.mkdtemp(prefix="convermetry-svn-", dir=str(temporary_root)))
    try:
        snapshot = workspace / "build.zip"
        shutil.copyfile(archive, snapshot)
        version = extract_release(snapshot, workspace / "extracted")
        print("Build: %s\nVersion: %s\nRepository: %s" % (archive, version, repository), flush=True)
        release = Release(workspace, repository, username, password)
        release.prepare(workspace / "extracted" / "convermetry", version)
        if args.publish:
            print("\nPublishing Convermetry %s..." % version, flush=True)
            release.svn("commit", "trunk", "tags/" + version, "-m", "Release Convermetry " + version,
                        capture=False, authenticate=True)
            print("Published Convermetry %s. Complete any WordPress.org release-confirmation email." % version, flush=True)
            # This path was created above, never supplied by the caller.
            try:
                shutil.rmtree(workspace)
            except OSError:
                print("Release committed; temporary workspace could not be removed: " + str(workspace), file=sys.stderr)
        else:
            print("\nPrepared only; nothing was published. Workspace: " + str(workspace))
            print("Inspect the prepared changes:")
            print("  cd " + shlex.quote(str(release.working_copy)))
            print("  svn diff trunk")
            print("Publish this prepared copy using SVN's saved credentials or password prompt:")
            print("  " + shlex.join(["svn", "commit", "trunk", "tags/" + version, "--username", username,
                                     "-m", "Release Convermetry " + version]))
            command = ["bash", str(ROOT / "bin" / "prepare-svn.sh"), "--publish"]
            if args.env_file:
                command += ["--env-file", str(env_file)]
            command.append(str(archive))
            print("Or prepare afresh and publish this ZIP using the credentials file:")
            print("  " + shlex.join(command))
            print("The temporary workspace is retained for review; remove it when finished.")
    except BaseException:
        print("Workspace retained: " + str(workspace), file=sys.stderr)
        raise


if __name__ == "__main__":
    try:
        main()
    except (ReleaseError, OSError, UnicodeError, zipfile.BadZipFile, ET.ParseError) as error:
        print("error: " + str(error), file=sys.stderr)
        sys.exit(1)
    except KeyboardInterrupt:
        print("\nInterrupted; inspect SVN before retrying an interrupted commit.", file=sys.stderr)
        sys.exit(130)
