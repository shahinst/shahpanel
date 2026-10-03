#!/usr/bin/env bash
#
# Updater for ShahPanel — pulls the latest code from GitHub.
#
#   sudo bash update.sh                       # public repository, no token needed
#   sudo GITHUB_TOKEN=<token> bash update.sh  # only for a private fork
#
# Backs up the database first, then pulls, installs dependencies, migrates
# and clears caches. Everything is printed and saved to
# /var/log/shahpanel-update.log
#
# If any step after the backup fails, the update rolls itself back: the code
# returns to the commit it started from, vendor/ is reinstalled for it, the
# database is restored from the backup when migrations had started, caches are
# cleared and PHP is restarted -- so a failed update never leaves the panel
# half-upgraded.
#
# Optional environment:
#   PANEL_UPDATE_STAMP   backup name stamp (YYYYmmdd-HHMMSS); default: now
#   PANEL_UPDATE_STATUS  JSON file to keep updated with status/stage/error
#   PANEL_UPDATE_WEB=1   plain output (no colours)
#
set -Eeuo pipefail

# Where the panel lives: APP_DIR when given; otherwise the checkout this script
# sits in (installs outside /var/www/shahpanel used to fail with "not a git
# checkout" unless APP_DIR was passed by hand); otherwise the default path.
if [[ -z "${APP_DIR:-}" ]]; then
  _self="$(readlink -f "${BASH_SOURCE[0]}" 2>/dev/null || true)"
  _self_dir="${_self:+$(dirname "$_self")}"
  if [[ -n "$_self_dir" && -f "$_self_dir/artisan" && -d "$_self_dir/.git" ]]; then
    APP_DIR="$_self_dir"
  else
    APP_DIR="/var/www/shahpanel"
  fi
fi
# Exported so the copy this script re-executes from (below) keeps the path.
export APP_DIR

# Started from the panel's Update button, this runs as a systemd unit with no
# HOME. Without it `git config --global` silently does nothing (so git then
# refuses the www-data-owned checkout) and composer will not start at all.
export HOME="${HOME:-/root}"
[[ -d "$HOME" ]] || export HOME=/root

# Every git call trusts the checkout explicitly. Relying on the global
# safe.directory entry alone failed whenever that entry could not be written.
# Hooks and fsmonitor are always off and system-level git config is ignored:
# the checkout may have been owned by the web user (older installs), and
# either setting would let that user run commands as root.
export GIT_CONFIG_NOSYSTEM=1
git() {
  command git -c safe.directory="$APP_DIR" -c core.hooksPath=/dev/null \
    -c core.fsmonitor=false -c core.sshCommand=ssh -c credential.helper= "$@"
}

# composer runs as root (it writes vendor/, which root owns) but never runs
# package scripts or plugins as root: those boot the application, and the
# application loads modules/, which the web user can write. Package discovery
# runs afterwards as the web user.
composer_install() {
  composer install --no-dev --optimize-autoloader --no-interaction --no-scripts --no-plugins \
    && sudo -u www-data php artisan package:discover --ansi >/dev/null
}

# Refuse to touch a checkout whose own git config could redirect a fetch or
# run commands (url.*.insteadOf, include, core.sshCommand, aliases, ...).
assert_safe_git_config() {
  local bad
  bad="$(command git -c safe.directory="$APP_DIR" -C "$APP_DIR" config --local --name-only --list 2>/dev/null \
    | grep -Eiv '^(core\.(repositoryformatversion|filemode|bare|logallrefupdates|ignorecase|precomposeunicode|autocrlf)|remote\.origin\.(url|fetch)|branch\.[^.]+\.(remote|merge)|gc\.auto|pull\.(rebase|ff)|safe\.directory|init\.defaultbranch|user\.(name|email))$' || true)"
  if [[ -n "$bad" ]]; then
    warn "unexpected settings in $APP_DIR/.git/config:"
    echo "$bad" | sed 's/^/    /'
    die "refusing to run git as root with these settings — remove them (git config --local --unset <name>) and run again"
  fi
}

