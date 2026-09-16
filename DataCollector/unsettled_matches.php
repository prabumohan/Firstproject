<?php
/**
 * NON-LIVE / Unsettled Matches dashboard — Oracle + PostgreSQL.
 *
 * Deploy to /var/www/sbet/dashboard/DataCollector/ (copy this whole folder).
 * On the server you also need the existing Oracle files:
 *   - class_database.inc.php
 *   - common.inc.php
 * Plus from this folder:
 *   - class_postgres.inc.php
 *   - lib_outrights.inc.php
 *   - pg_config.inc.php      (copy from pg_config.example.php)
 *   - oracle_config.inc.php  (copy from oracle_config.example.php)
 * And php-pgsql installed.
 *
 * TRI section merges two Postgres sources in PHP:
 *   1) e3_prod_offer.event  (JOIN competition — has names)
 *   2) e3_prod_odsdb.odsevent (has competitionid only)
 * ODS competition names are looked up from e3_prod_offer.competition
 * in a separate query. A cross-database JOIN is not used.
 */
$here = dirname(__FILE__);

if (is_file($here . '/class_database.inc.php')) {
    require_once $here . '/class_database.inc.php';
}
if (is_file($here . '/common.inc.php')) {
    require_once $here . '/common.inc.php';
}
require_once $here . '/class_postgres.inc.php';
require_once $here . '/lib_outrights.inc.php';

if (is_file($here . '/oracle_config.inc.php')) {
    require_once $here . '/oracle_config.inc.php';
} else {
    $ORA_TNS = $ORA_USER = $ORA_PASSWORD = '';
}
if (is_file($here . '/pg_config.inc.php')) {
    require_once $here . '/pg_config.inc.php';
} else {
    require_once $here . '/pg_config.example.php';
}

date_default_timezone_set('Europe/London');
$now = date('Y-m-d H:i:s');

