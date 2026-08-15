#!/bin/sh
# Completes VirtueMart's installation the way an administrator does.
#
# The CLI installer cannot: VirtueMart's install script ends with
# $app->redirect(), which does not exist on a console application, so it aborts
# having created tables but never writing its configuration. Without that
# configuration every front-end request 303s to VirtueMart's migration screen
# and the callback is never reached.
set -e
J=http://localhost/administrator/index.php
C=/tmp/vmcookies.txt
rm -f "$C"
TOKEN=$(curl -s -c "$C" "$J" | grep -oE 'name="[a-f0-9]{32}" value="1"' | head -1 | cut -d'"' -f2)
[ -n "$TOKEN" ] || { echo "no login token"; exit 1; }
curl -s -b "$C" -c "$C" -o /dev/null -X POST "$J" \
  --data-urlencode "username=admin" \
  --data-urlencode "passwd=tier2tier2tier2" \
  --data-urlencode "option=com_login" \
  --data-urlencode "task=login" \
  --data-urlencode "$TOKEN=1"
curl -s -b "$C" -c "$C" -o /tmp/vminstall.html \
  "$J?option=com_virtuemart&view=updatesmigration&installVM=1&nosafepathcheck=1"
echo "install page bytes: $(wc -c < /tmp/vminstall.html)"
