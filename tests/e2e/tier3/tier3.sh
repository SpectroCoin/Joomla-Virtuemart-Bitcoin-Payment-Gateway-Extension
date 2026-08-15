#!/usr/bin/env bash
# ============================================================================
# Tier 3 end-to-end test — a real shopper, in a real browser, through a real
# Joomla + VirtueMart checkout.
#
# Tier 2 proves the callback contract: it seeds the SpectroCoin order directly
# on the stub and delivers every status onto a VirtueMart order it inserted by
# hand. That is stated as a scope gap in tier2.sh's own header - the order VM
# actually sends is assembled inside plgVmConfirmedOrder from a VirtueMartCart
# built up over several checkout steps in the shopper's session, and nothing
# about that path is exercised without a browser. This is the layer that
# catches "the plugin is invisible at checkout" - a real defect class tier1 and
# tier2 cannot see no matter how thorough they are.
#
# So this one buys VirtueMart's own sample product: add to cart, fill in
# shopper details, pick a shipment method, pick SpectroCoin, confirm the order,
# and follow the redirect to the payment page. The SpectroCoin API is the same
# stub tier2 uses.
#
# Usage:
#   ./tier3.sh          # run the full journey
#   ./tier3.sh --keep   # leave the stack running for inspection
#
# TIER3_DISABLE_PLUGIN=1 ./tier3.sh   disables *only* the SpectroCoin payment
#   plugin right after it would normally be enabled, changing nothing else.
#   Used to prove the checkout assertions actually depend on the plugin: with
#   it set, the SpectroCoin-specific assertions must fail while the stock
#   payment method control still passes.
#
# Screenshots of any failing step land in ./artifacts/.
# ============================================================================
set -euo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT="$(cd "$HERE/../../.." && pwd)"
VM_PACKAGE="${VM_PACKAGE:-$HOME/spectrocoin-plugin-audit/.artifacts/com_virtuemart.4.6.4.11226_package.zip}"
VM_URL="https://dev.virtuemart.net/attachments/download/1406/com_virtuemart.4.6.4.11226_package_or_extract.zip"
TIER3_DISABLE_PLUGIN="${TIER3_DISABLE_PLUGIN:-0}"
KEEP=0

while [ $# -gt 0 ]; do
  case "$1" in
    --keep) KEEP=1; shift ;;
    *) echo "unknown argument: $1" >&2; exit 2 ;;
  esac
done

say()  { printf '\n\033[1m== %s\033[0m\n' "$*"; }
pass() { printf '  \033[32mPASS\033[0m  %s\n' "$*"; }
fail() { printf '  \033[31mFAIL\033[0m  %s\n' "$*"; FAILED=$((FAILED+1)); }
FAILED=0

WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT

cd "$HERE"
rm -rf artifacts && mkdir -p artifacts

# --------------------------------------------------------------------------
# 1. Certificates and the VirtueMart package.
# --------------------------------------------------------------------------
say "Preparing certificates and VirtueMart"
rm -rf .certs && mkdir -p .certs
openssl req -x509 -newkey rsa:2048 -nodes -days 3650 \
  -keyout .certs/ca.key -out .certs/ca.crt \
  -subj "/CN=SpectroCoin Tier3 Test CA" >/dev/null 2>&1
openssl req -newkey rsa:2048 -nodes -keyout .certs/server.key -out .certs/server.csr \
  -subj "/CN=spectrocoin.com" >/dev/null 2>&1
printf 'subjectAltName=DNS:spectrocoin.com\n' > .certs/ext
openssl x509 -req -in .certs/server.csr -CA .certs/ca.crt -CAkey .certs/ca.key \
  -CAcreateserial -out .certs/server.crt -days 3650 -extfile .certs/ext >/dev/null 2>&1
