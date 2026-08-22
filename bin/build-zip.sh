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
