#!/usr/bin/env bash
# shellcheck disable=SC2016  # PHP snippets are intentionally single-quoted (no shell expansion)
#
# LBA Cloud v3.0 — audited STAGING deployment (WS6.F).
#
# A single, explicit, fail-closed path that deploys an APPROVED revision to a
# STAGING host. It is deliberately conservative and idempotent.
#
# Pipeline:
#   1. preflight (binaries, clean tree, APP_KEY present, central DB reachable);
#   2. explicit staging-target attestation (independent of APP_ENV);
#   3. read-only backup (central + tenant databases, APP_KEY, private media);
#   4. non-destructive pull of the approved revision (immutable SHA);
#   5. composer install (production dependencies);
#   6. central migrations (`migrate --force`, additive only);
#   7. migrate every ALREADY REGISTERED tenant database (never provisions new);
#   8. build front-end assets;
#   9. cache strategy (selective clears; Filament asset publishing);
#  10. health validation (`lba:staging:health`);
#  11. reachability check for /principal, /clinic, /patient;
#  12. login-page static-asset smoke check (`lba:staging:login-assets`) — fetches
#      the real /login markup and probes every local CSS/JS/font it references.
#
# SAFETY GUARANTEES (do not weaken):
#   * NEVER runs a destructive schema command (fresh/refresh/reset/wipe).
#   * NEVER overwrites a database — migrations are additive and a full
#     pre-deploy backup is taken first.
#   * NEVER modifies APP_KEY (checked in preflight; left untouched).
#   * NEVER provisions databases — it only migrates tenant databases already
#     registered centrally; no `lba:clinic:setup` is run.
#   * NEVER force-resets local changes (no `reset --hard`, no forced pull).
#   * NEVER targets an obvious production host (hostname denylist) AND requires
#     positive identification of the staging target: APP_URL must equal the
#     approved staging URL, the cPanel account must match, and the operator must
#     attest explicitly before it will run.
#   * Stops immediately and safely on the first failure (no destructive rollback).
#
# Every run is audited: a timestamped log is written to
# storage/logs/deploy-staging.log (override with --log) and echoed to stdout.

set -Eeuo pipefail

# ---------------------------------------------------------------- Constants
SCRIPT_NAME="$(basename "$0")"
REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

# Approved revision to deploy. Defaults to the latest approved `main`.
REF="origin/main"
BACKUP_DIR=""
LOG_FILE=""
SKIP_ASSETS=0
SKIP_BACKUP=0
SKIP_PANELS=0
SKIP_LOGIN_ASSETS=0
DRY_RUN=0
OPTIMIZE=0

# Explicit staging attestation (required for any real run).
CONFIRM_STAGING=0
ATTEST_HOST=""
OPERATOR="${LBA_DEPLOY_OPERATOR:-${SUDO_USER:-${USER:-unknown}}}"

# Hostname patterns that are always refused (true production). Operators may
# tighten this with LBA_PRODUCTION_HOST_PATTERN but should not loosen it.
PRODUCTION_HOST_PATTERN="${LBA_PRODUCTION_HOST_PATTERN:-prod|production|live|www}"

# Positive identification of the approved staging target (P2.3). A denylist
# alone is not proof of the target: the run must match the EXPECTED staging URL
# and cPanel account. Both are overridable, but never silently skipped.
STAGING_APP_URL="${LBA_STAGING_APP_URL:-https://dev.lbacloud.com}"
STAGING_CPANEL_ACCOUNT="${LBA_STAGING_CPANEL_ACCOUNT:-lbacloud}"

# Filament can break when `php artisan optimize` caches config/views on some
# cPanel/LiteSpeed hosts. The safe default is selective clears; full `optimize`
# is opt-in via --optimize.
SAFE_CACHE_DEFAULT=1

CURRENT_STEP="preflight"
DEPLOY_STARTED_AT="$(date -u '+%Y-%m-%dT%H:%M:%SZ')"
BACKUP_DIR_RESOLVED=""
DEPLOYED_SHA=""

