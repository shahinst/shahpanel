#!/usr/bin/env bash
#
# Updater for ShahPanel — pulls the latest code from GitHub.
#
#   sudo GITHUB_TOKEN=ghp_xxx bash update.sh
#   sudo bash update.sh                       # asks for the token
#
# Backs up the database first, then pulls, installs dependencies, migrates
# and clears caches. Everything is printed and saved to
# /var/log/shahpanel-update.log
#
set -Eeuo pipefail

APP_DIR="${APP_DIR:-/var/www/shahpanel}"
REPO="${REPO:-https://github.com/shahinst/shahpanel.git}"
BRANCH="${BRANCH:-master}"
BACKUP_DIR="${BACKUP_DIR:-/var/backups/shahpanel}"
LOG="/var/log/shahpanel-update.log"

export DEBIAN_FRONTEND=noninteractive
export COMPOSER_ALLOW_SUPERUSER=1
export COMPOSER_PROCESS_TIMEOUT=600

RED=$'\e[31m'; GRN=$'\e[32m'; YLW=$'\e[33m'; BLU=$'\e[36m'; DIM=$'\e[2m'; BLD=$'\e[1m'; RST=$'\e[0m'

STEP_NO=0
step() { STEP_NO=$((STEP_NO+1)); echo -e "\n${BLU}${BLD}[${STEP_NO}/7] $*${RST}"; }
ok()   { echo -e "  ${GRN}✓${RST} $*"; }
info() { echo -e "  ${DIM}· $*${RST}"; }
warn() { echo -e "  ${YLW}!${RST} $*"; }
die()  { echo -e "\n${RED}✗ FAILED: $*${RST}\n  Full log: ${LOG}\n" >&2; exit 1; }

run() { echo -e "  ${DIM}\$ $*${RST}"; "$@"; }

trap 'die "aborted at line $LINENO (see the error above)"' ERR

[[ $EUID -eq 0 ]] || die "run as root:  sudo bash update.sh"
[[ -d "$APP_DIR/.git" ]] || die "$APP_DIR is not a git checkout — was the panel installed with install.sh?"

