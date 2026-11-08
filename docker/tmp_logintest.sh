#!/bin/bash
rm -f /tmp/cjlogin.txt /tmp/login.html
echo "=== POST login verbose ==="
curl -s -D - -o /tmp/login.html -c /tmp/cjlogin.txt -d 'User=Admin&Password=test12!' http://localhost/session/login | head -20
echo "=== body title/messages ==="
grep -oiE '<title>[^<]*</title>|incorrect|invalid|error|password' /tmp/login.html | head -10
echo "=== cookies ==="
cat /tmp/cjlogin.txt
echo "=== login route code ==="
cd /home/khbgbgk/ecclesiacrm/src
grep -rn "session/login\|/login" api/routes/ EcclesiaCRM/APIControllers/ 2>/dev/null | head -10