# The code belongs to root; the web user writes only where the panel stores
# data. A compromised web user can then no longer change the code (or .git)
# that this script and the scheduler run.
secure_permissions() {
  chown -R root:root "$APP_DIR"
  find "$APP_DIR" -path "$APP_DIR/vendor" -prune -o -path "$APP_DIR/node_modules" -prune -o -type d -exec chmod 755 {} +
  chmod -R u+rwX,go+rX,go-w "$APP_DIR"
  local d
  for d in storage bootstrap/cache modules; do
    [[ -e "$APP_DIR/$d" ]] && chown -R www-data:www-data "$APP_DIR/$d" && chmod -R u+rwX,g+rwX "$APP_DIR/$d"
  done
  # Written by the panel's Apache basic-auth option.
  [[ -f "$APP_DIR/public/.htaccess" ]] && chown www-data:www-data "$APP_DIR/public/.htaccess"
  if [[ -f "$APP_DIR/.env" ]]; then
    chown root:www-data "$APP_DIR/.env"
    chmod 640 "$APP_DIR/.env"
  fi
  [[ -f "$APP_DIR/.installed.lock" ]] && chmod 644 "$APP_DIR/.installed.lock"
  return 0
}
REPO="${REPO:-https://github.com/shahinst/shahpanel.git}"
BRANCH="${BRANCH:-master}"
BACKUP_DIR="${BACKUP_DIR:-/var/backups/shahpanel}"
LOG="/var/log/shahpanel-update.log"
STATUS_FILE="${PANEL_UPDATE_STATUS:-}"
STAMP="${PANEL_UPDATE_STAMP:-}"
[[ "$STAMP" =~ ^[0-9]{8}-[0-9]{6}$ ]] || STAMP="$(date +%Y%m%d-%H%M%S)"

export DEBIAN_FRONTEND=noninteractive
export COMPOSER_ALLOW_SUPERUSER=1
export COMPOSER_PROCESS_TIMEOUT=600
export COMPOSER_NO_INTERACTION=1

if [[ -n "${PANEL_UPDATE_WEB:-}" || ! -t 1 ]]; then
  RED=''; GRN=''; YLW=''; BLU=''; DIM=''; BLD=''; RST=''
else
  RED=$'\e[31m'; GRN=$'\e[32m'; YLW=$'\e[33m'; BLU=$'\e[36m'; DIM=$'\e[2m'; BLD=$'\e[1m'; RST=$'\e[0m'
fi

STEP_NO=0
STAGE="start"
CURRENT=""
CURRENT_FULL=""
DUMP=""
CODE_CHANGED=0
VENDOR_TOUCHED=0
MIGRATE_STARTED=0
MAINTENANCE=0
STARTED_AT="$(date -Is)"

step() { STEP_NO=$((STEP_NO+1)); echo -e "\n${BLU}${BLD}[${STEP_NO}/8] $*${RST}"; }
ok()   { echo -e "  ${GRN}✓${RST} $*"; }
info() { echo -e "  ${DIM}· $*${RST}"; }
warn() { echo -e "  ${YLW}!${RST} $*"; }

LAST_CMD=""
run() { LAST_CMD="$*"; echo -e "  ${DIM}\$ $*${RST}"; "$@"; }

# JSON-quote a string (php is always present on a panel server).
jstr() { php -r 'echo json_encode($argv[1], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);' -- "$1" 2>/dev/null || printf '""'; }

# Status for the admin page's console; written atomically, readable by nginx.
write_status() {
  [[ -n "$STATUS_FILE" ]] || return 0
  local status="$1" error="${2:-}" tmp
  tmp="${STATUS_FILE}.tmp"
  {
    printf '{"status":%s,"stage":%s,"from":%s,"to":%s,"backup":%s,"error":%s,"started_at":%s,"updated_at":%s}' \
      "$(jstr "$status")" "$(jstr "$STAGE")" "$(jstr "$CURRENT")" \
      "$(jstr "$(git -C "$APP_DIR" rev-parse --short HEAD 2>/dev/null || true)")" \
      "$(jstr "$DUMP")" "$(jstr "$error")" "$(jstr "$STARTED_AT")" "$(jstr "$(date -Is)")"
  } > "$tmp" 2>/dev/null && chmod 644 "$tmp" && mv -f "$tmp" "$STATUS_FILE"
}

stage() { STAGE="$1"; write_status running; }

restart_php() {
  local fpm=""
  fpm="$(systemctl list-units --type=service --no-legend 'php*-fpm.service' 2>/dev/null | awk '{print $1}' | head -1)" || fpm=""
  if [[ -n "$fpm" ]]; then
    systemctl restart "$fpm" && ok "PHP restarted ($fpm)"
  else
    warn "no php-fpm service found — restart PHP by hand so it loads the new code"
  fi
}