chmod 644 .certs/*
[ -s .certs/server.crt ] && pass "issued a certificate for spectrocoin.com" \
  || fail "certificate generation failed"

if [ ! -s "$VM_PACKAGE" ]; then
  mkdir -p "$(dirname "$VM_PACKAGE")"
  curl -fsSL --max-time 300 -o "$VM_PACKAGE" "$VM_URL" || true
fi
if [ -s "$VM_PACKAGE" ]; then
  pass "VirtueMart package available ($(( $(wc -c < "$VM_PACKAGE") / 1024 )) KB)"
else
  fail "could not obtain the VirtueMart package from $VM_URL"
fi

# --------------------------------------------------------------------------
# 2. The stack.
# --------------------------------------------------------------------------
say "Starting Joomla, the API stub and a browser"
docker compose down -v >/dev/null 2>&1 || true
docker compose up -d --build --wait >/dev/null 2>&1

jm()   { docker compose exec -T joomla "$@"; }
stub() { docker compose exec -T spectrocoin "$@"; }
pw()   { docker compose exec -T playwright "$@"; }
q()    { docker compose exec -T db mariadb -uroot -proot -N -B joomla -e "$1" 2>/dev/null | tr -d '\r'; }

jm sh -c 'cat /certs/ca.crt >> /etc/ssl/certs/ca-certificates.crt' >/dev/null 2>&1 || true
PFX=$(jm sh -c "sed -n \"s/.*dbprefix[^']*'\\([^']*\\)'.*/\\1/p\" /var/www/html/configuration.php" | tr -d ' \r')
[ -n "$PFX" ] && pass "Joomla installed, table prefix ${PFX}" || fail "Joomla is not installed"

if jm sh -c 'curl -fsS -o /dev/null https://spectrocoin.com/__test/requests' >/dev/null 2>&1; then
  pass "the shop trusts the stub's certificate"
else
  fail "the shop cannot reach the stub over TLS - checkout will fail with cURL error 60"
fi

# --------------------------------------------------------------------------
# 3. VirtueMart, with sample data - a real category, a real product, and
#    VirtueMart's own stock payment/shipment methods.
# --------------------------------------------------------------------------
say "Installing VirtueMart with sample data"
docker compose cp "$VM_PACKAGE" joomla:/tmp/vm.zip >/dev/null 2>&1
jm sh -c 'cd /tmp && rm -rf vmx && mkdir vmx \
    && php -r "\$z=new ZipArchive(); \$z->open(\"/tmp/vm.zip\"); \$z->extractTo(\"/tmp/vmx\"); \$z->close();"' \
  >/dev/null 2>&1
jm sh -c 'cd /var/www/html && php cli/joomla.php extension:install --path=/tmp/vm.zip' >/dev/null 2>&1 || true
jm sh -c 'cd /var/www/html && php cli/joomla.php extension:install --path=/tmp/vmx/com_virtuemart.4.6.4.11226.zip' \
  >/dev/null 2>&1 || true
# The sample-data installer unconditionally requires com_virtuemart_allinone
# (it calls into it for the PDF/menu helpers regardless of whether "sample
# data" was requested) - without it, clicking "install with sample data" is a
# hard PHP fatal, not a degraded result.
jm sh -c 'cd /var/www/html && php cli/joomla.php extension:install --path=/tmp/vmx/com_virtuemart.4.6.4.11226_ext_aio.zip' \
  >/dev/null 2>&1 || true

jm sh -c '[ -d /var/www/html/administrator/components/com_virtuemart ] && [ -d /var/www/html/administrator/components/com_virtuemart_allinone ]' \
  && pass "VirtueMart component files installed" \
  || fail "VirtueMart files were not installed"

docker compose cp vm-finish-install.sh joomla:/tmp/vm-finish-install.sh >/dev/null 2>&1
jm sh /tmp/vm-finish-install.sh > "$WORK/vminstall.log" 2>&1 || true
[ -n "$(q "SHOW TABLES LIKE '${PFX}virtuemart_configs';")" ] \
  && pass "VirtueMart install completed (config table present)" \
  || { fail "VirtueMart install did not complete:"; tail -6 "$WORK/vminstall.log" | sed 's/^/        /'; }

