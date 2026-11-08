#!/bin/bash
HASH=$(printf 'test12!1' | sha256sum | awk '{print $1}')
echo "hash=$HASH"
docker exec lamp_ecclesiacrm-mariadb106 mariadb -uroot -ptiger -D docker -e "UPDATE user_usr SET usr_Password='$HASH', usr_NeedPasswordChange=0 WHERE usr_UserName='Admin';"
docker exec lamp_ecclesiacrm-mariadb106 mariadb -uroot -ptiger -D docker -e "SELECT usr_UserName, usr_Password, usr_NeedPasswordChange, usr_Admin FROM user_usr;"
echo "=== login test ==="
rm -f /tmp/cjlogin.txt
code=$(curl -s -o /dev/null -w '%{http_code}' -c /tmp/cjlogin.txt -d 'User=Admin&Password=test12!' http://localhost/session/login)
echo "login http=$code (302 = OK)"
# verify session actually authenticated
code2=$(curl -s -o /dev/null -w '%{http_code}' -b /tmp/cjlogin.txt http://localhost/v2/dashboard)
echo "dashboard http=$code2 (200 = connecté)"
rm -f /tmp/cjlogin.txt