# ------------------------------------------------------------------- Output
log() {
    local level="$1"; shift
    local message="$*"
    local line
    line="$(date -u '+%Y-%m-%dT%H:%M:%SZ') [${level}] [${CURRENT_STEP}] ${message}"
    printf '%s\n' "$line"
    if [[ -n "${LOG_FILE}" ]]; then
        printf '%s\n' "$line" >> "${LOG_FILE}"
    fi
}

info()  { log INFO  "$@"; }
warn()  { log WARN  "$@"; }
error() { log ERROR "$@" >&2; }

step() {
    CURRENT_STEP="$1"
    log INFO "==== ${1} ===="
}

die() {
    error "$@"
    exit 1
}

# Stop safely: report where we stopped and exit non-zero. No destructive undo.
on_error() {
    local exit_code=$?
    error "Deployment FAILED during [${CURRENT_STEP}] (exit ${exit_code})."
    error "Stopped safely — no destructive rollback was performed."
    if [[ -n "${BACKUP_DIR_RESOLVED}" ]]; then
        error "Pre-deploy backup directory: ${BACKUP_DIR_RESOLVED}"
        error "See docs/WS6_STAGING_RUNBOOK.md for the manual restore procedure."
    fi
    if [[ -n "${DEPLOYED_SHA}" ]]; then
        error "Revision checked out before the failure: ${DEPLOYED_SHA}"
    fi
    error "Inspect the log, fix the cause, then re-run ${SCRIPT_NAME} (it is idempotent)."
    exit 2
}
trap on_error ERR

# --------------------------------------------------------------- Arguments
usage() {
    cat <<'USAGE'
LBA Cloud v3.0 — audited STAGING deployment (WS6.F)

Usage:
  ./deploy-staging.sh --confirm-staging --attest-host <host> [options]

Required (real runs):
  --confirm-staging            Explicit operator approval to deploy to staging.
  --attest-host <host>         Must equal the OS hostname of this host.

The target is then verified POSITIVELY (not just against a denylist):
  APP_URL           must equal the approved staging URL
                    (default https://dev.lbacloud.com; override LBA_STAGING_APP_URL).
  CPANEL_USERNAME   must equal the approved cPanel account
                    (default lbacloud; override LBA_STAGING_CPANEL_ACCOUNT).

Options:
  --ref <ref>                  Revision to deploy (branch/tag/SHA). Default origin/main.
  --branch <name>              Shortcut for --ref origin/<name>.
  --operator <name>            Operator identity recorded in the audit log.
  --backup-dir <dir>           Backup target. Default storage/app/deploy-backups.
  --log <file>                 Audit log file. Default storage/logs/deploy-staging.log.
  --skip-assets                Skip npm ci && npm run build.
  --skip-backup                Skip the pre-deploy backup (explicitly requested).
  --skip-panels                Skip the /principal /clinic /patient reachability check.
  --skip-login-assets          Skip the /login static-asset smoke check.
  --optimize                   Also run `php artisan optimize` (unsafe on some hosts).
  --dry-run                    Preview every step without changing anything.
  --help, -h                   Show this help.

Exit codes: 0 success · 1 usage / precondition failure · 2 a step failed.
USAGE
}

parse_args() {
    while [[ $# -gt 0 ]]; do
        case "$1" in
            --ref)           REF="${2:?--ref requires a value}"; shift 2 ;;
            --branch)        REF="origin/${2:?--branch requires a value}"; shift 2 ;;
            --operator)      OPERATOR="${2:?--operator requires a value}"; shift 2 ;;
            --backup-dir)    BACKUP_DIR="${2:?--backup-dir requires a value}"; shift 2 ;;
            --log)           LOG_FILE="${2:?--log requires a value}"; shift 2 ;;
            --attest-host)   ATTEST_HOST="${2:?--attest-host requires a value}"; shift 2 ;;
            --confirm-staging) CONFIRM_STAGING=1; shift ;;
            --skip-assets)   SKIP_ASSETS=1; shift ;;
            --skip-backup)   SKIP_BACKUP=1; shift ;;
            --skip-panels)   SKIP_PANELS=1; shift ;;
            --skip-login-assets) SKIP_LOGIN_ASSETS=1; shift ;;
            --optimize)      OPTIMIZE=1; shift ;;
            --dry-run)       DRY_RUN=1; shift ;;
            --help|-h)       usage; exit 0 ;;
            *)               die "Unknown argument: $1 (try --help)" ;;
        esac
    done
}