# Put everything back the way it was before this run touched it.
rollback() {
  local reason="$1" failed=0
  set +e
  trap - ERR
  echo -e "\n${YLW}${BLD}Rolling back to ${CURRENT:-the previous version}…${RST}"
  write_status rolling_back "$reason"

  cd "$APP_DIR" || failed=1

  if [[ "$CODE_CHANGED" -eq 1 && -n "$CURRENT_FULL" ]]; then
    if git reset --hard "$CURRENT_FULL"; then ok "code restored to $CURRENT"; else warn "could not restore the code"; failed=1; fi
  fi

  if [[ "$VENDOR_TOUCHED" -eq 1 ]]; then
    if composer_install; then ok "vendor/ restored"; else warn "could not restore vendor/"; failed=1; fi
  fi

  if [[ "$MIGRATE_STARTED" -eq 1 && -n "$DUMP" && -s "$DUMP" ]]; then
    info "restoring the database from $DUMP"
    # Drop every table first so tables a new migration created do not survive
    # next to the restored schema (the next update would trip over them).
    local tables
    tables="$(mysql -N -h"$DB_HOST" -P"$DB_PORT" -u"$DB_USER" "$DB_NAME" -e 'SHOW TABLES' 2>/dev/null)"
    {
      echo "SET FOREIGN_KEY_CHECKS=0;"
      while read -r t; do [[ -n "$t" ]] && echo "DROP TABLE IF EXISTS \`$t\`;"; done <<< "$tables"
      echo "SET FOREIGN_KEY_CHECKS=1;"
    } | mysql -h"$DB_HOST" -P"$DB_PORT" -u"$DB_USER" "$DB_NAME" \
      && gunzip -c "$DUMP" | mysql -h"$DB_HOST" -P"$DB_PORT" -u"$DB_USER" "$DB_NAME"
    if [[ $? -eq 0 ]]; then ok "database restored"; else warn "could not restore the database — restore $DUMP by hand"; failed=1; fi
  fi

  sudo -u www-data php artisan optimize:clear >/dev/null 2>&1 || true
  if [[ "$MAINTENANCE" -eq 1 ]]; then
    sudo -u www-data php artisan up >/dev/null 2>&1 || rm -f "$APP_DIR/storage/framework/down"
  fi
  secure_permissions 2>/dev/null || true
  sudo -u www-data php artisan queue:restart >/dev/null 2>&1 || true
  restart_php || true

  if [[ "$failed" -eq 0 ]]; then
    echo -e "\n${YLW}${BLD}Update failed and was rolled back.${RST} The panel is running the previous version."
    write_status rolled_back "$reason"
  else
    echo -e "\n${RED}${BLD}Update failed and the rollback was incomplete.${RST} See the messages above."
    write_status rollback_failed "$reason"
  fi
  echo -e "  ${DIM}reason:${RST} $reason"
  echo -e "  ${DIM}database backup:${RST} ${DUMP:-none}"
  echo -e "  ${DIM}full log:${RST} $LOG\n"
  exit 1
}

die() {
  echo -e "\n${RED}✗ FAILED: $*${RST}\n  Full log: ${LOG}\n" >&2
  # Nothing has been changed before the backup exists, so there is nothing
  # to undo; after it, every failure is rolled back.
  if [[ "$CODE_CHANGED" -eq 1 || "$VENDOR_TOUCHED" -eq 1 || "$MIGRATE_STARTED" -eq 1 ]]; then
    rollback "$*"
  fi
  write_status failed "$*"
  exit 1
}

on_error() {
  local code=$? line=$1 cmd=$2
  # Inside run() the failing command reads as "$@"; name the real one.
  [[ "$cmd" == '"$@"' && -n "$LAST_CMD" ]] && cmd="$LAST_CMD"
  die "step \"${STAGE}\" failed: \`${cmd}\` exited with ${code} (line ${line})"
}

trap 'on_error $LINENO "$BASH_COMMAND"' ERR

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
# The repository is public, so a token is optional. It is still honoured for
# anyone running a private fork — but never demanded, because an unattended
# update (cron, a script, a pipe) has no terminal to type it into and would
# hang here forever waiting for input nobody can give.
GH_TOKEN="${GITHUB_TOKEN:-}"
REPO_PATH="${REPO#https://}"
if [[ -n "$GH_TOKEN" ]]; then
  AUTH_URL="https://${GH_TOKEN}@${REPO_PATH}"
  ok "token supplied (never written to disk)"
else
  AUTH_URL="https://${REPO_PATH}"
  ok "public repository — no token needed"
fi

