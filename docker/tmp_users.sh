#!/bin/bash
echo "=== users ==="
docker exec lamp_ecclesiacrm-mariadb106 mariadb -uroot -ptiger -D docker -e "SELECT usr_per_ID, usr_UserName, usr_Password, usr_NeedPasswordChange, usr_Admin FROM user_usr;"
echo "=== person 1 ==="
docker exec lamp_ecclesiacrm-mariadb106 mariadb -uroot -ptiger -D docker -e "SELECT per_ID, per_FirstName, per_LastName, per_Email, per_Flags FROM person_per WHERE per_ID=1;"
echo "=== expected sha256(test12! . 1) ==="
printf 'test12!1' | sha256sum
echo "=== config admin ==="
docker exec lamp_ecclesiacrm-mariadb106 mariadb -uroot -ptiger -D docker -e "SELECT cfg_Name, cfg_Value FROM config_cfg WHERE cfg_Name IN ('sAdminEmail','iMinPasswordLength','bRequireAdminTwoFactorAuth');" 2>&1