<?php
/**
 * Copy this file to pg_config.inc.php and fill in the SAME live values
 * the dashboard already uses. Do not commit pg_config.inc.php.
 *
 * Place the filled file in DataCollector/ (next to the dashboard) or
 * copy it next to tests/remote_tri_test.php.
 *
 * Local fixture (127.0.0.1, both schemas in one database):
 *   bash tests/run_local.sh
 * That writes this gitignored file with the reporting/reporting_local user.
 *
 * Remote live databases:
 *   cd DataCollector && php tests/remote_tri_test.php
 *
 * OFFER (e3_prod_offer) and ODS (e3_prod_odsdb) may be two Postgres
 * databases. The dashboard never JOINs them — it queries each one
 * separately and looks up competition names in PHP.
 *
 * If both schemas live in the same database, leave the ODS_* connection
 * settings equal to the OFFER ones. If ODS is a separate database, set
 * $PG_ODS_DBNAME (and optional $PG_ODS_HOST / $PG_ODS_PORT / $PG_ODS_USER /
 * $PG_ODS_PASSWORD). The dashboard retries that second connection when
 * the first ODS query fails.
 */

$PG_HOST     = 'YOUR_REMOTE_PG_HOST';
$PG_PORT     = 5432;
$PG_DBNAME   = 'e3_prod_offer';
$PG_USER     = 'reporting';
$PG_PASSWORD = '';
$PG_SCHEMA   = 'e3_prod_offer';

$PG_ODS_HOST     = $PG_HOST;
$PG_ODS_PORT     = $PG_PORT;
$PG_ODS_DBNAME   = 'e3_prod_odsdb';
$PG_ODS_USER     = $PG_USER;
$PG_ODS_PASSWORD = $PG_PASSWORD;
$PG_ODS_SCHEMA   = 'e3_prod_odsdb';
