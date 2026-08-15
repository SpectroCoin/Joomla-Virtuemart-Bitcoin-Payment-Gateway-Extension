#!/usr/bin/env bash
# ============================================================================
# Tier 2 end-to-end test — install VirtueMart, put a real order through it,
# deliver callbacks, and assert what the shop actually does.
#
# Tier 1 stops at packaging and loading, deliberately: it does not install
# VirtueMart. Everything the callback does - VirtueMartModelOrders,
# VmModel::getModel('orders')->updateStatusForOneOrder - needs VirtueMart
# present, so this installs it.
#
# The SpectroCoin API is stood in for by a stub answering as spectrocoin.com
# inside the compose network, over TLS signed by a CA generated here. No
# credentials, no live orders, no calls to the real API — and because the alias
# does the redirection, the plugin's own Config URLs are exercised as they ship.
#
# SCOPE, stated honestly: this covers the callback contract, not checkout.
# VirtueMart assembles the SpectroCoin order inside plgVmConfirmedOrder from a
# VirtueMartCart in the shopper's session; driving that needs a browser and
# belongs to Tier 3. The SpectroCoin order here is seeded directly on the stub.
#
# Usage:
#   ./tier2.sh          # run the full flow
#   ./tier2.sh --keep   # leave the stack running for inspection
# ============================================================================
set -euo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT="$(cd "$HERE/../../.." && pwd)"
VM_PACKAGE="${VM_PACKAGE:-$HOME/spectrocoin-plugin-audit/.artifacts/com_virtuemart.4.6.4.11226_package.zip}"
VM_URL="https://dev.virtuemart.net/attachments/download/1406/com_virtuemart.4.6.4.11226_package_or_extract.zip"
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

# --------------------------------------------------------------------------
# 1. Certificates and the VirtueMart package.
# --------------------------------------------------------------------------
say "Preparing certificates and VirtueMart"
rm -rf .certs && mkdir -p .certs
openssl req -x509 -newkey rsa:2048 -nodes -days 3650 \
  -keyout .certs/ca.key -out .certs/ca.crt \
  -subj "/CN=SpectroCoin Tier2 Test CA" >/dev/null 2>&1
openssl req -newkey rsa:2048 -nodes -keyout .certs/server.key -out .certs/server.csr \
  -subj "/CN=spectrocoin.com" >/dev/null 2>&1
printf 'subjectAltName=DNS:spectrocoin.com\n' > .certs/ext
openssl x509 -req -in .certs/server.csr -CA .certs/ca.crt -CAkey .certs/ca.key \
  -CAcreateserial -out .certs/server.crt -days 3650 -extfile .certs/ext >/dev/null 2>&1
chmod 644 .certs/*
[ -s .certs/server.crt ] && pass "issued a certificate for spectrocoin.com" \
  || fail "certificate generation failed"

# VirtueMart 4.6.4 is a free download and supports Joomla 4 and 5. Cached
# locally so a run does not depend on virtuemart.net being up.
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
say "Starting Joomla and the API stub"
docker compose down -v >/dev/null 2>&1 || true
docker compose up -d --build --wait >/dev/null 2>&1

jm()   { docker compose exec -T joomla "$@"; }
stub() { docker compose exec -T spectrocoin "$@"; }
q()    { docker compose exec -T db mariadb -uroot -proot -N -B joomla -e "$1" 2>/dev/null | tr -d '\r'; }
# Requests to the shop come from the stub container: that is where a callback
# comes from in production, and only a container on this network resolves the
# shop's hostname.
shopcurl() { docker compose exec -T spectrocoin curl "$@"; }

jm sh -c 'cat /certs/ca.crt >> /etc/ssl/certs/ca-certificates.crt' >/dev/null 2>&1 || true
PFX=$(jm sh -c "sed -n \"s/.*dbprefix[^']*'\\([^']*\\)'.*/\\1/p\" /var/www/html/configuration.php" | tr -d ' \r')
[ -n "$PFX" ] && pass "Joomla installed, table prefix ${PFX}" || fail "Joomla is not installed"

