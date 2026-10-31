#!/usr/bin/env bash
# Prepare a build ZIP for WordPress.org; --publish also commits the release.
# Python's standard library handles ZIP paths, SVN XML and literal credentials.
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd -P)"
command -v python3 >/dev/null 2>&1 || {
  echo "error: python3 is required to prepare an SVN release" >&2
  exit 1
}
exec python3 "$SCRIPT_DIR/prepare_svn.py" "$@"