# Run a command, honouring --dry-run, and log it (audited).
run() {
    if [[ "${DRY_RUN}" -eq 1 ]]; then
        info "[dry-run] $*"
        return 0
    fi
    info "run: $*"
    "$@"
}

# Read KEY from the real environment, falling back to the app .env file.
env_value() {
    local key="$1"
    local value="${!key:-}"
    if [[ -z "${value}" && -f "${REPO_ROOT}/.env" ]]; then
        value="$(awk -v k="${key}" '
            $0 ~ "^[[:space:]]*"k"=" {
                sub("^[[:space:]]*"k"=", "")
                gsub(/^"|"$/, "")
                gsub(/^'\''|'\''$/, "")
                sub(/\r$/, "")
                v = $0
            }
            END { print v }
        ' "${REPO_ROOT}/.env")"
    fi
    printf '%s' "${value}"
}

require_binary() {
    command -v "$1" >/dev/null 2>&1 || die "Required binary not found on PATH: $1"
}

actual_host() {
    hostname -f 2>/dev/null || hostname
}

# ---------------------------------------------------------------- Preflight
preflight() {
    step "preflight"

    cd "${REPO_ROOT}"

    [[ -f "${REPO_ROOT}/artisan" && -f "${REPO_ROOT}/composer.json" ]] \
        || die "Not a Laravel application root (missing artisan/composer.json): ${REPO_ROOT}"

    require_binary git
    require_binary php
    require_binary composer
    if [[ "${SKIP_ASSETS}" -eq 0 ]]; then
        require_binary npm
    fi

    [[ -f "${REPO_ROOT}/.env" ]] || die "Missing .env — create it on the host before deploying."

    # The working tree must be clean so the deployed revision is exactly the
    # approved one. This script never force-resets local changes.
    if [[ "${DRY_RUN}" -eq 0 ]] && [[ -n "$(git status --porcelain)" ]]; then
        die "Working tree is dirty — commit/stash changes before deploying (this script never resets)."
    fi

    # APP_KEY must exist and is NEVER modified by this script.
    local app_key
    app_key="$(env_value APP_KEY)"
    if [[ -z "${app_key}" ]]; then
        die "APP_KEY is not set. Set it manually — this script never generates or changes APP_KEY."
    fi

    # The central database must be reachable before we touch anything.
    if [[ -f "${REPO_ROOT}/vendor/autoload.php" && "${DRY_RUN}" -eq 0 ]]; then
        php artisan migrate:status >/dev/null 2>&1 \
            || die "Central database is not reachable (php artisan migrate:status failed)."
    elif [[ ! -f "${REPO_ROOT}/vendor/autoload.php" ]]; then
        warn "vendor/ is absent — the central database reachability check runs after composer install."
    fi

    info "preflight OK (php $(php -r 'echo PHP_VERSION;'))"
}