# --------------------------------------------------------------------------
# 3. VirtueMart.
#
# Its installer fights automation, and the order below is the one that works:
#   a) the package zip creates the 55 tables but aborts before copying files -
#      the install script ends with $app->redirect(), which a console
#      application does not have;
#   b) the inner component zip then lands the files;
#   c) opening the component once as an administrator over HTTP completes the
#      install (this is what creates virtuemart_configs - without it every
#      front-end request 303s to VirtueMart's migration screen).
# --------------------------------------------------------------------------
say "Installing VirtueMart"
docker compose cp "$VM_PACKAGE" joomla:/tmp/vm.zip >/dev/null 2>&1
jm sh -c 'cd /tmp && rm -rf vmx && mkdir vmx \
    && php -r "\$z=new ZipArchive(); \$z->open(\"/tmp/vm.zip\"); \$z->extractTo(\"/tmp/vmx\"); \$z->close();"' \
  >/dev/null 2>&1
jm sh -c 'cd /var/www/html && php cli/joomla.php extension:install --path=/tmp/vm.zip' >/dev/null 2>&1 || true
jm sh -c 'cd /var/www/html && php cli/joomla.php extension:install --path=/tmp/vmx/com_virtuemart.4.6.4.11226.zip' \
  >/dev/null 2>&1 || true

jm sh -c '[ -d /var/www/html/administrator/components/com_virtuemart ]' \
  && pass "VirtueMart component files installed" \
  || fail "VirtueMart files were not installed"

docker compose cp vm-finish-install.sh joomla:/tmp/vm-finish-install.sh >/dev/null 2>&1
jm sh /tmp/vm-finish-install.sh > "$WORK/vminstall.log" 2>&1 || true
[ -n "$(q "SHOW TABLES LIKE '${PFX}virtuemart_configs';")" ] \
  && pass "VirtueMart install completed (config table present)" \
  || { fail "VirtueMart install did not complete:"; tail -4 "$WORK/vminstall.log" | sed 's/^/        /'; }

# VirtueMart keeps translated columns in per-language tables and creates them
# only when the backend configuration is saved with an active language set -
# a path that is not reachable headlessly. Without them the callback dies on a
# missing table the moment it touches a payment method or a vendor.
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

# The payment method a merchant would create, with the statuses the callback
# maps onto. They are deliberately all different so each mapping is provable.
q "SET SESSION sql_mode='';
   INSERT IGNORE INTO ${PFX}virtuemart_vendors
     (virtuemart_vendor_id, vendor_name, vendor_currency, vendor_accepted_currencies, vendor_params, created_on, created_by)
   VALUES (1,'Tier2',47,'47','',NOW(),0);
   INSERT INTO ${PFX}virtuemart_paymentmethods
     (virtuemart_vendor_id, payment_jplugin_id, payment_element, payment_params,
      currency_id, shared, ordering, published, created_on, created_by)
   VALUES (1, $PJ, 'spectrocoin',
     'project_id=\"tier2-project\"|client_id=\"tier2-client\"|client_secret=\"tier2-secret\"|new_status=\"P\"|pending_status=\"U\"|paid_status=\"C\"|failed_status=\"X\"|expired_status=\"D\"|',
     47, 0, 1, 1, NOW(), 0);" >/dev/null 2>&1
PM=$(q "SELECT virtuemart_paymentmethod_id FROM ${PFX}virtuemart_paymentmethods WHERE payment_element='spectrocoin' ORDER BY virtuemart_paymentmethod_id DESC LIMIT 1;")
q "INSERT IGNORE INTO ${PFX}virtuemart_vendors_en_gb (virtuemart_vendor_id, slug) VALUES (1,'tier2');" >/dev/null 2>&1
# A shop has a store owner, and VirtueMart emails the order status on every
# change. Without one there is no sender or recipient, the mail throws, and a
# settlement that was applied correctly still answers 500.
STORE_OWNER=$(q "SELECT id FROM ${PFX}users ORDER BY id LIMIT 1;")
q "SET SESSION sql_mode='';
   INSERT IGNORE INTO ${PFX}virtuemart_vmusers
     (virtuemart_user_id, virtuemart_vendor_id, user_is_vendor, created_on, created_by)
   VALUES ($STORE_OWNER, 1, 1, NOW(), 0);" >/dev/null 2>&1
[ -n "$STORE_OWNER" ] && pass "store owner set (user $STORE_OWNER)" \
                      || fail "no Joomla user to make store owner"
