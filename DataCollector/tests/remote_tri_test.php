<?php
/**
 * Remote TRI connection test — OFFER + ODS against the LIVE databases.
 *
 * This is NOT a local Postgres fixture test. It connects to the same remote
 * databases the live dashboard uses, via pg_config.inc.php and
 * class_postgres.inc.php (connect / ExecSQL / uppercase FetchRow).
 *
 * How to run (on a machine that can reach the remote DBs):
 *
 *   1. Copy live pg_config.inc.php next to this script, OR into DataCollector/.
 *      Do not commit it. See pg_config.example.php for the variable names.
 *   2. From the DataCollector folder:
 *
 *        php tests/remote_tri_test.php
 *
 *      Also works as:
 *
 *        php DataCollector/tests/remote_tri_test.php
 *
 * Reuses FetchOfferOutrights / FetchOdsOutrights / CollectTriOutrights from
 * unsettled-Outrights-sep.php (no HTML). ODS retry uses $PG_ODS_DBNAME or
 * e3_prod_odsdb, same as the dashboard. Competition names for ODS rows come
 * from e3_prod_offer.competition in PHP (no cross-database JOIN). OFFER wins
 * on the same event_id.
 *
 * Exit 0 if OFFER and ODS both succeed; exit 1 otherwise.
 */
error_reporting(E_ALL);
ini_set('display_errors', '1');

if (php_sapi_name() !== 'cli') {
    header('Content-Type: text/plain; charset=utf-8');
}

$tests_dir = dirname(__FILE__);
$dc_dir = dirname($tests_dir);

function fail($msg)
{
    fwrite(STDERR, 'FAIL: ' . $msg . "\n");
    exit(1);
}

function line($msg)
{
    echo $msg . "\n";
}

if (!function_exists('pg_connect')) {
    fail('php-pgsql extension is not loaded (install php-pgsql)');
}

$pg_config = null;
$pg_config_candidates = array(
    $tests_dir . '/pg_config.inc.php',
    $dc_dir . '/pg_config.inc.php',
);
foreach ($pg_config_candidates as $path) {
    if (is_file($path)) {
        $pg_config = $path;
        break;
    }
}

if ($pg_config === null) {
    fail(
        "pg_config.inc.php not found.\n" .
        "  Looked in:\n    " . implode("\n    ", $pg_config_candidates) . "\n" .
        "  Copy the live DataCollector/pg_config.inc.php next to this script\n" .
        "  or into DataCollector/, then run: php tests/remote_tri_test.php"
    );
}

$pg_class = $dc_dir . '/class_postgres.inc.php';
if (!is_file($pg_class)) {
    fail('class_postgres.inc.php not found at ' . $pg_class);
}

require_once $pg_class;
require_once $pg_config;

if (!isset($PG_HOST, $PG_PORT, $PG_DBNAME, $PG_USER, $PG_PASSWORD)) {
    fail($pg_config . ' did not set $PG_HOST / $PG_PORT / $PG_DBNAME / $PG_USER / $PG_PASSWORD');
}

define('TRI_LIBRARY_ONLY', true);
require_once $dc_dir . '/unsettled-Outrights-sep.php';

if (!function_exists('CollectTriOutrights') || !function_exists('FetchOfferOutrights') || !function_exists('FetchOdsOutrights')) {
    fail('unsettled-Outrights-sep.php did not define CollectTriOutrights / FetchOfferOutrights / FetchOdsOutrights');
}

line('TRI remote test (not a local fixture)');
line('Config:  ' . $pg_config);
line('Connect: host=' . $PG_HOST . ' port=' . $PG_PORT . ' dbname=' . $PG_DBNAME . ' user=' . $PG_USER);
line('');

$DB_PG = new Postgres();
try {
    $DB_PG->connect($PG_HOST, $PG_PORT, $PG_DBNAME, $PG_USER, $PG_PASSWORD);
} catch (Exception $e) {
    fail('PostgreSQL connection failed: ' . $e->getMessage());
}

line('Connected to ' . $PG_DBNAME . ' on ' . $PG_HOST . ':' . $PG_PORT);

try {
    $tri = CollectTriOutrights($DB_PG);
} catch (Exception $e) {
    $DB_PG->Disconnect();
    fail('CollectTriOutrights: ' . $e->getMessage());
}
$DB_PG->Disconnect();

$offer_n = count($tri['offer_rows']);
$ods_n = count($tri['ods_rows']);
$merged_n = count($tri['merged']);
$skipped = (int) $tri['skipped'];

line('OFFER rows:              ' . $offer_n);
if ($tri['offer_error']) {
    line('OFFER error:             ' . $tri['offer_error']);
}
line('ODS rows:                ' . $ods_n);
if ($tri['ods_retried']) {
    line('ODS retry db:            ' . $tri['ods_retry_db']);
}
if ($tri['ods_error']) {
    line('ODS error:               ' . $tri['ods_error']);
}
line('ODS names filled:        ' . $tri['ods_named'] . '/' . $tri['ods_in_table']);
if ($tri['name_error']) {
    line('Competition name error:  ' . $tri['name_error']);
}
line('Duplicates skipped:      ' . $skipped . ' (OFFER wins on same event_id)');
line('Merged:                  ' . $merged_n);
line('');

$sample_n = min(8, $merged_n);
line('Sample rows (' . $sample_n . ' of ' . $merged_n . '): source | competition | event_id | event_name');
if ($sample_n === 0) {
    line('  (none)');
} else {
    for ($i = 0; $i < $sample_n; $i++) {
        $row = $tri['merged'][$i];
        $source = isset($row['SOURCE']) ? $row['SOURCE'] : '';
        $comp = isset($row['COMPETITION_NAME']) ? $row['COMPETITION_NAME'] : '';
        $eid = isset($row['EVENT_ID']) ? $row['EVENT_ID'] : '';
        $ename = isset($row['EVENT_NAME']) ? $row['EVENT_NAME'] : '';
        line('  ' . $source . ' | ' . $comp . ' | ' . $eid . ' | ' . $ename);
    }
}

if ($tri['offer_error'] || $tri['ods_error']) {
    fail('query failed (see OFFER/ODS error above)');
}

line('');
line('OK');
exit(0);
