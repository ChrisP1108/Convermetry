#!/usr/bin/env bash
#
# Builds the distributable Convermetry plugin ZIP.
#
# Zipping the working directory ships .git, tests, and Composer dev
# dependencies. This stages only the runtime files listed by git, minus
# everything in .distignore, and verifies the result before writing the ZIP.
#
# Usage: bin/build-zip.sh [output-dir]

set -euo pipefail

PLUGIN_SLUG="convermetry"
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
OUT_DIR="${1:-$ROOT/build}"
STAGE="$OUT_DIR/$PLUGIN_SLUG"

cd "$ROOT"

VERSION="$(grep -m1 '^ \* Version:' convermetry.php | awk '{print $3}')"
if [[ -z "$VERSION" ]]; then
  echo "error: could not read Version from convermetry.php" >&2
  exit 1
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
# can rot.
while IFS= read -r found; do
  check_version "a \"plugin_version\" example" "$found"
done < <(grep -rhoE '"plugin_version": "[^"]+"' README.md src/Webhook/PayloadBuilder.php \
         | sed -E 's/.*: "([^"]+)"/\1/')

if [[ $version_mismatch -ne 0 ]]; then
  echo "error: version strings disagree; reconcile them before building" >&2
  exit 1
fi

rm -rf "$STAGE"
mkdir -p "$STAGE"

# Copy tracked files only, so untracked scratch files can never leak in.
EXCLUDES=()
while IFS= read -r line; do
  [[ -z "$line" || "$line" == \#* ]] && continue
  EXCLUDES+=("--exclude=$line")
done < .distignore

git ls-files -z | while IFS= read -r -d '' file; do
  skip=0
  while IFS= read -r pattern; do
    [[ -z "$pattern" || "$pattern" == \#* ]] && continue
    [[ "$file" == "$pattern" || "$file" == "$pattern"/* ]] && { skip=1; break; }
  done < .distignore
  [[ $skip -eq 1 ]] && continue
  mkdir -p "$STAGE/$(dirname "$file")"
  cp "$file" "$STAGE/$file"
done

# Fail loudly rather than shipping a contaminated archive.
for forbidden in .git tests vendor composer.json phpunit.xml; do
  if [[ -e "$STAGE/$forbidden" ]]; then
    echo "error: development artifact '$forbidden' reached the staged plugin" >&2
    exit 1
  fi
done

if [[ ! -f "$STAGE/convermetry.php" ]]; then
  echo "error: staged plugin is missing its entry file" >&2
  exit 1
fi

ZIP="$OUT_DIR/$PLUGIN_SLUG-$VERSION.zip"
rm -f "$ZIP"
( cd "$OUT_DIR" && zip -rq "$(basename "$ZIP")" "$PLUGIN_SLUG" )
rm -rf "$STAGE"

echo "built $ZIP"
unzip -l "$ZIP" | tail -n 1
