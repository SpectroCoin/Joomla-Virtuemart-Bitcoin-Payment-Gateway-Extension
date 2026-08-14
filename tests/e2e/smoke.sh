#!/usr/bin/env bash
# ============================================================================
# Tier 1 smoke test — install the packaged plugin into a real Joomla and prove
# it actually installs and loads.
#
# Catches what unit tests cannot: an artifact shipped without its vendor tree, a
# manifest Joomla rejects, an extension that installs but cannot be enabled, or
# code that will not compile under the runtime PHP.
#
# Scope: VirtueMart is not installed, so this does not exercise checkout. It
# proves the package is a valid, installable, loadable Joomla extension.
# Driving a real order needs VirtueMart and belongs to Tier 2.
#
# Usage:
#   ./smoke.sh                    # package the working tree the way release.yml does
#   ./smoke.sh --artifact x.zip   # test an arbitrary zip (e.g. a CI artifact)
#   ./smoke.sh --keep             # leave the stack running for inspection
# ============================================================================
set -euo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT="$(cd "$HERE/../.." && pwd)"
ELEMENT="spectrocoin"
ARTIFACT=""
KEEP=0

while [ $# -gt 0 ]; do
  case "$1" in
    --artifact) ARTIFACT="${2:-}"; shift 2 ;;
    --keep)     KEEP=1; shift ;;
    *) echo "unknown argument: $1" >&2; exit 2 ;;
  esac
done

say()  { printf '\n\033[1m== %s\033[0m\n' "$*"; }
pass() { printf '  \033[32mPASS\033[0m  %s\n' "$*"; }
fail() { printf '  \033[31mFAIL\033[0m  %s\n' "$*"; FAILED=$((FAILED+1)); }
FAILED=0

WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT

# --------------------------------------------------------------------------
# 1. Obtain the artifact a merchant would install.
# --------------------------------------------------------------------------
say "Packaging artifact"
if [ -n "$ARTIFACT" ]; then
  cp "$ARTIFACT" "$WORK/plugin.zip"
  echo "  using supplied artifact $ARTIFACT"
else
  # Mirror release.yml exactly.
  mkdir -p "$WORK/build"
  rsync -a --exclude='spectrocoin' --exclude='.git*' --exclude='.github*' \
        --exclude='README.txt' --exclude='README.md' --exclude='readme.md' \
        --exclude='changelog.md' --exclude='.gitignore' --exclude='tests' \
        "$ROOT/" "$WORK/build/"
  ( cd "$WORK/build" && zip -qr "$WORK/plugin.zip" . )
  echo "  built from working tree ($(find "$WORK/build" -type f | wc -l | tr -d ' ') files)"
fi

unzip -qo "$WORK/plugin.zip" -d "$WORK/inspect"
[ -f "$WORK/inspect/$ELEMENT.xml" ] \
  && pass "artifact contains the Joomla manifest" \
  || fail "artifact is missing $ELEMENT.xml - Joomla cannot install it"

grep -q 'group="vmpayment"' "$WORK/inspect/$ELEMENT.xml" \
  && pass "manifest declares the vmpayment plugin group" \
  || fail "manifest does not declare group=vmpayment"

[ -f "$WORK/inspect/vendor/autoload.php" ] \
  && pass "artifact contains vendor/autoload.php" \
  || fail "artifact has NO vendor/autoload.php - the plugin cannot run"

guzzle=$(find "$WORK/inspect/vendor/guzzlehttp/guzzle/src" -name '*.php' 2>/dev/null | wc -l | tr -d ' ')
if [ -f "$WORK/inspect/vendor/guzzlehttp/guzzle/src/Client.php" ] && [ "$guzzle" -gt 10 ]; then
  pass "artifact contains the HTTP client source ($guzzle files)"
else
  fail "artifact ships an EMPTY or partial guzzle tree ($guzzle php files)"
fi

# --------------------------------------------------------------------------
# 2. Real Joomla.
# --------------------------------------------------------------------------
say "Starting Joomla"
cd "$HERE"
docker compose down -v >/dev/null 2>&1 || true
docker compose up -d --wait >/dev/null 2>&1
jm() { docker compose exec -T joomla "$@"; }
q()  { docker compose exec -T db mariadb -uroot -proot -N -B joomla -e "$1" 2>/dev/null | tr -d '\r'; }

# The image's entrypoint installs Joomla from its JOOMLA_* environment when it
# can; only drive the CLI installer if that has not happened.
jm sh -c '[ -f /var/www/html/configuration.php ] || cd /var/www/html && [ -d installation ] && php installation/joomla.php install \
    --site-name=smoke --admin-user="Smoke Admin" --admin-username=admin \
    --admin-password=smokesmoke123   # Joomla requires >= 12 characters --admin-email=smoke@example.com \
    --db-type=mysqli --db-host=db --db-user=joomla --db-pass=joomla \
    --db-name=joomla --db-prefix=smk_ --db-encryption=0 2>&1 || true' \
  > "$WORK/jinstall.log" 2>&1 || true