# Not every sample product is a fair pick: some (like the "product pattern"
# showcase items) ship without a price or category row, and the clothing
# demo products carry customfields that force a "choose a variant" step
# before Add to Cart even enables - a real shopper journey, but a second UI
# this test would then have to drive for no benefit to what it is checking.
# A product joined to a price and a category, with no customfields at all,
# is the plainest thing a shopper could actually buy.
read -r PRODUCT_ID CATEGORY_ID <<EOF
$(q "SELECT p.virtuemart_product_id, c.virtuemart_category_id
     FROM ${PFX}virtuemart_products p
     JOIN ${PFX}virtuemart_product_prices pr ON pr.virtuemart_product_id = p.virtuemart_product_id
     JOIN ${PFX}virtuemart_product_categories c ON c.virtuemart_product_id = p.virtuemart_product_id
     LEFT JOIN ${PFX}virtuemart_product_customfields cf ON cf.virtuemart_product_id = p.virtuemart_product_id
     WHERE p.published = 1 AND pr.product_price > 1 AND cf.virtuemart_product_id IS NULL
     ORDER BY p.virtuemart_product_id LIMIT 1;")
EOF
if [ -n "$PRODUCT_ID" ] && [ -n "$CATEGORY_ID" ]; then
  pass "sample catalogue has a product to buy (#$PRODUCT_ID)"
else
  fail "sample data did not produce a published product:"; tail -6 "$WORK/vminstall.log" | sed 's/^/        /'
fi

# VirtueMart keeps translated columns (names, descriptions, slugs) in
# per-language tables it only creates when its settings form is saved with an
# active language - a path unreachable headlessly. Without them the storefront
# and the callback both die the moment they touch a product, category, payment
# method or vendor.
docker compose cp vm-language-tables.php joomla:/tmp/vm-language-tables.php >/dev/null 2>&1
jm php /tmp/vm-language-tables.php > "$WORK/langtables.log" 2>&1 || true
langtables=$(sed -n 's/^LANGTABLES=//p' "$WORK/langtables.log")
if [ "${langtables:-0}" -ge 2 ]; then
  pass "VirtueMart language tables created ($langtables)"
else
  fail "language tables were not created:"; tail -4 "$WORK/langtables.log" | sed 's/^/        /'
fi

# --------------------------------------------------------------------------
# 4. The plugin.
# --------------------------------------------------------------------------
say "Installing and configuring the plugin"
BUILD="$WORK/spectrocoin"
mkdir -p "$BUILD"
( cd "$ROOT" && find . -maxdepth 1 -not -path '.' -not -path './.git' \
    -not -path './.github' -not -path './tests' -not -path './.gitignore' \
    -exec cp -r {} "$BUILD/" \; )
( cd "$BUILD" && composer install --no-dev --prefer-dist --optimize-autoloader \
    --no-interaction -q 2>/dev/null || php "$ROOT/../composer.phar" install \
    --no-dev --prefer-dist --optimize-autoloader --no-interaction -q )
( cd "$WORK" && zip -qr plugin.zip spectrocoin )

docker compose cp "$WORK/plugin.zip" joomla:/tmp/plugin.zip >/dev/null 2>&1
jm sh -c 'cd /var/www/html && php cli/joomla.php extension:install --path=/tmp/plugin.zip' \
  > "$WORK/plugin.log" 2>&1 || true
PJ=$(q "SELECT extension_id FROM ${PFX}extensions WHERE element='spectrocoin' AND folder='vmpayment';")
if [ -n "$PJ" ]; then
  pass "plugin installed as a vmpayment extension"
else
  fail "plugin did not install:"; tail -4 "$WORK/plugin.log" | sed 's/^/        /'
fi
q "UPDATE ${PFX}extensions SET enabled=1 WHERE element='spectrocoin' AND folder='vmpayment';" >/dev/null 2>&1
[ "$(q "SELECT enabled FROM ${PFX}extensions WHERE element='spectrocoin' AND folder='vmpayment';")" = "1" ] \
  && pass "plugin enabled" || fail "plugin could not be enabled"

# Every vmpayment/vmshipment plugin keeps its own per-order settings table
# (`#__virtuemart_<type>_plg_<element>`), normally created the first time a
# merchant installs the plugin through VirtueMart's own admin UI - a path
# `extension:install` on the CLI does not run. Without it, checkout itself
# 500s the moment a plugin's onCheckAutomaticSelected/getDataByOrderId touches
# the table, which looks nothing like a plugin problem in the response.
q "CREATE TABLE IF NOT EXISTS ${PFX}virtuemart_shipment_plg_weight_countries (
     id int(1) UNSIGNED NOT NULL AUTO_INCREMENT, virtuemart_order_id int(11) UNSIGNED,
     order_number char(32), virtuemart_shipmentmethod_id mediumint(1) UNSIGNED,
     shipment_name varchar(5000), order_weight decimal(10,4),
     shipment_weight_unit char(3) DEFAULT 'KG', shipment_cost decimal(10,2),
     shipment_package_fee decimal(10,2), tax_id smallint(1),
     created_on datetime, created_by int(11) NOT NULL DEFAULT '0',
     modified_on datetime, modified_by int(11) NOT NULL DEFAULT '0',
     locked_on datetime, locked_by int(11) NOT NULL DEFAULT '0',
     PRIMARY KEY (id)
   ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;" >/dev/null 2>&1
q "CREATE TABLE IF NOT EXISTS ${PFX}virtuemart_payment_plg_standard (
     id int(1) UNSIGNED NOT NULL AUTO_INCREMENT, virtuemart_order_id int(1) UNSIGNED,
     order_number char(64), virtuemart_paymentmethod_id mediumint(1) UNSIGNED,
     payment_name varchar(5000), payment_order_total decimal(15,5) NOT NULL DEFAULT '0.00000',
     payment_currency char(3), email_currency char(3),
     cost_per_transaction decimal(10,2), cost_min_transaction decimal(10,2),
     cost_percent_total decimal(10,2), tax_id smallint(1),
     created_on datetime, created_by int(11) NOT NULL DEFAULT '0',
     modified_on datetime, modified_by int(11) NOT NULL DEFAULT '0',
     locked_on datetime, locked_by int(11) NOT NULL DEFAULT '0',
     PRIMARY KEY (id)
   ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;" >/dev/null 2>&1
q "CREATE TABLE IF NOT EXISTS ${PFX}virtuemart_payment_plg_spectrocoin (
     id int(1) UNSIGNED NOT NULL AUTO_INCREMENT, virtuemart_order_id int(1) UNSIGNED,
     order_number char(64), virtuemart_paymentmethod_id mediumint(1) UNSIGNED,
     payment_name varchar(5000), payment_order_total decimal(15,5) NOT NULL DEFAULT '0.00000',
     payment_currency char(3), logo varchar(5000),
     created_on datetime, created_by int(11) NOT NULL DEFAULT '0',
     modified_on datetime, modified_by int(11) NOT NULL DEFAULT '0',
     locked_on datetime, locked_by int(11) NOT NULL DEFAULT '0',
     PRIMARY KEY (id)
   ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;" >/dev/null 2>&1
pgtables=$(q "SHOW TABLES LIKE '${PFX}virtuemart_%_plg_%';" | wc -l | tr -d ' ')
[ "${pgtables:-0}" -ge 3 ] && pass "plugin settings tables created ($pgtables)" \
                           || fail "plugin settings tables missing - checkout will 500"

# VirtueMart's sample-data installer registers "standard" (Cash on delivery)
# and "weight_countries" as Joomla extensions but leaves them disabled - and a
# disabled vmpayment/vmshipment plugin is never even instantiated by Joomla's
# own plugin dispatcher, so its method list comes back empty regardless of
# what rows exist in virtuemart_paymentmethods/shipmentmethods. Without this,
# checkout shows "no shipment/payment method" for everyone, ours included.
q "UPDATE ${PFX}extensions SET enabled=1 WHERE folder IN ('vmshipment','vmpayment') AND element IN ('weight_countries','standard');" >/dev/null 2>&1

CURRENCY_ID=$(q "SELECT product_currency FROM ${PFX}virtuemart_product_prices WHERE virtuemart_product_id='$PRODUCT_ID' LIMIT 1;")
CURRENCY_ID="${CURRENCY_ID:-47}"
VENDOR_ID=$(q "SELECT virtuemart_vendor_id FROM ${PFX}virtuemart_products WHERE virtuemart_product_id='$PRODUCT_ID';")
VENDOR_ID="${VENDOR_ID:-1}"

q "SET SESSION sql_mode='';
   INSERT INTO ${PFX}virtuemart_paymentmethods
     (virtuemart_vendor_id, payment_jplugin_id, payment_element, payment_params,
      currency_id, shared, ordering, published, created_on, created_by)
   VALUES ($VENDOR_ID, $PJ, 'spectrocoin',
     'project_id=\"tier3-project\"|client_id=\"tier3-client\"|client_secret=\"tier3-secret\"|new_status=\"P\"|pending_status=\"U\"|paid_status=\"C\"|failed_status=\"X\"|expired_status=\"D\"|',
     $CURRENCY_ID, 0, 1, 1, NOW(), 0);" >/dev/null 2>&1
PM=$(q "SELECT virtuemart_paymentmethod_id FROM ${PFX}virtuemart_paymentmethods WHERE payment_element='spectrocoin' ORDER BY virtuemart_paymentmethod_id DESC LIMIT 1;")
q "INSERT IGNORE INTO ${PFX}virtuemart_paymentmethods_en_gb
     (virtuemart_paymentmethod_id, payment_name, payment_desc, slug)
   VALUES ($PM,'SpectroCoin','','spectrocoin');" >/dev/null 2>&1
[ -n "$PM" ] && pass "payment method configured (id $PM, currency $CURRENCY_ID)" \
             || fail "payment method could not be created"

# The decisive control for tier3.sh's checkout assertions: a stock VirtueMart
# payment method, left enabled the way a merchant configures one. If the
# payment step is empty for every method, that is a fixture problem, not a
# finding about our plugin - "Cash on delivery" has to show up too, or "ours
# is missing" proves nothing.
#
# VirtueMart ships a "standard" (Cash on delivery / manual) vmpayment plugin
# and a "weight_countries" vmshipment plugin as core extensions, and the
# sample-data installer is meant to wire both up automatically - but that
# installer shares one array by reference between the two blocks (a real bug
# in VirtueMart 4.6.4's own updatesmigration model) and the payment method
# silently fails to save more often than not. Configuring it here directly,
# the same way the SpectroCoin method above is configured, is what actually
# reproduces "a merchant enabled a stock payment method" reliably.
SHIP_PLG=$(q "SELECT extension_id FROM ${PFX}extensions WHERE element='weight_countries' AND folder='vmshipment';")
if [ -z "$(q "SELECT virtuemart_shipmentmethod_id FROM ${PFX}virtuemart_shipmentmethods WHERE shipment_element='weight_countries' LIMIT 1;")" ] && [ -n "$SHIP_PLG" ]; then
  q "SET SESSION sql_mode='';
     INSERT INTO ${PFX}virtuemart_shipmentmethods
       (virtuemart_vendor_id, shipment_jplugin_id, shipment_element, shipment_params,
        currency_id, shared, ordering, published, created_on, created_by)
     VALUES ($VENDOR_ID, $SHIP_PLG, 'weight_countries', 'cost=\"0\"|free_shipment=\"\"|',
       $CURRENCY_ID, 0, 1, 1, NOW(), 0);" >/dev/null 2>&1
  SHIPM=$(q "SELECT virtuemart_shipmentmethod_id FROM ${PFX}virtuemart_shipmentmethods WHERE shipment_element='weight_countries' ORDER BY virtuemart_shipmentmethod_id DESC LIMIT 1;")
  q "INSERT IGNORE INTO ${PFX}virtuemart_shipmentmethods_en_gb
       (virtuemart_shipmentmethod_id, shipment_name, shipment_desc, slug)
     VALUES ($SHIPM,'Self pick-up','','self-pick-up');" >/dev/null 2>&1
fi

PAY_PLG=$(q "SELECT extension_id FROM ${PFX}extensions WHERE element='standard' AND folder='vmpayment';")
if [ -z "$(q "SELECT virtuemart_paymentmethod_id FROM ${PFX}virtuemart_paymentmethods WHERE payment_element='standard' LIMIT 1;")" ] && [ -n "$PAY_PLG" ]; then
  q "SET SESSION sql_mode='';
     INSERT INTO ${PFX}virtuemart_paymentmethods
       (virtuemart_vendor_id, payment_jplugin_id, payment_element, payment_params,
        currency_id, shared, ordering, published, created_on, created_by)
     VALUES ($VENDOR_ID, $PAY_PLG, 'standard',
       'payment_currency=\"0\"|status_pending=\"U\"|send_invoice_on_order_null=\"1\"|cost_per_transaction=\"0\"|cost_percent_total=\"0\"|tax_id=\"0\"|',
       $CURRENCY_ID, 0, 1, 1, NOW(), 0);" >/dev/null 2>&1
  CODPM=$(q "SELECT virtuemart_paymentmethod_id FROM ${PFX}virtuemart_paymentmethods WHERE payment_element='standard' ORDER BY virtuemart_paymentmethod_id DESC LIMIT 1;")
  q "INSERT IGNORE INTO ${PFX}virtuemart_paymentmethods_en_gb
       (virtuemart_paymentmethod_id, payment_name, payment_desc, slug)
     VALUES ($CODPM,'Cash on delivery','','cash-on-delivery');" >/dev/null 2>&1
fi

cod=$(q "SELECT COUNT(*) FROM ${PFX}virtuemart_paymentmethods WHERE payment_element='standard' AND published=1;")
ship=$(q "SELECT COUNT(*) FROM ${PFX}virtuemart_shipmentmethods WHERE shipment_element='weight_countries' AND published=1;")
if [ "${cod:-0}" -ge 1 ] && [ "${ship:-0}" -ge 1 ]; then
  pass "stock payment method (Cash on delivery) and a shipment method are enabled"
else
  fail "stock payment or shipment method is missing - cod=$cod ship=$ship"
fi

if [ "$TIER3_DISABLE_PLUGIN" = "1" ]; then
  say "Negative test: disabling ONLY the SpectroCoin plugin"
  q "UPDATE ${PFX}extensions SET enabled=0 WHERE element='spectrocoin' AND folder='vmpayment';" >/dev/null 2>&1
  q "UPDATE ${PFX}virtuemart_paymentmethods SET published=0 WHERE payment_element='spectrocoin';" >/dev/null 2>&1
  [ "$(q "SELECT enabled FROM ${PFX}extensions WHERE element='spectrocoin' AND folder='vmpayment';")" = "0" ] \
    && pass "SpectroCoin plugin disabled for the negative test (nothing else changed)" \
    || fail "could not disable the SpectroCoin plugin for the negative test"
fi

stub curl -fsS -X POST http://localhost/__test/reset >/dev/null 2>&1

# --------------------------------------------------------------------------
# 5. Walk a shopper through checkout.
# --------------------------------------------------------------------------
say "Walking a shopper through checkout"
PRODUCT_URL="http://shop.test/index.php?option=com_virtuemart&view=productdetails&virtuemart_product_id=$PRODUCT_ID&virtuemart_category_id=$CATEGORY_ID"

pw sh -c 'cd /work && [ -d node_modules/playwright ] || npm --silent i playwright@1.50.0' \
  > "$WORK/npm.log" 2>&1 || true
pw sh -c 'node -e "require(\"playwright\")"' >/dev/null 2>&1 \
  && pass "browser client available" \
  || { fail "playwright module could not be installed:"; tail -4 "$WORK/npm.log" | sed 's/^/        /'; }

pw sh -c "SHOP_URL=http://shop.test PRODUCT_URL='$PRODUCT_URL' EXT_TITLE='SpectroCoin' STOCK_TITLE='Cash on delivery' node /work/checkout.mjs" \
  > "$WORK/browser.log" 2>&1 || true

# A browser run that produces no verdicts at all is a failure in itself, not a
# silent pass - and `set -o pipefail` would otherwise abort the script here.
if ! grep -aqE '^(PASS|FAIL)' "$WORK/browser.log"; then
  fail "the browser run produced no verdicts:"
  tail -12 "$WORK/browser.log" | sed 's/^/        /'
fi

grep -aE '^(PASS|FAIL|INFO)' "$WORK/browser.log" 2>/dev/null | while read -r line; do
  case "$line" in
    PASS*) printf '  \033[32mPASS\033[0m  %s\n' "${line#PASS }" ;;
    FAIL*) printf '  \033[31mFAIL\033[0m  %s\n' "${line#FAIL }" ;;
    INFO*) printf '  \033[33mNOTE\033[0m  %s\n' "${line#INFO }" ;;
  esac