q "INSERT IGNORE INTO ${PFX}virtuemart_paymentmethods_en_gb
     (virtuemart_paymentmethod_id, payment_name, payment_desc, slug)
   VALUES ($PM,'SpectroCoin','','spectrocoin');" >/dev/null 2>&1
[ -n "$PM" ] && pass "payment method configured (id $PM)" || fail "payment method could not be created"

# --------------------------------------------------------------------------
# 5. A real VirtueMart order, and the matching SpectroCoin order.
# --------------------------------------------------------------------------
say "Creating the order"
q "SET SESSION sql_mode='';
   INSERT INTO ${PFX}virtuemart_orders
     (virtuemart_user_id, virtuemart_vendor_id, order_number, customer_number, order_pass,
      order_total, order_salesPrice, order_subtotal, order_currency, order_status,
      user_currency_id, user_currency_rate, virtuemart_paymentmethod_id, order_language,
      ip_address, created_on, created_by, modified_on, modified_by)
   VALUES ($STORE_OWNER, 1, 'TIER2ORDER1', 'C1', 'p_1', 12.34, 12.34, 12.34, 47, 'P',
           47, 1.0, $PM, 'en-GB', '127.0.0.1', NOW(), 0, NOW(), 0);" >/dev/null 2>&1
OID=$(q "SELECT virtuemart_order_id FROM ${PFX}virtuemart_orders ORDER BY virtuemart_order_id DESC LIMIT 1;")
q "SET SESSION sql_mode='';
   INSERT INTO ${PFX}virtuemart_order_userinfos
     (virtuemart_order_id, virtuemart_user_id, address_type, address_type_name, last_name,
      first_name, phone_1, address_1, city, virtuemart_country_id, zip, email,
      created_on, created_by, modified_on, modified_by)
   VALUES ($OID, $STORE_OWNER, 'BT', 'BT', 'Two', 'Tier', '000', '1 Test St', 'Vilnius', 0, '01100',
           'tier2@example.com', NOW(), 0, NOW(), 0);" >/dev/null 2>&1
[ -n "$OID" ] && pass "VirtueMart order #$OID created (12.34 EUR, status P)" \
              || fail "order could not be created"

# The SpectroCoin side. Seeded on the stub rather than created through
# checkout - see the scope note in the header.
stub curl -fsS -X POST http://localhost/__test/reset >/dev/null 2>&1
stub curl -fsS -X POST -H 'Authorization: Bearer stub-access-token' -H 'Content-Type: application/json' \
  -d "{\"orderId\":\"$OID-tier2\",\"receiveAmount\":\"12.34\",\"receiveCurrencyCode\":\"EUR\",\"callbackUrl\":\"http://shop.test/\",\"projectId\":\"tier2-project\"}" \
  http://localhost/api/public/merchants/orders/create >/dev/null 2>&1
UUID=$(stub sh -c 'php -r "\$s=json_decode(file_get_contents(\"/tmp/stub-state.json\"),true); echo array_key_first(\$s[\"orders\"]);"' 2>/dev/null)
[ -n "$UUID" ] && pass "SpectroCoin order created (uuid ${UUID:0:8}…)" \
               || fail "no SpectroCoin order was created"

# --------------------------------------------------------------------------
# 6. Deliver callbacks and assert what the shop does with each status.
# --------------------------------------------------------------------------
say "Delivering callbacks for every status on the wire"

CB="http://shop.test/index.php?option=com_virtuemart&view=pluginresponse&task=pluginnotification"

patch_order() {
  stub curl -fsS -X POST -H 'Content-Type: application/json' -d "$1" \
    http://localhost/__test/status >/dev/null 2>&1
}
reset_order() { q "UPDATE ${PFX}virtuemart_orders SET order_status='P' WHERE virtuemart_order_id=$OID;" >/dev/null 2>&1; }
order_status() { q "SELECT order_status FROM ${PFX}virtuemart_orders WHERE virtuemart_order_id=$OID;"; }

deliver() {
  shopcurl -s -o /dev/null -w '%{http_code}' -X POST -H 'Content-Type: application/json' \
    -d "{\"id\":\"$UUID\",\"merchantApiId\":\"tier2-project\"}" "$CB"
}