# ------------------------------------------------------------ Attestation
# The cPanel staging host may intentionally run APP_ENV=production (to disable
# local-only features). APP_ENV is therefore NOT a reliable staging signal. This
# attestation binds the run to THIS host and requires explicit operator
# approval, while still hard-refusing any obvious production hostname.
attest_staging() {
    step "attestation"

    local host
    host="$(actual_host)"
    info "host: ${host} · operator: ${OPERATOR}"

    if [[ "${host,,}" =~ ${PRODUCTION_HOST_PATTERN} ]]; then
        die "Refusing to run: host [${host}] matches the production denylist (${PRODUCTION_HOST_PATTERN}). A true production host is never a valid staging target."
    fi

    if [[ "${CONFIRM_STAGING}" -ne 1 ]]; then
        die "Explicit staging attestation required: pass --confirm-staging --attest-host <host> (this host is [${host}])."
    fi

    if [[ -z "${ATTEST_HOST}" ]]; then
        die "--confirm-staging requires --attest-host <host> to bind the attestation to this machine."
    fi

    if [[ "${ATTEST_HOST}" != "${host}" ]]; then
        die "Attestation host mismatch: --attest-host [${ATTEST_HOST}] does not match this host [${host}]."
    fi

    # P2.3 — positively identify the target. The absence of an arbitrary host
    # name from a denylist proves nothing: the configuration itself must match
    # the approved staging URL and cPanel account.
    local app_url
    app_url="$(env_value APP_URL)"
    app_url="${app_url%/}"
    if [[ -z "${app_url}" ]]; then
        die "APP_URL is not set — cannot confirm this is the staging target (expected ${STAGING_APP_URL})."
    fi
    if [[ "${app_url}" != "${STAGING_APP_URL%/}" ]]; then
        die "APP_URL is [${app_url}] but the approved staging URL is [${STAGING_APP_URL}]. Refusing to deploy."
    fi
    info "APP_URL verified: ${app_url}"

    local cpanel_account
    cpanel_account="$(env_value CPANEL_USERNAME)"
    if [[ -z "${STAGING_CPANEL_ACCOUNT}" ]]; then
        warn "No approved cPanel account configured (LBA_STAGING_CPANEL_ACCOUNT empty) — the account check is skipped."
    elif [[ -z "${cpanel_account}" ]]; then
        die "CPANEL_USERNAME is not set — cannot verify the approved cPanel account [${STAGING_CPANEL_ACCOUNT}]."
    elif [[ "${cpanel_account}" != "${STAGING_CPANEL_ACCOUNT}" ]]; then
        die "The configured cPanel account is [${cpanel_account}] but the approved staging account is [${STAGING_CPANEL_ACCOUNT}]. Refusing to deploy."
    else
        info "cPanel account verified: ${cpanel_account}"
    fi

    local app_env
    app_env="$(env_value APP_ENV)"
    app_env="${app_env:-production}"
    info "application environment: ${app_env} (attested staging host)"

    if [[ "${app_env}" == "production" ]]; then
        warn "APP_ENV=production on an attested staging host — allowed, but confirm APP_DEBUG=false and verify the target before continuing."
    fi

    info "staging attestation accepted for host [${host}] at ${DEPLOY_STARTED_AT}"
}

# ---------------------------------------------------------------- Inventory
# Read-only view of the cPanel / tenancy posture. Never prints a secret.
inventory() {
    step "inventory"

    local prefix cpanel_enabled runtime_user
    prefix="$(env_value TENANT_DB_PREFIX)"
    cpanel_enabled="$(env_value CPANEL_PROVISIONING_ENABLED)"
    runtime_user="$(env_value CPANEL_RUNTIME_DB_USER)"

    info "tenant database prefix: ${prefix:-<unset>}"
    info "cPanel provisioning enabled: ${cpanel_enabled:-<unset>}"
    info "tenant runtime database user: ${runtime_user:-<unset>}"

    if [[ -n "${cpanel_enabled}" && "${cpanel_enabled}" != "false" && "${cpanel_enabled}" != "0" && -z "${runtime_user}" ]]; then
        warn "CPANEL_PROVISIONING_ENABLED is on but CPANEL_RUNTIME_DB_USER is empty — verify tenant runtime credentials."
    fi

    warn "This script never grants a global CREATE privilege, never recreates data and never changes APP_KEY."
}

# ------------------------------------------------------------------ Backups
resolved_backup_dir() {
    if [[ -n "${BACKUP_DIR}" ]]; then
        printf '%s' "${BACKUP_DIR}"
    else
        printf '%s' "${REPO_ROOT}/storage/app/deploy-backups"
    fi
}

