#!/usr/bin/env bash
#
# Regression tests for bin/build-zip.sh path safety.
#
# Origin: STAGE was "$OUT_DIR/convermetry" and the script began with
# `rm -rf "$STAGE"`. Because a Convermetry checkout is itself named
# "convermetry", `bin/build-zip.sh ..` resolved STAGE to the repository and
# deleted it. These tests pin that shut.
#
# Every case runs against a DISPOSABLE FIXTURE repository created under
# mktemp -- a throwaway checkout that is also named "convermetry", so it
# reproduces the exact collision. The real repository is never an argument to
# the script under test and never the CWD it runs from; the only thing copied
# out of it is the script itself.
#
# Usage: tests/Shell/build-zip-safety.sh

set -uo pipefail

SUITE_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd -P)"
REAL_ROOT="$(cd "$SUITE_DIR/../.." && pwd -P)"
SCRIPT_UNDER_TEST="$REAL_ROOT/bin/build-zip.sh"

[[ -f "$SCRIPT_UNDER_TEST" ]] || { echo "missing $SCRIPT_UNDER_TEST" >&2; exit 1; }

PASS=0
FAIL=0
WORKSPACES=()

cleanup_all() {
  for ws in ${WORKSPACES+"${WORKSPACES[@]}"}; do
    # Guard the guard: only ever remove our own mktemp workspaces.
    case "$ws" in
      "${TMPDIR:-/tmp}"/cvm-buildtest.*|/tmp/cvm-buildtest.*|/private/tmp/cvm-buildtest.*|/var/folders/*/cvm-buildtest.*)
        [[ -d "$ws" ]] && rm -rf "$ws"
        ;;
    esac
  done
}
trap cleanup_all EXIT

ok()   { PASS=$((PASS + 1)); printf '  ok   %s\n' "$1"; }
bad()  { FAIL=$((FAIL + 1)); printf '  FAIL %s\n     %s\n' "$1" "${2:-}"; }

# Builds a disposable checkout named "convermetry" that mirrors just enough of
# the real tree for the script to run: the version surfaces it cross-checks,
# a .distignore, and a git index to enumerate.
make_fixture() {
  local ws repo
  ws="$(mktemp -d "${TMPDIR:-/tmp}/cvm-buildtest.XXXXXXXX")"
  WORKSPACES+=("$ws")
  repo="$ws/convermetry"

  mkdir -p "$repo/bin" "$repo/src/Webhook" "$repo/tests" "$repo/assets"
  cp "$SCRIPT_UNDER_TEST" "$repo/bin/build-zip.sh"
  chmod +x "$repo/bin/build-zip.sh"

  cat > "$repo/convermetry.php" <<'PHP'
<?php
/**
 * Plugin Name: Convermetry
 * Version: 9.9.9
 */
define('CVM_VERSION', '9.9.9');
PHP

  cat > "$repo/README.md" <<'MD'
# Convermetry
- **Version:** 9.9.9
Example: "plugin_version": "9.9.9"
MD

  cat > "$repo/src/Webhook/PayloadBuilder.php" <<'PHP'
<?php
/** "plugin_version": "9.9.9" */
PHP

  printf '%s\n' '.git' '.distignore' 'build' 'tests' 'bin' 'composer.json' > "$repo/.distignore"
  echo 'body{}' > "$repo/assets/style.css"
  echo 'test' > "$repo/tests/SomeTest.php"
  echo '{}' > "$repo/composer.json"

  git -C "$repo" init -q 2>/dev/null
  git -C "$repo" add -A 2>/dev/null
  git -C "$repo" -c user.email=t@t.test -c user.name=t commit -qm init 2>/dev/null

  printf '%s' "$repo"
}

# A fixture repo is "intact" when its tracked content and git metadata survive.
repo_intact() {
  local repo="$1"
  [[ -d "$repo" ]] \
    && [[ -f "$repo/convermetry.php" ]] \
    && [[ -f "$repo/README.md" ]] \
    && [[ -d "$repo/.git" ]] \
    && [[ -f "$repo/src/Webhook/PayloadBuilder.php" ]]
}

