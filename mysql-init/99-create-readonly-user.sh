#!/usr/bin/env bash
set -euo pipefail

db_name="${MYSQL_DATABASE:?MYSQL_DATABASE is required}"
db_user="${LOCAL_LAB_DB_USER:?LOCAL_LAB_DB_USER is required}"
db_password="${LOCAL_LAB_DB_PASSWORD:?LOCAL_LAB_DB_PASSWORD is required}"

sql_escape() {
  printf "%s" "$1" | sed "s/'/''/g"
}

db_name_escaped="${db_name//\`/\`\`}"
db_user_escaped="$(sql_escape "$db_user")"
db_password_escaped="$(sql_escape "$db_password")"

mysql -uroot -p"${MYSQL_ROOT_PASSWORD}" <<SQL
CREATE USER IF NOT EXISTS '${db_user_escaped}'@'%' IDENTIFIED BY '${db_password_escaped}';
GRANT SELECT ON \`${db_name_escaped}\`.* TO '${db_user_escaped}'@'%';
FLUSH PRIVILEGES;
SQL
