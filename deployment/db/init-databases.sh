#!/bin/bash
# Runs once on first MariaDB init (via /docker-entrypoint-initdb.d).
# Creates the three databases Pathfinder needs and an app user with access.
# The schema + EVE universe data are imported later through Pathfinder's /setup wizard.
set -e

mysql -u root -p"${MARIADB_ROOT_PASSWORD}" <<-SQL
	CREATE DATABASE IF NOT EXISTS \`${MYSQL_PF_DB_NAME}\`;
	CREATE DATABASE IF NOT EXISTS \`${MYSQL_UNIVERSE_DB_NAME}\`;
	CREATE DATABASE IF NOT EXISTS \`${MYSQL_CCP_DB_NAME}\`;
	CREATE USER IF NOT EXISTS '${MYSQL_USER}'@'%' IDENTIFIED BY '${MYSQL_PASSWORD}';
	GRANT ALL PRIVILEGES ON \`${MYSQL_PF_DB_NAME}\`.*       TO '${MYSQL_USER}'@'%';
	GRANT ALL PRIVILEGES ON \`${MYSQL_UNIVERSE_DB_NAME}\`.* TO '${MYSQL_USER}'@'%';
	GRANT ALL PRIVILEGES ON \`${MYSQL_CCP_DB_NAME}\`.*      TO '${MYSQL_USER}'@'%';
	FLUSH PRIVILEGES;
SQL
