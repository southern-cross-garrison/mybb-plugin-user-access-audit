#!/usr/bin/env bash
# Shared configuration for the dev-environment scripts.
set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$REPO_ROOT"

# shellcheck disable=SC1091
[ -f "$REPO_ROOT/.env" ] && set -a && . "$REPO_ROOT/.env" && set +a

# The MyBB release the suite runs against, kept in step with the sibling plugins' suites.
MYBB_VERSION="${MYBB_VERSION:-1841}"
MYBB_SHA256="${MYBB_SHA256:-48cc76e9e1146ea052756575991f43f51a634f125eea06d05ce9041b03eedeaa}"
MYBB_URL="https://resources.mybb.com/downloads/mybb_${MYBB_VERSION}.zip"

WEB_PORT="${WEB_PORT:-8100}"
DB_PORT="${DB_PORT:-3327}"
BASE_URL="${BASE_URL:-http://localhost:${WEB_PORT}}"

FORUM_DIR="$REPO_ROOT/test-forum"
DEVENV_DIR="$REPO_ROOT/.devenv"
CACHE_DIR="$DEVENV_DIR/cache"
SNAPSHOT_FILE="$DEVENV_DIR/snapshot.sql"

DB_NAME="mybb"
DB_USER="mybb"
DB_PASS="mybbpassword"
DB_ROOT_PASS="rootpassword"
TABLE_PREFIX="mybb_"

# Fixture credentials used by both provisioning and the e2e suite.
ADMIN_USER="admin"
ADMIN_PASS="adminpass123"
ADMIN_EMAIL="admin@example.test"

dc() { docker compose "$@"; }

log() { printf '\033[1;34m==>\033[0m %s\n' "$*"; }
die() { printf '\033[1;31mERROR:\033[0m %s\n' "$*" >&2; exit 1; }