check_status() {
  local status="$1" want="$2" note="${3:-}"
  reset_order
  patch_order "{\"uuid\":\"$UUID\",\"status\":\"$status\"}"
  local code got
  code=$(deliver)
  got=$(order_status)
  if [ "$code" = "200" ] && [ "$got" = "$want" ]; then
    pass "$status -> $want${note:+ ($note)}"
  else
    fail "$status gave HTTP $code and status '$got', expected 200 and '$want'${note:+ ($note)}"
  fi
}

check_status NEW     P "the method's new_status"
check_status PENDING U "the method's pending_status"
check_status PAID    C "the method's paid_status"
check_status FAILED          X
check_status CANCELLED       X
check_status REJECTED        X
check_status INVALID_PAYMENT X
check_status EXPIRED         D "the method's expired_status"

# Informational statuses report on a payment already under way. The order must
# be left exactly as it was: transitioning here would either fulfil an order
# that was not paid in full, or reverse one the merchant already settled.
for s in PARTIAL_PAYMENT UNDERPAID LATE_CRYPTO_PAYMENT PENDING_LATE_CRYPTO_PAYMENT \
         PROCESSING_REFUND REFUNDED REJECTED_REFUND TEST TEST_PAID TEST_EXPIRED; do
  check_status "$s" P "informational, no change"
done

# --------------------------------------------------------------------------
# 7. The callback endpoint is a public URL. It must refuse the obvious abuse.
# --------------------------------------------------------------------------
say "Callback endpoint guards"

code=$(shopcurl -s -o /dev/null -w '%{http_code}' "$CB")
[ "$code" = "405" ] && pass "GET is refused (405)" \
                    || fail "GET returned $code, expected 405 - the callback must be POST-only"

# A failing API call must be handled, not fatal.
reset_order
code=$(shopcurl -s -o /dev/null -w '%{http_code}' -X POST -H 'Content-Type: application/json' \
  -d "{\"id\":\"no-such-uuid\",\"merchantApiId\":\"tier2-project\"}" "$CB")
[ "$code" = "400" ] && pass "an unresolvable order is refused (400)" \
                    || fail "unresolvable order returned $code, expected 400"

# A callback quoting a merchantApiId we have no method for.
code=$(shopcurl -s -o /dev/null -w '%{http_code}' -X POST -H 'Content-Type: application/json' \
  -d "{\"id\":\"$UUID\",\"merchantApiId\":\"not-our-project\"}" "$CB")
[ "$code" = "400" ] && pass "a callback for an unknown project is refused (400)" \
                    || fail "unknown project returned $code, expected 400"

# A settlement in the wrong currency.
patch_order "{\"uuid\":\"$UUID\",\"status\":\"PAID\",\"receiveCurrencyCode\":\"XXX\"}"
reset_order
code=$(deliver)
now=$(order_status)
if [ "$code" = "400" ] && [ "$now" = "P" ]; then
  pass "a settlement in the wrong currency is refused (400)"
else
  fail "currency mismatch returned $code and left the order '$now'"
fi

# --------------------------------------------------------------------------
# 8. Nothing may have been logged as an unexpected error.
# --------------------------------------------------------------------------
say "Plugin log"
log=$(jm sh -c 'cat /var/www/html/administrator/logs/plg_vmpayment_spectrocoin.log.php 2>/dev/null || true')
ours=$(printf '%s\n' "$log" | grep -iE "unexpected error|fatal|uncaught" || true)
[ -z "$ours" ] && pass "no unexpected errors logged by the plugin" \
  || { fail "unexpected errors in the log:"; printf '%s\n' "$ours" | head -5; }

if [ "$KEEP" -eq 1 ]; then
  echo -e "\nstack left running: add '127.0.0.1 shop.test' to /etc/hosts, then"
  echo    "http://shop.test:8091/administrator (admin/tier2tier2tier2)"
else
  docker compose down -v >/dev/null 2>&1 || true
  rm -rf .certs
fi

echo
[ "$FAILED" -eq 0 ] && echo "tier 2 PASSED" || echo "tier 2 FAILED ($FAILED check(s))"
exit $([ "$FAILED" -eq 0 ] && echo 0 || echo 1)