tables=$(docker compose exec -T db mariadb -uroot -proot -N -B \
          -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='joomla';" 2>/dev/null | tr -d ' \r')
if [ "${tables:-0}" -gt 0 ]; then
  pass "Joomla installed ($tables tables)"
else
  fail "Joomla did not install:"; sed 's/^/        /' "$WORK/jinstall.log" | grep -v '^\s*$' | head -6
fi

# Read the prefix Joomla actually used; the image and the CLI installer pick
# different ones, so hardcoding it silently queries the wrong tables.
PFX=$(jm sh -c "sed -n \"s/.*dbprefix[^']*'\\([^']*\\)'.*/\\1/p\" /var/www/html/configuration.php" | tr -d ' \r')
[ -n "$PFX" ] && pass "table prefix is ${PFX}" || fail "could not read the table prefix"

# --------------------------------------------------------------------------
# 3. Install the artifact exactly as a merchant would.
# --------------------------------------------------------------------------
say "Installing the plugin"
docker compose cp "$WORK/plugin.zip" joomla:/tmp/plugin.zip >/dev/null
# Install from the ZIP, not an extracted folder: Joomla's CLI rejects a folder
# here with a bare "Unable to install extension" and no further detail.
jm sh -c 'cd /var/www/html && php cli/joomla.php extension:install --path=/tmp/plugin.zip 2>&1' \
  > "$WORK/install.log" 2>&1 || true

# The database is the source of truth: CLI wording varies between Joomla versions.
row=$(q "SELECT COUNT(*) FROM ${PFX}extensions WHERE element='$ELEMENT' AND folder='vmpayment' AND type='plugin';" 2>/dev/null || echo 0)
if [ "${row:-0}" -ge 1 ]; then
  pass "plugin registered in #__extensions as a vmpayment plugin"
else
  fail "plugin NOT registered. Installer said:"; sed 's/^/        /' "$WORK/install.log" | grep -v '^\s*$' | head -8
fi

# --------------------------------------------------------------------------
# 4. Assertions that only a real install can make.
# --------------------------------------------------------------------------
say "Verifying inside the running site"

files=$(jm sh -c "ls /var/www/html/plugins/vmpayment/$ELEMENT/ 2>/dev/null | wc -l" | tr -d ' \r')
[ "${files:-0}" -gt 0 ] \
  && pass "plugin files landed in plugins/vmpayment/$ELEMENT ($files entries)" \
  || fail "plugin files are NOT in plugins/vmpayment/$ELEMENT"

q "UPDATE ${PFX}extensions SET enabled=1 WHERE element='$ELEMENT' AND folder='vmpayment';" >/dev/null 2>&1 || true
enabled=$(q "SELECT enabled FROM ${PFX}extensions WHERE element='$ELEMENT' AND folder='vmpayment';")
[ "$enabled" = "1" ] && pass "plugin can be enabled" || fail "plugin could not be enabled"

if jm php -r "
  require '/var/www/html/plugins/vmpayment/$ELEMENT/vendor/autoload.php';
  exit(class_exists('GuzzleHttp\\\\Client') ? 0 : 1);" >/dev/null 2>&1; then
  pass "GuzzleHttp\\Client resolves via autoload"
else
  fail "GuzzleHttp\\Client does NOT resolve - vendor tree is absent or stale"
fi

# NB: php -l prints "No syntax errors detected", so counting lines containing
# "error" counts the successes. Drop those first.
bad=$(jm sh -c "find /var/www/html/plugins/vmpayment/$ELEMENT -name vendor -prune -o -name '*.php' -print0 \
                | xargs -0 -n1 php -l 2>&1 | grep -v 'No syntax errors detected' \
                | grep -ciE 'parse error|fatal error' || true" | tr -d ' \r')
[ "${bad:-0}" -eq 0 ] \
  && pass "all shipped PHP compiles under the runtime PHP version" \
  || fail "$bad file(s) fail to compile"

# The status enum carries the callback contract; prove it loads and still
# understands the statuses the API sends.
if jm php -r "
  define('_JEXEC', 1);
  require '/var/www/html/plugins/vmpayment/$ELEMENT/lib/SCMerchantClient/Enum/OrderStatus.php';
  \$e = 'SpectroCoin\\\\SCMerchantClient\\\\Enum\\\\OrderStatus';
  foreach (['PAID','CANCELLED','TEST_PAID','LATE_CRYPTO_PAYMENT'] as \$s) {
    if (\$e::normalize(\$s)->value !== \$s) exit(1);
  }
  exit(0);" >/dev/null 2>&1; then
  pass "order-status enum loads and normalises the wire statuses"
else
  fail "order-status enum failed to load or reject a wire status"
fi

# --------------------------------------------------------------------------
# 5. Nothing may have been logged as a fatal.
# --------------------------------------------------------------------------
say "PHP error log"
log=$(jm sh -c 'cat /var/www/html/administrator/logs/*.php /var/log/apache2/error.log 2>/dev/null || true')
ours=$(printf '%s\n' "$log" | grep -iE "fatal|uncaught|parse error" | grep -iE "spectrocoin|guzzle|class .* not found" || true)
[ -z "$ours" ] && pass "no fatals attributable to the plugin" \
  || { fail "fatals in the log:"; printf '%s\n' "$ours" | head -10; }

if [ "$KEEP" -eq 1 ]; then
  echo -e "\nstack left running: http://localhost:8084/administrator (admin/smokesmoke123)"
else
  docker compose down -v >/dev/null 2>&1 || true
fi

echo
[ "$FAILED" -eq 0 ] && echo "smoke test PASSED" || echo "smoke test FAILED ($FAILED check(s))"
exit $([ "$FAILED" -eq 0 ] && echo 0 || echo 1)
