#!/bin/sh
# Completes VirtueMart's installation the way an administrator does, then
# clicks the same "Install with Sample Data" button an administrator would -
# it is the only way to get a real category, a real product and stock
# payment/shipment methods without hand-guessing VirtueMart's product schema.
#
# The CLI installer cannot finish the plain install either way: VirtueMart's
# install script ends with $app->redirect(), which does not exist on a console
# application, so it aborts having created tables but never writing its
# configuration. Without that configuration every front-end request 303s to
# VirtueMart's migration screen and neither checkout nor the callback is ever
# reached.
set -e
J=http://localhost/administrator/index.php
C=/tmp/vmcookies.txt
rm -f "$C"
TOKEN=$(curl -s -c "$C" "$J" | grep -oE 'name="[a-f0-9]{32}" value="1"' | head -1 | cut -d'"' -f2)
[ -n "$TOKEN" ] || { echo "no login token"; exit 1; }
curl -s -b "$C" -c "$C" -o /dev/null -X POST "$J" \
  --data-urlencode "username=admin" \
  --data-urlencode "passwd=tier3tier3tier3" \
  --data-urlencode "option=com_login" \
  --data-urlencode "task=login" \
  --data-urlencode "$TOKEN=1"

# redirectedToInstallVM=1 is what actually selects the view's "install" layout
# (the two-button "Fresh Install" / "Fresh Install + Sample Data" screen);
# installVM=1 alone only renders the ordinary migration-tools dashboard, which
# creates virtuemart_configs as a side effect of loading but never seeds a
# product.
curl -s -b "$C" -c "$C" -o /tmp/vminstall.html \
  "$J?option=com_virtuemart&view=updatesmigration&installVM=1&redirectedToInstallVM=1&nosafepathcheck=1"
echo "install page bytes: $(wc -c < /tmp/vminstall.html)"

# The migration screen offers two buttons, each a plain onclick with the CSRF
# token baked into the URL - there is no <form> here to lift a token from, so
# the link itself is the only place it appears. "Fresh install + sample data"
# is the one that also seeds a category, a product and VirtueMart's own stock
# payment (Cash on delivery) and shipment (Self pick-up) methods.
SAMPLE_URL=$(grep -oE "index\.php\?option=com_virtuemart[^\"']*task=installCompleteSamples[^\"']*" /tmp/vminstall.html \
  | head -1 | sed 's/&amp;/\&/g')
if [ -z "$SAMPLE_URL" ]; then
  echo "no installCompleteSamples link found on the migration page"
  exit 1
fi
curl -s -b "$C" -c "$C" -o /tmp/vmsample.html "http://localhost/administrator/$SAMPLE_URL"
echo "sample-data response bytes: $(wc -c < /tmp/vmsample.html)"