# Runs the script from inside the fixture repo, capturing status and output.
run_build() {
  local repo="$1"; shift
  local cwd="${RUN_CWD:-$repo}"
  ( cd "$cwd" && "$repo/bin/build-zip.sh" "$@" ) >"$OUT_FILE" 2>&1
}

# --- rejection cases -------------------------------------------------------
# Each must exit non-zero AND leave the fixture repository fully intact.

assert_rejected() {
  local name="$1" repo="$2"; shift 2
  local status
  run_build "$repo" "$@"
  status=$?

  if [[ $status -eq 0 ]]; then
    bad "$name" "expected a non-zero exit, got 0"
  elif ! repo_intact "$repo"; then
    bad "$name" "THE FIXTURE REPOSITORY WAS DAMAGED"
  elif ! grep -qi 'error:' "$OUT_FILE"; then
    bad "$name" "rejected without an error message: $(head -3 "$OUT_FILE")"
  else
    ok "$name -- rejected, repo intact"
  fi
}

assert_accepted() {
  local name="$1" repo="$2" expect_zip="$3"; shift 3
  local status
  run_build "$repo" "$@"
  status=$?

  if [[ $status -ne 0 ]]; then
    bad "$name" "expected success, got $status: $(head -5 "$OUT_FILE")"
  elif ! repo_intact "$repo"; then
    bad "$name" "THE FIXTURE REPOSITORY WAS DAMAGED"
  elif [[ ! -f "$expect_zip" ]]; then
    bad "$name" "no ZIP at $expect_zip"
  else
    ok "$name -- built, repo intact"
  fi
}

OUT_FILE="$(mktemp "${TMPDIR:-/tmp}/cvm-buildtest-out.XXXXXX")"
trap 'rm -f "$OUT_FILE"; cleanup_all' EXIT

echo "build-zip.sh path-safety regression suite"
echo

echo "rejection cases (must not delete the checkout):"

# The exact historical repo-deleting invocation: OUT_DIR=.., STAGE=../convermetry.
repo="$(make_fixture)"
assert_rejected "parent directory '..'" "$repo" ".."

repo="$(make_fixture)"
assert_rejected "current directory '.' at repo root" "$repo" "."

repo="$(make_fixture)"
assert_rejected "explicit repository root" "$repo" "$repo"

repo="$(make_fixture)"
assert_rejected "filesystem root '/'" "$repo" "/"

repo="$(make_fixture)"
assert_rejected "empty string argument" "$repo" ""

# An unquoted path containing spaces arrives as several arguments, with $1 a
# truncated prefix pointing somewhere the operator never named.
repo="$(make_fixture)"
assert_rejected "unquoted path split into several arguments" "$repo" "/tmp/some" "dir"

# A symlink whose real target is the repository must be resolved before the
# guards read it, not after.
repo="$(make_fixture)"
ln -s "$repo" "$(dirname "$repo")/link-to-repo"
assert_rejected "symlink resolving to the repo root" "$repo" "$(dirname "$repo")/link-to-repo"

# A symlink pointing above the repo is an ancestor once resolved.
repo="$(make_fixture)"
ln -s "$(dirname "$repo")" "$(dirname "$repo")/link-to-parent"
assert_rejected "symlink resolving to an ancestor" "$repo" "$(dirname "$repo")/link-to-parent"

# Relative traversal that lands on the repo root from a subdirectory.
repo="$(make_fixture)"
RUN_CWD="$repo/src" assert_rejected "relative '..' from a subdirectory" "$repo" ".."

echo
echo "acceptance cases (must build without touching the checkout):"

# The default: build/ inside the tree is a descendant, which stays allowed.
repo="$(make_fixture)"
assert_accepted "default output directory" "$repo" "$repo/build/convermetry-9.9.9.zip"