function h($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

/**
 * Oracle: unsettled outright matches (existing logic).
 *
 * @param Oracle $DB_ORACLE
 */
function LookupOracleUnsettled($DB_ORACLE)
{
    $query = "
SELECT meeting_id,
       meeting_name,
       event_id,
       event_name,
       to_char(EVENT_STARTING_TIME, 'dd/mm/yyyy hh24:mi:ss') EVENT_STARTING_TIME,
       to_char(EVENT_ENDING_TIME,   'dd/mm/yyyy hh24:mi:ss') EVENT_ENDING_TIME,
       event_type
FROM reporting.comp_unsettled_matches_v
WHERE EVENT_STARTING_TIME = EVENT_ENDING_TIME
  AND trunc(sysdate, 'YEAR') = trunc(event_starting_time, 'YEAR')
  AND EVENT_STARTING_TIME <= sysdate - 2/24
  AND EVENT_ENDING_TIME   <= sysdate - 2/24
  AND event_type = 'O'
GROUP BY meeting_id, meeting_name, EVENT_STARTING_TIME, EVENT_ENDING_TIME,
         event_id, event_name, event_type
ORDER BY event_name ASC";

    $resource = $DB_ORACLE->PrepareSQL($query);
    $result   = $DB_ORACLE->ExecSQL($resource);
    ?>
<table border="1" align="center">
<tr class="row">
  <td>Meeting ID</td>
  <td>Meeting Name</td>
  <td>Event ID</td>
  <td>Event Name</td>
  <td>Event Type</td>
  <td>Event Starting Time</td>
  <td>Event Ending Time</td>
</tr>
<?php
    while ($row = $DB_ORACLE->FetchRow($result)) {
        $event_name = $row['EVENT_NAME'];
        if (strpos($event_name, ' v ') !== false) {
            continue;
        }
        echo '<tr class="row">';
        echo '<td>' . h($row['MEETING_ID']) . '</td>';
        echo '<td>' . h($row['MEETING_NAME']) . '</td>';
        echo '<td>' . h($row['EVENT_ID']) . '</td>';
        echo '<td>' . h($row['EVENT_NAME']) . '</td>';
        echo '<td>' . h($row['EVENT_TYPE']) . '</td>';
        echo '<td>' . h($row['EVENT_STARTING_TIME']) . '</td>';
        echo '<td>' . h($row['EVENT_ENDING_TIME']) . '</td>';
        echo '</tr>';
    }
    echo '</table>';
}

/**
 * @param array[] $rows
 */
function RenderTriOutrightsTable($rows)
{
    ?>
<table border="1" align="center">
<tr class="row">
  <td>Competition ID</td>
  <td>Competition Name</td>
  <td>Event ID</td>
  <td>Event Name</td>
  <td>Source</td>
  <td>Status</td>
  <td>Event Status</td>
  <td>Trade Status</td>
  <td>Event Start Time</td>
</tr>
<?php
    foreach ($rows as $row) {
        echo '<tr class="row">';
        echo '<td>' . h(isset($row['COMPETITION_ID']) ? $row['COMPETITION_ID'] : '') . '</td>';
        echo '<td>' . h(isset($row['COMPETITION_NAME']) ? $row['COMPETITION_NAME'] : '') . '</td>';
        echo '<td>' . h(isset($row['EVENT_ID']) ? $row['EVENT_ID'] : '') . '</td>';
        echo '<td>' . h(isset($row['EVENT_NAME']) ? $row['EVENT_NAME'] : '') . '</td>';
        echo '<td>' . h(isset($row['SOURCE']) ? $row['SOURCE'] : '') . '</td>';
        echo '<td>' . h(isset($row['STATUS']) ? $row['STATUS'] : '') . '</td>';
        echo '<td>' . h(isset($row['EVENT_STATUS']) ? $row['EVENT_STATUS'] : '') . '</td>';
        echo '<td>' . h(isset($row['TRADE_STATUS']) ? $row['TRADE_STATUS'] : '') . '</td>';
        echo '<td>' . h(isset($row['ESTARTTIME']) ? $row['ESTARTTIME'] : '') . '</td>';
        echo '</tr>';
    }
    echo '</table>';
}

/**
 * PostgreSQL: offered outrights from e3_prod_offer, plus ODS outrights
 * with competition names resolved from the offer database in PHP.
 *
 * @param Postgres      $DB_OFFER
 * @param Postgres|null $DB_ODS
 * @param string        $offer_schema
 * @param string        $ods_schema
 */
function LookupPostgresOfferedOutrights($DB_OFFER, $DB_ODS, $offer_schema, $ods_schema)
{
    $rows = LoadMergedTriOutrights($DB_OFFER, $DB_ODS, $offer_schema, $ods_schema);
    RenderTriOutrightsTable($rows);
}
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<meta http-equiv="refresh" content="180">
<title>NON-LIVE | Unsettled Matches</title>
</head>
<body>
<p>Last Update at <b><?php echo h($now); ?></b></p>

<h1 style="text-align:center">NON-LIVE | Unsettled Matches (MM1)</h1>
<?php
$oracle_error = null;
try {
    if (!class_exists('Oracle')) {
        throw new Exception('Oracle helper class_database.inc.php is not deployed on this host');
    }
    if ($ORA_TNS === '' || $ORA_USER === '') {
        throw new Exception('oracle_config.inc.php is missing or empty');
    }
    $DB_ORACLE = new Oracle();
    $DB_ORACLE->connectORA($ORA_TNS, $ORA_USER, $ORA_PASSWORD);
    LookupOracleUnsettled($DB_ORACLE);
    $DB_ORACLE->Disconnect();
} catch (Exception $e) {
    $oracle_error = $e->getMessage();
    echo '<p style="color:red">MM1 error: ' . h($oracle_error) . '</p>';
}
?>

<h1 style="text-align:center">OFFERED Outrights (TRI)</h1>
<p style="text-align:center">OFFER (e3_prod_offer) + ODS (e3_prod_odsdb). Competition names for ODS rows are looked up from offer.competition in PHP.</p>
<?php
$pg_error = null;
$DB_OFFER = null;
$DB_ODS = null;
$ods_is_separate = false;
try {
    if (empty($PG_HOST) || empty($PG_DBNAME)) {
        throw new Exception('pg_config.inc.php is missing or empty');
    }
    if (!isset($PG_ODS_HOST)) {
        $PG_ODS_HOST = $PG_HOST;
    }
    if (!isset($PG_ODS_PORT)) {
        $PG_ODS_PORT = $PG_PORT;
    }
    if (!isset($PG_ODS_DBNAME)) {
        $PG_ODS_DBNAME = $PG_DBNAME;
    }
    if (!isset($PG_ODS_USER)) {
        $PG_ODS_USER = $PG_USER;
    }
    if (!isset($PG_ODS_PASSWORD)) {
        $PG_ODS_PASSWORD = $PG_PASSWORD;
    }
    if (!isset($PG_ODS_SCHEMA)) {
        $PG_ODS_SCHEMA = 'e3_prod_odsdb';
    }
    if (!isset($PG_SCHEMA)) {
        $PG_SCHEMA = 'e3_prod_offer';
    }

    $DB_OFFER = new Postgres();
    $DB_OFFER->connect($PG_HOST, $PG_PORT, $PG_DBNAME, $PG_USER, $PG_PASSWORD);

    $ods_is_separate = !Postgres::SameTarget(
        $PG_HOST,
        $PG_PORT,
        $PG_DBNAME,
        $PG_USER,
        $PG_ODS_HOST,
        $PG_ODS_PORT,
        $PG_ODS_DBNAME,
        $PG_ODS_USER
    );
    if ($ods_is_separate) {
        $DB_ODS = new Postgres();
        $DB_ODS->connect($PG_ODS_HOST, $PG_ODS_PORT, $PG_ODS_DBNAME, $PG_ODS_USER, $PG_ODS_PASSWORD);
    } else {
        $DB_ODS = $DB_OFFER;
    }

    LookupPostgresOfferedOutrights($DB_OFFER, $DB_ODS, $PG_SCHEMA, $PG_ODS_SCHEMA);

    if ($ods_is_separate && $DB_ODS) {
        $DB_ODS->Disconnect();
    }
    $DB_OFFER->Disconnect();
} catch (Exception $e) {
    $pg_error = $e->getMessage();
    echo '<p style="color:red">TRI error: ' . h($pg_error) . '</p>';
    if ($ods_is_separate && $DB_ODS && $DB_ODS->IsConnected()) {
        $DB_ODS->Disconnect();
    }
    if ($DB_OFFER && $DB_OFFER->IsConnected()) {
        $DB_OFFER->Disconnect();
    }
}
?>
</body>
</html>
