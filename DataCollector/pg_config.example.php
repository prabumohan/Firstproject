<?php
/**
 * Copy this file to pg_config.inc.php and fill in live values.
 *
 * OFFER (e3_prod_offer) and ODS (e3_prod_odsdb) may be two Postgres
 * databases. The dashboard never JOINs them — it queries each one
 * separately and looks up competition names in PHP.
 *
 * If both schemas live in the same database, leave the ODS_* connection
 * settings equal to the OFFER ones (the defaults below).
 */

$PG_HOST     = '127.0.0.1';
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