# This script normally lives inside the checkout it is about to update, so the
# merge would rewrite the file bash is still reading. Re-exec from a copy first.
SELF="$(readlink -f "${BASH_SOURCE[0]}")"
if [[ "$SELF" == "$APP_DIR"/* && -z "${PANEL_UPDATE_RELOCATED:-}" ]]; then
  RELOC="$(mktemp /tmp/panel-update-XXXXXX.sh)"
  cp "$SELF" "$RELOC"
  PANEL_UPDATE_RELOCATED=1 exec bash "$RELOC" "$@"
fi
[[ -n "${PANEL_UPDATE_RELOCATED:-}" ]] && trap 'rm -f "$SELF"' EXIT

touch "$LOG" 2>/dev/null || true
exec > >(tee -a "$LOG") 2>&1

echo -e "${BLD}Panel updater${RST}  —  started $(date)"
echo -e "${DIM}A full log is being written to ${LOG}${RST}"

cd "$APP_DIR"

# ── 1) Token ─────────────────────────────────────────────────────────────
# The repo is private and install.sh deliberately leaves no token behind in
# .git/config, so every pull needs one supplied here.
step "GitHub access"
GH_TOKEN="${GITHUB_TOKEN:-}"
while [[ -z "$GH_TOKEN" ]]; do
  read -rsp "  GitHub token (input hidden): " GH_TOKEN; echo
done
REPO_PATH="${REPO#https://}"
AUTH_URL="https://${GH_TOKEN}@${REPO_PATH}"
ok "token supplied (never written to disk)"

# ── 2) Local state ───────────────────────────────────────────────────────
step "Checking local state"
# The checkout belongs to www-data but we run as root, which git refuses to
# touch until the directory is declared safe.
git config --global --add safe.directory "$APP_DIR" 2>/dev/null || true
# chmod -R 775 storage flips the mode bit on tracked placeholder files, which
# would otherwise look like local edits forever.
git config core.fileMode false
CURRENT="$(git rev-parse --short HEAD)"
info "currently at $CURRENT"
CHANGED="$(git status --porcelain --untracked-files=no)"
if [[ -n "$CHANGED" ]]; then
  warn "there are local modifications to tracked files:"
  echo "$CHANGED" | sed 's/^/    /'
  die "commit, stash or revert them first — refusing to overwrite your changes"
fi
ok "working tree is clean"

# ── 3) Database backup ───────────────────────────────────────────────────
# A migration that goes wrong is only recoverable if this exists.
step "Backing up the database"
mkdir -p "$BACKUP_DIR"
envval() { grep -E "^$1=" .env | head -1 | cut -d= -f2- | tr -d '"'"'"' '; }
DB_NAME="$(envval DB_DATABASE)"
DB_USER="$(envval DB_USERNAME)"
DB_PASS="$(envval DB_PASSWORD)"
# Honour DB_HOST: without -h, mysqldump goes through the unix socket as
# 'user'@'localhost', which is a different grant from 'user'@'127.0.0.1'
# and is usually denied.
DB_HOST="$(envval DB_HOST)"; DB_HOST="${DB_HOST:-127.0.0.1}"
DB_PORT="$(envval DB_PORT)"; DB_PORT="${DB_PORT:-3306}"
DUMP="$BACKUP_DIR/db-$(date +%Y%m%d-%H%M%S).sql.gz"
# --no-tablespaces: dumping tablespaces needs the PROCESS privilege, which the
# panel's database user has no reason to hold.
if mysqldump --single-transaction --quick --no-tablespaces \
     -h"$DB_HOST" -P"$DB_PORT" -u"$DB_USER" -p"$DB_PASS" "$DB_NAME" 2>/dev/null | gzip > "$DUMP"; then
  [[ -s "$DUMP" ]] || { rm -f "$DUMP"; die "database backup came out empty — refusing to continue"; }
  ok "saved $(du -h "$DUMP" | cut -f1) to $DUMP"
else
  rm -f "$DUMP"
  die "database backup failed — not touching the code until a backup exists"
fi
info "keeping the 10 most recent backups"
ls -1t "$BACKUP_DIR"/db-*.sql.gz 2>/dev/null | tail -n +11 | xargs -r rm -f

# ── 4) Pull ──────────────────────────────────────────────────────────────
step "Fetching the latest code"
# Deliberately NOT through run(): that would echo the token into the log file.
echo -e "  ${DIM}\$ git fetch https://***TOKEN***@${REPO_PATH} ${BRANCH}${RST}"
git fetch "$AUTH_URL" "$BRANCH"
BEHIND="$(git rev-list --count HEAD..FETCH_HEAD)"
if [[ "$BEHIND" -eq 0 ]]; then
  ok "already up to date — nothing to do"
  echo -e "\n${GRN}${BLD}Done.${RST} Panel is on the latest version ($CURRENT).\n"
  exit 0
fi
info "$BEHIND new commit(s):"
git --no-pager log --oneline HEAD..FETCH_HEAD | sed 's/^/    /'
# A bootstrap copy of this script downloaded into the checkout is untracked and
# would block the merge; the tracked version is what replaces it.
if [[ -f update.sh ]] && ! git ls-files --error-unmatch update.sh >/dev/null 2>&1; then
  info "removing the untracked bootstrap copy of update.sh"
  rm -f update.sh
fi
run git merge --ff-only FETCH_HEAD
ok "now at $(git rev-parse --short HEAD)"

# ── 5) Dependencies ──────────────────────────────────────────────────────
step "Installing PHP dependencies"
run composer install --no-dev --optimize-autoloader --no-interaction
ok "vendor/ is up to date"

# ── 6) Database migrations ───────────────────────────────────────────────
# artisan must run as www-data: as root it writes root-owned files into
# storage/framework/cache, and the www-data cron then fails on them every
# minute with "Failed to open stream: Permission denied".
step "Running migrations"
run sudo -u www-data php artisan migrate --force
ok "schema is up to date"

# ── 7) Caches and permissions ────────────────────────────────────────────
step "Clearing caches and fixing permissions"
run sudo -u www-data php artisan optimize:clear
chown -R www-data:www-data storage bootstrap/cache
chmod -R 775 storage bootstrap/cache
# The queue worker runs the old code until it is told to stop and respawn.
sudo -u www-data php artisan queue:restart >/dev/null 2>&1 || true
PHPFPM="$(systemctl list-units --type=service --no-legend 'php*-fpm.service' | awk '{print $1}' | head -1)"
[[ -n "$PHPFPM" ]] && run systemctl reload "$PHPFPM"
ok "caches cleared, workers signalled to restart"

echo -e "\n${GRN}${BLD}Update complete.${RST}"
echo -e "  ${DIM}from${RST} $CURRENT  ${DIM}to${RST} $(git rev-parse --short HEAD)"
echo -e "  ${DIM}database backup:${RST} $DUMP"
echo -e "  ${DIM}full log:${RST} $LOG\n"
