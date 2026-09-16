#!/usr/bin/env bash
# Seed local Postgres, lint, unit+live merge tests, serve the TRI page, curl-assert.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
DC="$ROOT/DataCollector"
PG_USER="${PG_USER:-reporting}"
PG_PASSWORD="${PG_PASSWORD:-reporting_local}"
PG_DBNAME="${PG_DBNAME:-e3_prod_offer}"
PG_HOST="${PG_HOST:-127.0.0.1}"
PG_PORT="${PG_PORT:-5432}"
HTTP_PORT="${HTTP_PORT:-8080}"
URL="http://127.0.0.1:${HTTP_PORT}/unsettled-Outrights-sep.php"

if ! pg_isready -h "$PG_HOST" -p "$PG_PORT" >/dev/null 2>&1; then
  sudo pg_ctlcluster 16 main start
fi
pg_isready -h "$PG_HOST" -p "$PG_PORT"

sudo -u postgres psql -v ON_ERROR_STOP=1 <<SQL
DO \$\$
BEGIN
  IF NOT EXISTS (SELECT FROM pg_roles WHERE rolname = '${PG_USER}') THEN
    CREATE ROLE ${PG_USER} LOGIN PASSWORD '${PG_PASSWORD}';
  ELSE
    ALTER ROLE ${PG_USER} WITH LOGIN PASSWORD '${PG_PASSWORD}';
  END IF;
END
\$\$;

SELECT 'CREATE DATABASE ${PG_DBNAME} OWNER ${PG_USER}'
WHERE NOT EXISTS (SELECT FROM pg_database WHERE datname = '${PG_DBNAME}')\gexec

GRANT ALL PRIVILEGES ON DATABASE ${PG_DBNAME} TO ${PG_USER};
SQL

sudo -u postgres psql -d "$PG_DBNAME" -v ON_ERROR_STOP=1 -c "GRANT CREATE ON DATABASE ${PG_DBNAME} TO ${PG_USER};"
sudo -u postgres psql -d "$PG_DBNAME" -v ON_ERROR_STOP=1 -f "$DC/tests/seed_local.sql"
sudo -u postgres psql -d "$PG_DBNAME" -v ON_ERROR_STOP=1 <<SQL
GRANT USAGE ON SCHEMA e3_prod_offer, e3_prod_odsdb TO ${PG_USER};
GRANT SELECT ON ALL TABLES IN SCHEMA e3_prod_offer TO ${PG_USER};
GRANT SELECT ON ALL TABLES IN SCHEMA e3_prod_odsdb TO ${PG_USER};
SQL

cat > "$DC/pg_config.inc.php" <<PHP
<?php
\$PG_HOST     = '${PG_HOST}';
\$PG_PORT     = ${PG_PORT};
\$PG_DBNAME   = '${PG_DBNAME}';
\$PG_USER     = '${PG_USER}';
\$PG_PASSWORD = '${PG_PASSWORD}';
\$PG_SCHEMA   = 'e3_prod_offer';

\$PG_ODS_HOST     = \$PG_HOST;
\$PG_ODS_PORT     = \$PG_PORT;
\$PG_ODS_DBNAME   = 'e3_prod_odsdb';
\$PG_ODS_USER     = \$PG_USER;
\$PG_ODS_PASSWORD = \$PG_PASSWORD;
\$PG_ODS_SCHEMA   = 'e3_prod_odsdb';
PHP

php -l "$DC/unsettled-Outrights-sep.php"
php -l "$DC/class_postgres.inc.php"
php -l "$DC/class_database.inc.php"
php -l "$DC/lib_outrights.inc.php"
php -l "$DC/tests/merge_test.php"
php -l "$DC/tests/assert_http.php"
php -l "$DC/tests/remote_tri_test.php"

php "$DC/tests/merge_test.php"

URL_OK=0
if curl -fsS -o /dev/null --max-time 1 "$URL" 2>/dev/null; then
  echo "PHP server already responding on ${HTTP_PORT}"
  URL_OK=1
else
  echo "Starting PHP built-in server on ${HTTP_PORT}"
  TMUX_CONF="/exec-daemon/tmux.portal.conf"
  SESSION_NAME="tri-php-server"
  if [ -f "$TMUX_CONF" ]; then
    tmux -f "$TMUX_CONF" has-session -t "=$SESSION_NAME" 2>/dev/null || \
      tmux -f "$TMUX_CONF" new-session -d -s "$SESSION_NAME" -c "$DC" -- "${SHELL:-bash}" -l
    tmux -f "$TMUX_CONF" send-keys -t "$SESSION_NAME:0.0" "php -S 127.0.0.1:${HTTP_PORT} -t '$DC'" C-m
  else
    php -S "127.0.0.1:${HTTP_PORT}" -t "$DC" >/tmp/tri-php-server.log 2>&1 &
    echo $! > /tmp/tri-php-server.pid
  fi
  for i in 1 2 3 4 5 6 7 8 9 10 11 12 13 14 15; do
    if curl -fsS -o /dev/null --max-time 1 "$URL" 2>/dev/null; then
      URL_OK=1
      break
    fi
    sleep 0.4
  done
fi

if [ "$URL_OK" != 1 ]; then
  echo "PHP server did not become ready at $URL" >&2
  exit 1
fi

php "$DC/tests/assert_http.php" "$URL"
echo "Local TRI page OK: $URL"