repo="$(make_fixture)"
ext="$(dirname "$repo")/external-out"
assert_accepted "external output directory" "$repo" "$ext/convermetry-9.9.9.zip" "$ext"

repo="$(make_fixture)"
spaced="$(dirname "$repo")/out dir with spaces"
assert_accepted "output path containing spaces" "$repo" "$spaced/convermetry-9.9.9.zip" "$spaced"

repo="$(make_fixture)"
nested="$(dirname "$repo")/does/not/exist/yet"
assert_accepted "missing nested output directory" "$repo" "$nested/convermetry-9.9.9.zip" "$nested"

repo="$(make_fixture)"
target="$(dirname "$repo")/real-out"
mkdir -p "$target"
ln -s "$target" "$(dirname "$repo")/link-to-out"
assert_accepted "symlink to a safe external directory" "$repo" "$target/convermetry-9.9.9.zip" \
  "$(dirname "$repo")/link-to-out"

# Relative path resolved against the caller's CWD, not the repository root.
repo="$(make_fixture)"
mkdir -p "$(dirname "$repo")/rel-out"
RUN_CWD="$(dirname "$repo")" assert_accepted "relative path from outside the repo" "$repo" \
  "$(dirname "$repo")/rel-out/convermetry-9.9.9.zip" "rel-out"

echo
echo "archive hygiene:"

repo="$(make_fixture)"
out="$(dirname "$repo")/hygiene"
if ( cd "$repo" && ./bin/build-zip.sh "$out" ) >"$OUT_FILE" 2>&1; then
  listing="$(unzip -Z1 "$out/convermetry-9.9.9.zip")"
  leaked=""
  for forbidden in ".git/" "tests/" "bin/" "composer.json" ".distignore" "build/"; do
    if printf '%s\n' "$listing" | grep -q "^convermetry/$forbidden"; then
      leaked="$leaked $forbidden"
    fi
  done
  if [[ -n "$leaked" ]]; then
    bad "excluded paths stay out of the archive" "leaked:$leaked"
  elif ! printf '%s\n' "$listing" | grep -q '^convermetry/convermetry.php$'; then
    bad "excluded paths stay out of the archive" "entry file missing from archive"
  elif ! printf '%s\n' "$listing" | grep -q '^convermetry/assets/style.css$'; then
    bad "excluded paths stay out of the archive" "runtime asset missing from archive"
  else
    ok "archive contains runtime files and no development artifacts"
  fi
else
  bad "archive hygiene build" "$(head -5 "$OUT_FILE")"
fi

# Staging must not survive, and must never have been inside the tree.
repo="$(make_fixture)"
out="$(dirname "$repo")/stage-check"
( cd "$repo" && ./bin/build-zip.sh "$out" ) >"$OUT_FILE" 2>&1
if [[ -e "$repo/convermetry" ]]; then
  bad "staging never lands inside the checkout" "found $repo/convermetry"
elif [[ -e "$out/convermetry" ]]; then
  bad "staging is cleaned up" "leftover staging directory in the output dir"
else
  ok "staging is external and cleaned up"
fi

echo
echo "version-drift guard:"

repo="$(make_fixture)"
# Drift one surface; the build must refuse rather than ship a mislabelled ZIP.
sed -i.bak 's/- \*\*Version:\*\* 9.9.9/- **Version:** 1.0.0/' "$repo/README.md"
rm -f "$repo/README.md.bak"
out="$(dirname "$repo")/drift"
if ( cd "$repo" && ./bin/build-zip.sh "$out" ) >"$OUT_FILE" 2>&1; then
  bad "mismatched version surfaces are rejected" "build succeeded despite drift"
elif ! grep -q 'README.md version line' "$OUT_FILE"; then
  bad "mismatched version surfaces are rejected" "wrong error: $(head -3 "$OUT_FILE")"
else
  ok "mismatched version surfaces are rejected"
fi

echo
echo "----------------------------------------"
printf 'passed: %d   failed: %d\n' "$PASS" "$FAIL"
[[ $FAIL -eq 0 ]] || exit 1