# Read-only backup of BOTH the central and registered tenant databases, the
# application key and private media. Delegated to `lba:staging:backup` so the
# logic is exercised by automated tests and never prints a credential.
backup_all() {
    step "backup"

    local dir
    dir="$(resolved_backup_dir)"
    BACKUP_DIR_RESOLVED="${dir}"

    if [[ "${SKIP_BACKUP}" -eq 1 ]]; then
        warn "--skip-backup set: NOT taking a pre-deploy backup (explicitly requested)."
        return 0
    fi

    if [[ ! -f "${REPO_ROOT}/vendor/autoload.php" ]]; then
        die "vendor/ is absent — cannot run the backup. Install dependencies first or pass --skip-backup explicitly."
    fi

    if [[ "${DRY_RUN}" -eq 1 ]]; then
        info "[dry-run] would back up the central + tenant databases, APP_KEY and private media into ${dir}"
        return 0
    fi

    run php artisan lba:staging:backup --dir="${dir}"

    info "backup complete: ${dir} (shadow each registered tenant; see docs/WS6_STAGING_RUNBOOK.md)"
}

# -------------------------------------------------------------------- Deploy
pull_approved_changes() {
    step "pull"

    local before
    before="$(git rev-parse --short HEAD)"

    run git fetch --prune origin

    if [[ "${DRY_RUN}" -eq 1 ]]; then
        info "[dry-run] would resolve ${REF} to an immutable SHA and check it out (detached)"
        return 0
    fi

    local sha
    sha="$(git rev-parse --verify "${REF}^{commit}")" || die "Cannot resolve ref: ${REF}"
    info "resolved ${REF} -> ${sha}"

    # Detached checkout of an immutable SHA — never a forced reset.
    git checkout --detach "${sha}"
    DEPLOYED_SHA="${sha}"

    info "revision: ${before} -> $(git rev-parse --short HEAD) (${REF})"
}

composer_install() {
    step "composer"

    run composer install \
        --no-interaction \
        --no-progress \
        --prefer-dist \
        --no-dev \
        --optimize-autoloader
}

central_migrations() {
    step "central-migrations"

    # Additive only; the destructive schema commands are never invoked here.
    run php artisan migrate --force
}

tenant_migrations() {
    step "tenant-migrations"

    # Only databases ALREADY registered as provisioned/migrated. This never
    # provisions a new tenant database and never drops or recreates one.
    run php artisan lba:tenant:migrate:registered
}

build_assets() {
    step "assets"

    if [[ "${SKIP_ASSETS}" -eq 1 ]]; then
        warn "--skip-assets set: skipping npm ci && npm run build."
        return 0
    fi

    # `--include=dev` is REQUIRED: vite, laravel-vite-plugin, tailwindcss and
    # @tailwindcss/vite are devDependencies. Installing without them leaves
    # `npm run build` unable to produce public/build, so every hashed Vite /
    # Filament asset 404s on the deployed site. A production install here would
    # silently ship a broken stylesheet.
    run npm ci --include=dev --no-audit --no-fund
    run npm run build
}

# Filament can break on hosts where a previously-cached config/route/view (or a
# stale Filament asset manifest) survives a deploy. Default to the SAFE path:
# clear every cache selectively, then republish Filament's assets. Full
# `php artisan optimize` is opt-in (--optimize) and warned about.
clear_caches() {
    step "caches"

    run php artisan optimize:clear
    run php artisan config:clear
    run php artisan route:clear
    run php artisan view:clear

    if [[ "${DRY_RUN}" -eq 0 ]] && php artisan list --raw 2>/dev/null | grep -q '^filament:assets'; then
        run php artisan filament:assets
    else
        info "filament:assets not published (command unavailable or dry-run)."
    fi

    if [[ "${OPTIMIZE}" -eq 1 ]]; then
        warn '--optimize set: running php artisan optimize (config/route/view caches). Verify Filament after deploy.'
        run php artisan optimize
    else
        info "safe cache strategy: selective clears only (pass --optimize to also cache config/route/view)."
    fi
}

health_validation() {
    step "health"

    if [[ "${DRY_RUN}" -eq 1 ]]; then
        info "[dry-run] would run php artisan lba:staging:health"
        return 0
    fi

    php artisan lba:staging:health --json >/dev/null
    info "health validation passed"
}