# ── 2) Local state ───────────────────────────────────────────────────────
step "Checking local state"
# The checkout belongs to www-data but we run as root, which git refuses to
# touch until the directory is declared safe.
git config --global --add safe.directory "$APP_DIR" 2>/dev/null || true
assert_safe_git_config
# chmod -R 775 storage flips the mode bit on tracked placeholder files, which
# would otherwise look like local edits forever.
git config core.fileMode false
CURRENT="$(git rev-parse --short HEAD)"
CURRENT_FULL="$(git rev-parse HEAD)"
stage "checking"
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
stage "backup"
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
DUMP="$BACKUP_DIR/db-${STAMP}.sql.gz"
# The password goes through the environment, not the command line, where any
# local user could read it from ps for the length of the dump.
export MYSQL_PWD="$DB_PASS"
# --no-tablespaces: dumping tablespaces needs the PROCESS privilege, which the
# panel's database user has no reason to hold.
if mysqldump --single-transaction --quick --no-tablespaces \
     -h"$DB_HOST" -P"$DB_PORT" -u"$DB_USER" "$DB_NAME" 2>/dev/null | gzip > "$DUMP"; then
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
stage "fetch"
# Deliberately NOT through run(): that would echo the token into the log file.
echo -e "  ${DIM}\$ git fetch https://${GH_TOKEN:+***TOKEN***@}${REPO_PATH} ${BRANCH}${RST}"
# Hooks and fsmonitor are switched off: this runs as root in a checkout the
# web user owns, and either would let that user run commands as root.
git -c core.hooksPath=/dev/null -c core.fsmonitor=false fetch "$AUTH_URL" "$BRANCH"
BEHIND="$(git rev-list --count HEAD..FETCH_HEAD)"
# The checkout can already be current while the database is not — someone may
# have moved the code with git directly, which a rewritten history forces them
# to do. Exiting here would leave new code running against an old schema, so we
# skip only the merge and still run composer, the migrations and the cache
# clears below. They are all idempotent: a needless pass costs seconds, a
# skipped one costs a broken panel.
if [[ "$BEHIND" -eq 0 ]]; then
  ok "code is already current — checking dependencies and database anyway"
else
  info "$BEHIND new commit(s):"
  git --no-pager log --oneline HEAD..FETCH_HEAD | sed 's/^/    /'
  # A bootstrap copy of this script downloaded into the checkout is untracked and
  # would block the merge; the tracked version is what replaces it.
  if [[ -f update.sh ]] && ! git ls-files --error-unmatch update.sh >/dev/null 2>&1; then
    info "removing the untracked bootstrap copy of update.sh"
    rm -f update.sh
  fi
  stage "merge"
  CODE_CHANGED=1
  if git merge-base --is-ancestor HEAD FETCH_HEAD; then
    run git -c core.hooksPath=/dev/null -c core.fsmonitor=false merge --ff-only FETCH_HEAD
  else
    # The branch's history was rewritten upstream, so a fast-forward is
    # impossible even though nothing here was changed (the working tree was
    # checked clean above, and servers never commit). Move to the published
    # code; a failure later still rolls back to $CURRENT.
    warn "the published history was rewritten — moving to it instead of fast-forwarding"
    run git -c core.hooksPath=/dev/null -c core.fsmonitor=false reset --hard FETCH_HEAD
  fi
fi
ok "now at $(git rev-parse --short HEAD)"

# ── 5) Dependencies ──────────────────────────────────────────────────────
step "Installing PHP dependencies"
stage "composer"
VENDOR_TOUCHED=1
run composer_install
ok "vendor/ is up to date"

# ── 6) Database migrations ───────────────────────────────────────────────
# artisan must run as www-data: as root it writes root-owned files into
# storage/framework/cache, and the www-data cron then fails on them every
# minute with "Failed to open stream: Permission denied".
step "Running migrations"
stage "migrate"
# Visitors get a short "back in a moment" page instead of errors from new
# code running against a half-migrated database.
if sudo -u www-data php artisan down --retry=30 >/dev/null 2>&1; then MAINTENANCE=1; fi
MIGRATE_STARTED=1
run sudo -u www-data php artisan migrate --force
ok "schema is up to date"