done
browser_failures=$(grep -ac '^FAIL' "$WORK/browser.log" 2>/dev/null || true)
browser_failures=${browser_failures:-0}
FAILED=$((FAILED + browser_failures))
if [ "$browser_failures" -gt 0 ]; then
  echo "        --- browser log tail ---"
  tail -20 "$WORK/browser.log" | sed 's/^/        /'
fi

# --------------------------------------------------------------------------
# 6. What the shop and SpectroCoin ended up with.
# --------------------------------------------------------------------------
say "Verifying the order that resulted"
stub curl -fsS http://localhost/__test/requests > "$WORK/requests.json" 2>/dev/null

created=$(python3 - "$WORK/requests.json" <<'PYEOF'
import json,sys
for r in json.load(open(sys.argv[1])):
    if r["path"].endswith("/orders/create"):
        print(json.dumps(json.loads(r["body"] or "{}")))
        break
PYEOF
)
[ -n "$created" ] || created='{}'
field() { printf '%s' "$created" | python3 -c "import json,sys;print(json.load(sys.stdin).get('$1',''))" 2>/dev/null; }

if [ "$TIER3_DISABLE_PLUGIN" = "1" ]; then
  if [ -z "$(field orderId)" ]; then
    pass "no SpectroCoin order was created (expected - the plugin is disabled)"
  else
    fail "a SpectroCoin order was created even with the plugin disabled"
  fi