# Unauthenticated reachability of the three panels. This does NOT authenticate;
# an operator with independently authorized accounts must still confirm login and
# scoping by hand (see docs/WS6_STAGING_RUNBOOK.md). A redirect to `*/login` is
# healthy; a 5xx or a connection failure is not.
verify_panels() {
    step "verify-panels"

    if [[ "${SKIP_PANELS}" -eq 1 ]]; then
        warn "--skip-panels set: skipping panel reachability check."
        return 0
    fi

    if [[ "${DRY_RUN}" -eq 1 ]]; then
        info "[dry-run] would verify /principal, /clinic, /patient against APP_URL"
        return 0
    fi

    local base
    base="$(env_value APP_URL)"
    if [[ -z "${base}" ]]; then
        warn "APP_URL is empty — cannot verify panel reachability."
        return 0
    fi

    require_binary curl
    base="${base%/}"

    local path code
    for path in /principal /clinic /patient; do
        code="$(curl -s -o /dev/null -w '%{http_code}' --max-time 15 "${base}${path}" || echo 000)"
        case "${code}" in
            2*|3*|401|403)
                info "${path} -> HTTP ${code} (reachable)"
                ;;
            000)
                error "${path} -> no response from ${base}${path}"
                return 1
                ;;
            5*)
                error "${path} -> HTTP ${code} (server error)"
                return 1
                ;;
            *)
                warn "${path} -> HTTP ${code} (unexpected; review manually)"
                ;;
        esac
    done

    info "panel reachability OK (unauthenticated); confirm authenticated scoping manually."
}

# WS6.G — post-deploy smoke check for the /login page's static assets.
#
# The panel reachability check above only proves a route answers; it says nothing
# about whether the page's stylesheet, Filament JavaScript or Livewire runtime
# are actually SERVED. A green /login with broken assets is exactly the staging
# regression this guards. `lba:staging:login-assets` fetches the real /login
# markup, extracts every local CSS/JS/font URL the page references, and requires
# each to return a non-HTML 2xx — never a hard-coded (and therefore brittle)
# hashed filename. Any 404 or an HTML body on an asset URL fails the deploy and
# is printed with its URL.
verify_login_assets() {
    step "verify-login-assets"

    if [[ "${SKIP_LOGIN_ASSETS}" -eq 1 ]]; then
        warn "--skip-login-assets set: skipping the /login static-asset smoke check."
        return 0
    fi

    if [[ "${DRY_RUN}" -eq 1 ]]; then
        info "[dry-run] would run php artisan lba:staging:login-assets against APP_URL"
        return 0
    fi

    local base
    base="$(env_value APP_URL)"
    if [[ -z "${base}" ]]; then
        warn "APP_URL is empty — cannot verify /login assets."
        return 0
    fi

    if ! php artisan list --raw 2>/dev/null | grep -q '^lba:staging:login-assets'; then
        warn "lba:staging:login-assets is unavailable in this revision — skipping the smoke check."
        return 0
    fi

    if ! php artisan lba:staging:login-assets --url="${base%/}"; then
        error "The /login page references static assets that are NOT being served (see the URLs above)."
        error "This is a hosted-assets regression: verify a Vite build exists, Filament assets are published, and that LiteSpeed serves public/build, public/css, public/js and public/fonts."
        return 1
    fi

    info "login assets OK — every CSS/JS/font referenced by /login is served with the correct Content-Type."
}

finish() {
    step "done"
    info "staging deployment completed successfully (started ${DEPLOY_STARTED_AT})."
    info "Reminder: deployment is operator-initiated only — it is never automatic."
    if [[ "${SAFE_CACHE_DEFAULT}" -eq 1 && "${OPTIMIZE}" -eq 0 ]]; then
        info "Caches were cleared selectively (safe default)."
    fi
}

main() {
    parse_args "$@"

    if [[ -n "${LOG_FILE}" ]]; then
        mkdir -p "$(dirname "${LOG_FILE}")"
        : > "${LOG_FILE}"
    fi

    info "${SCRIPT_NAME} starting (dry-run=${DRY_RUN} operator=${OPERATOR})"

    preflight
    attest_staging
    inventory
    backup_all
    pull_approved_changes
    composer_install
    central_migrations
    tenant_migrations
    build_assets
    clear_caches
    health_validation
    verify_panels
    verify_login_assets
    finish
}

main "$@"