# ── 7) Caches and permissions ────────────────────────────────────────────
step "Clearing caches and fixing permissions"
stage "caches"
run sudo -u www-data php artisan optimize:clear
secure_permissions
# The queue worker runs the old code until it is told to stop and respawn.
sudo -u www-data php artisan queue:restart >/dev/null 2>&1 || true
# Older installs were written without fastcgi_read_timeout, so a long admin
# action (syncing every account of a big server) hit nginx's 60-second default
# and showed a 504. Add it to this panel's site file once; roll back if nginx
# rejects the result. install.sh keeps the PHP block in a snippet; older
# hand-made setups may have it directly in the site file.
for SITE in /etc/nginx/snippets/*.conf /etc/nginx/sites-available/*; do
    [[ -f "$SITE" ]] || continue
    grep -q "root ${APP_DIR}/public" "$SITE" || continue
    grep -q "fastcgi_pass unix:/run/php/" "$SITE" || continue
    grep -q "fastcgi_read_timeout" "$SITE" && continue
    cp -a "$SITE" "${SITE}.bak-timeout"
    sed -i '/fastcgi_pass unix:\/run\/php\//a\    fastcgi_read_timeout 300s;' "$SITE"
    if nginx -t >/dev/null 2>&1; then
        systemctl reload nginx && ok "nginx: FastCGI timeout raised in $(basename "$SITE")"
        rm -f "${SITE}.bak-timeout"
    else
        mv -f "${SITE}.bak-timeout" "$SITE"
        warn "nginx rejected the timeout change in $(basename "$SITE"); left it as it was"
    fi
done
ok "caches cleared"

# ── 8) Health check ──────────────────────────────────
step "Checking the new version"
stage "verify"
# The new code must at least boot: a fatal error here is rolled back now,
# not discovered by the first visitor.
run sudo -u www-data php artisan --version
run sudo -u www-data php artisan route:list --path=__shahpanel_healthcheck__ >/dev/null 2>&1 || sudo -u www-data php artisan about --only=environment >/dev/null
if [[ "$MAINTENANCE" -eq 1 ]]; then
  sudo -u www-data php artisan up >/dev/null 2>&1 || true
  MAINTENANCE=0
fi


# Root-side launcher for the panel's "Update" button (scripts/shahpanel-update).
# The updater it starts is a root-owned copy of this script outside the
# checkout, so the web user cannot change what runs as root. Files are swapped
# in with mv, so a copy that is running right now keeps reading its old inode.
install_web_updater() {
  local src="$APP_DIR/scripts/shahpanel-update"
  [[ -f "$src" ]] || return 0
  install -d -o root -g root -m 0755 /usr/local/lib/shahpanel
  sed "s#__APP_DIR__#${APP_DIR}#g" "$src" > /usr/local/sbin/.shahpanel-update.new
  chown root:root /usr/local/sbin/.shahpanel-update.new
  chmod 0750 /usr/local/sbin/.shahpanel-update.new
  mv -f /usr/local/sbin/.shahpanel-update.new /usr/local/sbin/shahpanel-update
  install -o root -g root -m 0750 "$APP_DIR/update.sh" /usr/local/lib/shahpanel/.update.sh.new
  mv -f /usr/local/lib/shahpanel/.update.sh.new /usr/local/lib/shahpanel/update.sh
  printf 'www-data ALL=(root) NOPASSWD: /usr/local/sbin/shahpanel-update\n' > /etc/sudoers.d/.shahpanel-update.new
  chmod 0440 /etc/sudoers.d/.shahpanel-update.new
  if visudo -cf /etc/sudoers.d/.shahpanel-update.new >/dev/null 2>&1; then
    mv -f /etc/sudoers.d/.shahpanel-update.new /etc/sudoers.d/shahpanel-update
    # Progress files live outside the webroot in a root-owned folder; the
    # panel serves them to the main admin only.
    install -d -o root -g root -m 0755 /var/lib/shahpanel /var/lib/shahpanel/progress
    rm -rf "$APP_DIR/public/update-progress"
    ok "web updater installed"
  else
    rm -f /etc/sudoers.d/.shahpanel-update.new
    warn "web updater not installed: the sudoers rule was rejected"
  fi
}
install_web_updater

# restart, not reload: a reload keeps the existing workers alive, so OPcache
# goes on serving the PHP files from before the update and the panel silently
# runs half the old code until something else restarts the service.
stage "restart"
restart_php

STAGE="done"
trap - ERR
write_status success
echo -e "\n${GRN}${BLD}Update complete.${RST}"
echo -e "  ${DIM}from${RST} $CURRENT  ${DIM}to${RST} $(git rev-parse --short HEAD)"
echo -e "  ${DIM}database backup:${RST} $DUMP"
echo -e "  ${DIM}full log:${RST} $LOG\n"
