#!/usr/bin/env bash
#
# Builds the distributable Convermetry plugin ZIP.
#
# Zipping the working directory ships .git, tests, and Composer dev
# dependencies. This stages only the runtime files listed by git, minus
# everything in .distignore, and verifies the result before writing the ZIP.
#
# Staging is a private mktemp directory removed by an EXIT trap. It is
# deliberately NOT derived from the output directory. It used to be
# "$OUT_DIR/$PLUGIN_SLUG", so `bin/build-zip.sh ..` resolved staging to the
# checkout itself -- the working copy is named "convermetry" -- and the
# `rm -rf "$STAGE"` that clears stale staging deleted the repository.
#
# Usage: bin/build-zip.sh [output-dir]

set -euo pipefail

PLUGIN_SLUG="convermetry"

# Captured before any `cd`, so a relative output directory resolves against the
# directory the operator actually typed it in rather than the repository root.
INVOCATION_DIR="$PWD"
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd -P)"

die() {
  echo "error: $*" >&2
  exit 1
}

# Canonicalises to an absolute, symlink-free path. The directory is created
# first so `pwd -P` can resolve it; a symlinked output directory therefore
# collapses to its real target before any safety check reads it, which is what
# stops a symlink from smuggling a forbidden path past the guards below.
resolve_dir() {
  local raw="$1" abs
  [[ -n "$raw" ]] || die "output directory is empty"
  abs="$raw"
  [[ "$abs" = /* ]] || abs="$INVOCATION_DIR/$raw"
  mkdir -p "$abs" 2>/dev/null || die "could not create output directory: $raw"
  ( cd "$abs" && pwd -P )
}

# Rejects any path that must never be created inside, or deleted along with,
# the source tree. Every `rm -rf` in this script runs against a path that has
# been through here first.
assert_safe_target() {
  local target="$1" label="$2"

  [[ -n "$target" ]]      || die "$label is empty"
  [[ "$target" = /* ]]    || die "$label is not absolute: '$target'"
  [[ "$target" != "/" ]]  || die "$label is the filesystem root"
  [[ "$target" != "$ROOT" ]] \
    || die "$label is the repository root ($ROOT)"

  # An ancestor of the checkout: deleting or building into it would take the
  # repository with it. `bin/build-zip.sh ..` lands here.
  case "$ROOT/" in
    "$target"/*)
      die "$label is an ancestor of the repository: '$target' contains $ROOT"
      ;;
  esac
}

# Staging additionally must not live inside the source tree at all, so a
# cleanup can never touch tracked files.
assert_outside_repo() {
  local target="$1" label="$2"
  case "$target/" in
    "$ROOT"/*) die "$label must not be inside the repository: '$target'" ;;
  esac
}

# `${1:-default}` would swallow an explicitly empty argument and silently build
# into the default directory, so branch on the argument COUNT instead. More than
# one argument almost always means an unquoted path containing spaces, where
# $1 is a truncated prefix -- refuse rather than build somewhere unintended.
if [[ $# -gt 1 ]]; then
  die "expected at most one output directory (got $#); quote paths containing spaces"
fi

if [[ $# -eq 1 ]]; then
  OUT_DIR_RAW="$1"
else
  OUT_DIR_RAW="$ROOT/build"
fi

OUT_DIR="$(resolve_dir "$OUT_DIR_RAW")"
assert_safe_target "$OUT_DIR" "output directory"

cd "$ROOT"

VERSION="$(grep -m1 '^ \* Version:' convermetry.php | awk '{print $3}')"
if [[ -z "$VERSION" ]]; then
  die "could not read Version from convermetry.php"
fi

# The plugin header must stay a literal string for WordPress to parse it, so the
# version cannot be interpolated from one source at runtime. Enforce agreement at
# build time instead: every place that repeats the version has to match the
# header, and the ZIP is named from it. These drifted apart once already (the
# header said 0.2.0 while README and the PayloadBuilder docblock still said
# 0.1.0), which is exactly what this catches.
version_mismatch=0

check_version() {
  local label="$1" found="$2"
  if [[ "$found" != "$VERSION" ]]; then
    echo "error: $label is '$found', expected '$VERSION' (from the plugin header)" >&2
    version_mismatch=1
  fi
}

check_version "CVM_VERSION in convermetry.php" \
  "$(grep -m1 "define('CVM_VERSION'" convermetry.php | sed -E "s/.*'([0-9][^']*)'.*/\1/")"

check_version "README.md version line" \
  "$(grep -m1 '^- \*\*Version:\*\*' README.md | awk '{print $3}')"

# Payload examples in the README and the PayloadBuilder docblock hardcode the
# version; the live payload builds it from CVM_VERSION, so only the prose copies
# can rot. Only scan the copies that exist, so a minimal tree (the shell
# regression fixtures) does not trip grep's missing-file error.
VERSION_EXAMPLE_FILES=()
for candidate in README.md src/Webhook/PayloadBuilder.php; do
  [[ -f "$candidate" ]] && VERSION_EXAMPLE_FILES+=("$candidate")
done

if [[ ${#VERSION_EXAMPLE_FILES[@]} -gt 0 ]]; then
  while IFS= read -r found; do
    [[ -n "$found" ]] || continue
    check_version "a \"plugin_version\" example" "$found"
  done < <(grep -rhoE '"plugin_version": "[^"]+"' "${VERSION_EXAMPLE_FILES[@]}" \
           | sed -E 's/.*: "([^"]+)"/\1/')
fi

if [[ $version_mismatch -ne 0 ]]; then
  die "version strings disagree; reconcile them before building"
fi

# Unique per run, so two concurrent builds cannot share or clobber staging.
STAGE_ROOT="$(mktemp -d "${TMPDIR:-/tmp}/convermetry-build.XXXXXXXX")" \
  || die "could not create staging directory"
STAGE_ROOT="$(cd "$STAGE_ROOT" && pwd -P)"

assert_safe_target  "$STAGE_ROOT" "staging directory"
assert_outside_repo "$STAGE_ROOT" "staging directory"

cleanup() {
  # Re-validated at deletion time: the trap can fire from anywhere, including a
  # failure path that ran before STAGE_ROOT was fully established.
  if [[ -n "${STAGE_ROOT:-}" && -d "$STAGE_ROOT" ]]; then
    assert_safe_target  "$STAGE_ROOT" "staging directory"
    assert_outside_repo "$STAGE_ROOT" "staging directory"
    rm -rf "$STAGE_ROOT"
  fi
}
trap cleanup EXIT

STAGE="$STAGE_ROOT/$PLUGIN_SLUG"
mkdir -p "$STAGE"

# Read the ignore patterns once rather than re-reading the file per tracked path.
IGNORES=()
while IFS= read -r pattern || [[ -n "$pattern" ]]; do
  [[ -z "$pattern" || "$pattern" == \#* ]] && continue
  IGNORES+=("$pattern")
done < .distignore

# Copy tracked files only, so untracked scratch files can never leak in.
while IFS= read -r -d '' file; do
  skip=0
  for pattern in ${IGNORES+"${IGNORES[@]}"}; do
    if [[ "$file" == "$pattern" || "$file" == "$pattern"/* ]]; then
      skip=1
      break
    fi
  done
  [[ $skip -eq 1 ]] && continue
  mkdir -p "$STAGE/$(dirname "$file")"
  cp "$file" "$STAGE/$file"
done < <(git ls-files -z)

# Fail loudly rather than shipping a contaminated archive.
for forbidden in .git .github tests vendor node_modules build phpstan bin \
                 composer.json composer.lock phpunit.xml phpunit.integration.xml \
                 phpstan.neon .distignore; do
  if [[ -e "$STAGE/$forbidden" ]]; then
    die "development artifact '$forbidden' reached the staged plugin"
  fi
done

if [[ ! -f "$STAGE/convermetry.php" ]]; then
  die "staged plugin is missing its entry file"
fi

ZIP="$OUT_DIR/$PLUGIN_SLUG-$VERSION.zip"
rm -f "$ZIP"
( cd "$STAGE_ROOT" && zip -rq "$ZIP" "$PLUGIN_SLUG" )

echo "built $ZIP"
unzip -l "$ZIP" | tail -n 1
