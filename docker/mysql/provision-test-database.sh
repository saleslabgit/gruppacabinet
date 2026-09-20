#!/bin/sh

set -eu

mysql --host=mysql --user=root <<'SQL'
CREATE DATABASE IF NOT EXISTS `gruppa_cabinet_test`;
GRANT ALL PRIVILEGES ON `gruppa_cabinet_test`.* TO 'cabinet'@'%';
SQL