else
  if [ -n "$(field orderId)" ]; then
    pass "checkout produced a SpectroCoin order ($(field orderId))"
  else
    fail "checkout never reached SpectroCoin - no create-order request arrived"
  fi

  VM_ORDER=$(q "SELECT virtuemart_order_id FROM ${PFX}virtuemart_orders ORDER BY virtuemart_order_id DESC LIMIT 1;")
  vm_total=$(q "SELECT order_total FROM ${PFX}virtuemart_orders WHERE virtuemart_order_id='$VM_ORDER';")
  vm_status=$(q "SELECT order_status FROM ${PFX}virtuemart_orders WHERE virtuemart_order_id='$VM_ORDER';")
  vm_payment=$(q "SELECT virtuemart_paymentmethod_id FROM ${PFX}virtuemart_orders WHERE virtuemart_order_id='$VM_ORDER';")

  if [ -n "$vm_total" ] && python3 -c "
import sys
sys.exit(0 if abs(float('$(field receiveAmount)' or 'nan') - float('$vm_total')) < 0.01 else 1)" 2>/dev/null; then
    pass "the order was sent for the shop's total ($vm_total)"
  else
    fail "receiveAmount was '$(field receiveAmount)', shop's order total is '$vm_total'"
  fi

  [ "$vm_payment" = "$PM" ] \
    && pass "the shop recorded the order against the SpectroCoin payment method" \
    || fail "the shop's order payment method is '$vm_payment', expected $PM"

  [ "$vm_status" = "P" ] \
    && pass "the shop left the order pending confirmation ($vm_status)" \
    || fail "the shop's order is at status '$vm_status', expected P"
fi

say "Plugin log"
log=$(jm sh -c 'cat /var/www/html/administrator/logs/plg_vmpayment_spectrocoin.log.php 2>/dev/null || true')
ours=$(printf '%s\n' "$log" | grep -iE "unexpected error|fatal|uncaught" || true)
[ -z "$ours" ] && pass "no unexpected errors logged by the plugin" \
  || { fail "unexpected errors in the log:"; printf '%s\n' "$ours" | head -5; }

if [ "$KEEP" -eq 1 ]; then
  echo -e "\nstack left running: add '127.0.0.1 shop.test' to /etc/hosts, then"
  echo    "http://shop.test:8092/administrator (admin/tier3tier3tier3)"
else
  docker compose down -v >/dev/null 2>&1 || true
  rm -rf .certs
fi

echo
[ "$FAILED" -eq 0 ] && echo "tier 3 PASSED" || echo "tier 3 FAILED ($FAILED check(s))"
exit $([ "$FAILED" -eq 0 ] && echo 0 || echo 1)
