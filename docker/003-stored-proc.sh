#!/bin/bash
# MySQL CLI does not support DELIMITER in non-interactive mode.
# The docker-entrypoint-initdb.d mechanism pipes .sql files via stdin,
# which breaks stored procedures that use DELIMITER $$.
# This wrapper uses SOURCE to execute the file in interactive-like mode.
mysql -u root -p"$MYSQL_ROOT_PASSWORD" -e "SOURCE /sql-init/003_refresh_vl_aggregates.sql"
