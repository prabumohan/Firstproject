<?php
/**
 * MERGED remote-test page — copy this ONE file onto the server:
 *
 *   /var/www/sbet/dashboard/DataCollector/unsettled_matches_merged.php
 *
 * It uses the files already on the server:
 *   class_database.inc.php
 *   common.inc.php
 *   class_postgres.inc.php
 *   pg_config.inc.php
 *
 * TRI: OFFER query + ODS query, merged in PHP.
 * ODS has competitionid but no name. Names are loaded from
 * e3_prod_offer.competition in a separate query (no cross-DB JOIN).
 *
 * If ODS is only a schema on the same Postgres, the existing pg_config
 * connection is enough. If that query fails, it retries a second
 * connection to database e3_prod_odsdb with the same host/user/password.
 */
$here = dirname(__FILE__);

if (is_file($here . '/class_database.inc.php')) {
    require_once $here . '/class_database.inc.php';
}
if (is_file($here . '/common.inc.php')) {
    require_once $here . '/common.inc.php';
}
require_once $here . '/class_postgres.inc.php';
require_once $here . '/pg_config.inc.php';

if (is_file($here . '/oracle_config.inc.php')) {
    require_once $here . '/oracle_config.inc.php';
}

date_default_timezone_set('Europe/London');
$now = date('Y-m-d H:i:s');

if (!isset($PG_SCHEMA) || $PG_SCHEMA === '') {
    $PG_SCHEMA = 'e3_prod_offer';
}
if (!isset($PG_ODS_SCHEMA) || $PG_ODS_SCHEMA === '') {
    $PG_ODS_SCHEMA = 'e3_prod_odsdb';
}

function h($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function pg_ident($name)
{
    if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', (string) $name)) {
        throw new InvalidArgumentException('Invalid SQL identifier');
    }
    return $name;
}

function pg_digit_ids($ids)
{
    $clean = array();
    foreach ((array) $ids as $id) {
        $id = trim((string) $id);
        if ($id !== '' && preg_match('/^-?[0-9]+$/', $id)) {
            $clean[$id] = $id;
        }
    }
    return array_values($clean);
}

function row_val($row, $key)
{
    if (!is_array($row)) {
        return '';
    }
    if (isset($row[$key])) {
        return $row[$key];
    }
    $u = strtoupper($key);
    if (isset($row[$u])) {
        return $row[$u];
    }
    $l = strtolower($key);
    if (isset($row[$l])) {
        return $row[$l];
    }
    return '';
}

function pg_fetch_all_rows($DB, $query)
{
    $result = $DB->ExecSQL($query);
    $rows = array();
    while ($row = $DB->FetchRow($result)) {
        $rows[] = array_change_key_case($row, CASE_UPPER);
    }
    return $rows;
}

function FetchOfferOutrights($DB, $schema)
{
    $schema = pg_ident($schema);
    $query = "
SELECT c.id   AS competition_id,
       c.name AS competition_name,
       e.id   AS event_id,
       e.name AS event_name,
       e.estatus->>'mb' AS event_status,
       'OFFERED' AS status,
       CASE
           WHEN e.etradestatus->>'mb' = '1' THEN 'NONTRADABLE/HIDDEN'
           WHEN e.etradestatus->>'mb' = '2' THEN 'TRADABLE'
           ELSE 'UNKNOWN'
       END AS trade_status,
       to_char(e.estarttime, 'DD/MM/YYYY HH24:MI:SS') AS estarttime,
       'OFFER' AS source
FROM {$schema}.event e
JOIN {$schema}.competition c ON e.competitionid = c.id
WHERE e.estarttime <= now() - interval '2 hours'
  AND e.estarttime >  now() - interval '90 days'
  AND (e.betting->'mb'->'original'->>'EndTime')::timestamp <= now() - interval '2 hours'
  AND e.estatus->>'mb' IN ('2')
  AND e.type = '2'
ORDER BY e.name";
    return pg_fetch_all_rows($DB, $query);
}

function FetchOdsOutrights($DB, $schema)
{
    $schema = pg_ident($schema);
    $query = "
SELECT e.competitionid AS competition_id,
       '' AS competition_name,
       e.id AS event_id,
       e.name AS event_name,
       e.event->>'status' AS event_status,
       'OFFERED' AS status,
       CASE
           WHEN e.event->>'tradeStatus' = '1' THEN 'NONTRADABLE/HIDDEN'
           WHEN e.event->>'tradeStatus' = '2' THEN 'TRADABLE'
           ELSE 'UNKNOWN'
       END AS trade_status,
       to_char((e.event->'anticipated'->>'startTime')::timestamp, 'DD/MM/YYYY HH24:MI:SS') AS estarttime,
       'ODS' AS source
FROM {$schema}.odsevent e
WHERE (e.event->'anticipated'->>'startTime')::timestamp <= now() - interval '2 hours'
  AND (e.event->'anticipated'->>'startTime')::timestamp >  now() - interval '90 days'
  AND (e.event->'betting'->>'endTime')::timestamp <= now() - interval '2 hours'
  AND e.event->>'status' IN ('2')
  AND e.event->>'type' = '2'
ORDER BY e.name";
    return pg_fetch_all_rows($DB, $query);
}

function CompetitionNamesFromRows($rows)
{
    $names = array();
    foreach ($rows as $row) {
        $id = trim((string) row_val($row, 'COMPETITION_ID'));
        $name = trim((string) row_val($row, 'COMPETITION_NAME'));
        if ($id !== '' && $name !== '') {
            $names[$id] = $name;
        }
    }
    return $names;
}

function MissingCompetitionIds($ods_rows, $known_names)
{
    $missing = array();
    foreach ($ods_rows as $row) {
        $id = trim((string) row_val($row, 'COMPETITION_ID'));
        if ($id === '') {
            continue;
        }
        $existing = trim((string) row_val($row, 'COMPETITION_NAME'));
        if ($existing !== '') {
            continue;
        }
        if (!isset($known_names[$id])) {
            $missing[$id] = $id;
        }
    }
    return array_values($missing);
}

function LookupCompetitionNames($DB_OFFER, $offer_schema, $ids)
{
    $ids = pg_digit_ids($ids);
    if (!$ids) {
        return array();
    }
    $schema = pg_ident($offer_schema);
    $names = array();
    foreach (array_chunk($ids, 500) as $chunk) {
        $in = implode(',', $chunk);
        $query = "SELECT id, name FROM {$schema}.competition WHERE id IN ({$in})";
        foreach (pg_fetch_all_rows($DB_OFFER, $query) as $row) {
            $id = (string) row_val($row, 'ID');
            if ($id !== '') {
                $names[$id] = (string) row_val($row, 'NAME');
            }
        }
    }
    return $names;
}

function ApplyCompetitionNames(&$rows, $names)
{
    foreach ($rows as &$row) {
        $id = trim((string) row_val($row, 'COMPETITION_ID'));
        $current = trim((string) row_val($row, 'COMPETITION_NAME'));
        if ($current === '' && $id !== '' && isset($names[$id])) {
            $row['COMPETITION_NAME'] = $names[$id];
        }
    }
    unset($row);
}

function MergeOutrightRows($offer_rows, $ods_rows)
{
    $merged = array();
    $seen = array();
    $skipped = 0;

    foreach ($offer_rows as $row) {
        $event_id = (string) row_val($row, 'EVENT_ID');
        $merged[] = $row;
        if ($event_id !== '') {
            $seen[$event_id] = true;
        }
    }

    foreach ($ods_rows as $row) {
        $event_id = (string) row_val($row, 'EVENT_ID');
        if ($event_id !== '' && isset($seen[$event_id])) {
            $skipped++;
            continue;
        }
        $merged[] = $row;
        if ($event_id !== '') {
            $seen[$event_id] = true;
        }
    }

    usort($merged, function ($a, $b) {
        $cmp = strcasecmp((string) row_val($a, 'EVENT_NAME'), (string) row_val($b, 'EVENT_NAME'));
        if ($cmp !== 0) {
            return $cmp;
        }
        return strcmp((string) row_val($a, 'EVENT_ID'), (string) row_val($b, 'EVENT_ID'));
    });

    return array($merged, $skipped);
}

function CountNamed($rows)
{
    $named = 0;
    foreach ($rows as $row) {
        if (trim((string) row_val($row, 'COMPETITION_NAME')) !== '') {
            $named++;
        }
    }
    return $named;
}

function pg_try_disconnect($DB)
{
    if ($DB && method_exists($DB, 'Disconnect')) {
        $DB->Disconnect();
    }
}

/**
 * ODS first on the existing connection (schema e3_prod_odsdb).
 * If that fails, retry a second connection to database e3_prod_odsdb
 * (or $PG_ODS_DBNAME if set in pg_config.inc.php).
 */
function FetchOdsOutrightsWithFallback($DB_OFFER, $ods_schema)
{
    global $PG_HOST, $PG_PORT, $PG_USER, $PG_PASSWORD, $PG_ODS_HOST, $PG_ODS_PORT, $PG_ODS_DBNAME, $PG_ODS_USER, $PG_ODS_PASSWORD;

    $info = array(
        'rows' => array(),
        'via' => '',
        'error' => null,
    );

    try {
        $info['rows'] = FetchOdsOutrights($DB_OFFER, $ods_schema);
        $info['via'] = 'same Postgres connection, schema ' . $ods_schema;
        return $info;
    } catch (Exception $e) {
        $first = $e->getMessage();
    }

    $host = isset($PG_ODS_HOST) ? $PG_ODS_HOST : $PG_HOST;
    $port = isset($PG_ODS_PORT) ? $PG_ODS_PORT : $PG_PORT;
    $user = isset($PG_ODS_USER) ? $PG_ODS_USER : $PG_USER;
    $pass = isset($PG_ODS_PASSWORD) ? $PG_ODS_PASSWORD : $PG_PASSWORD;
    $dbname = isset($PG_ODS_DBNAME) && $PG_ODS_DBNAME !== '' ? $PG_ODS_DBNAME : $ods_schema;

    $DB_ODS = null;
    try {
        $DB_ODS = new Postgres();
        $DB_ODS->connect($host, $port, $dbname, $user, $pass);
        $info['rows'] = FetchOdsOutrights($DB_ODS, $ods_schema);
        $info['via'] = 'second connection db=' . $dbname . ' schema=' . $ods_schema;
        pg_try_disconnect($DB_ODS);
        return $info;
    } catch (Exception $e2) {
        pg_try_disconnect($DB_ODS);
        $info['error'] = 'ODS on existing connection: ' . $first . ' | ODS second connection (' . $dbname . '): ' . $e2->getMessage();
        return $info;
    }
}

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
        $event_name = row_val($row, 'EVENT_NAME');
        if (strpos($event_name, ' v ') !== false) {
            continue;
        }
        echo '<tr class="row">';
        echo '<td>' . h(row_val($row, 'MEETING_ID')) . '</td>';
        echo '<td>' . h(row_val($row, 'MEETING_NAME')) . '</td>';
        echo '<td>' . h(row_val($row, 'EVENT_ID')) . '</td>';
        echo '<td>' . h(row_val($row, 'EVENT_NAME')) . '</td>';
        echo '<td>' . h(row_val($row, 'EVENT_TYPE')) . '</td>';
        echo '<td>' . h(row_val($row, 'EVENT_STARTING_TIME')) . '</td>';
        echo '<td>' . h(row_val($row, 'EVENT_ENDING_TIME')) . '</td>';
        echo '</tr>';
    }
    echo '</table>';
}

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
        echo '<td>' . h(row_val($row, 'COMPETITION_ID')) . '</td>';
        echo '<td>' . h(row_val($row, 'COMPETITION_NAME')) . '</td>';
        echo '<td>' . h(row_val($row, 'EVENT_ID')) . '</td>';
        echo '<td>' . h(row_val($row, 'EVENT_NAME')) . '</td>';
        echo '<td>' . h(row_val($row, 'SOURCE')) . '</td>';
        echo '<td>' . h(row_val($row, 'STATUS')) . '</td>';
        echo '<td>' . h(row_val($row, 'EVENT_STATUS')) . '</td>';
        echo '<td>' . h(row_val($row, 'TRADE_STATUS')) . '</td>';
        echo '<td>' . h(row_val($row, 'ESTARTTIME')) . '</td>';
        echo '</tr>';
    }
    echo '</table>';
}
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<meta http-equiv="refresh" content="180">
<title>NON-LIVE | Unsettled Matches (merged test)</title>
</head>
<body>
<p>Last Update at <b><?php echo h($now); ?></b> — merged test page (OFFER + ODS)</p>

<h1 style="text-align:center">NON-LIVE | Unsettled Matches (MM1)</h1>
<?php
try {
    if (!class_exists('Oracle')) {
        throw new Exception('class_database.inc.php not found next to this file');
    }
    $ora_tns  = isset($ORA_TNS) ? $ORA_TNS : 'SO_PROD.world';
    $ora_user = isset($ORA_USER) ? $ORA_USER : 'reporting';
    $ora_pass = isset($ORA_PASSWORD) ? $ORA_PASSWORD : '';
    if ($ora_pass === '') {
        throw new Exception('Set $ORA_PASSWORD in oracle_config.inc.php (or paste your live connectORA password into this file) to test MM1. TRI below does not need Oracle.');
    }
    $DB_ORACLE = new Oracle();
    $DB_ORACLE->connectORA($ora_tns, $ora_user, $ora_pass);
    LookupOracleUnsettled($DB_ORACLE);
    $DB_ORACLE->Disconnect();
} catch (Exception $e) {
    echo '<p style="color:red">MM1 error: ' . h($e->getMessage()) . '</p>';
}
?>

<h1 style="text-align:center">OFFERED Outrights (TRI) — OFFER + ODS merged</h1>
<?php
$DB_OFFER = null;
try {
    if (!class_exists('Postgres')) {
        throw new Exception('class_postgres.inc.php not found');
    }
    if (!isset($PG_HOST) || !isset($PG_DBNAME) || !isset($PG_USER)) {
        throw new Exception('pg_config.inc.php did not set $PG_HOST / $PG_DBNAME / $PG_USER');
    }

    $DB_OFFER = new Postgres();
    $DB_OFFER->connect($PG_HOST, $PG_PORT, $PG_DBNAME, $PG_USER, $PG_PASSWORD);

    $offer_error = null;
    $offer_rows = array();
    try {
        $offer_rows = FetchOfferOutrights($DB_OFFER, $PG_SCHEMA);
    } catch (Exception $e) {
        $offer_error = $e->getMessage();
    }

    $ods_info = FetchOdsOutrightsWithFallback($DB_OFFER, $PG_ODS_SCHEMA);
    $ods_rows = $ods_info['rows'];
    $ods_error = $ods_info['error'];

    $name_error = null;
    $looked_up = 0;
    $names = CompetitionNamesFromRows($offer_rows);
    $missing = MissingCompetitionIds($ods_rows, $names);
    if ($missing) {
        try {
            $extra = LookupCompetitionNames($DB_OFFER, $PG_SCHEMA, $missing);
            $looked_up = count($extra);
            foreach ($extra as $id => $name) {
                $names[$id] = $name;
            }
        } catch (Exception $e) {
            $name_error = $e->getMessage();
        }
    }
    ApplyCompetitionNames($ods_rows, $names);

    list($merged, $skipped) = MergeOutrightRows($offer_rows, $ods_rows);
    $ods_named = CountNamed($ods_rows);

    echo '<p style="text-align:center;background:#f4f4f4;padding:8px">';
    echo 'OFFER rows: <b>' . count($offer_rows) . '</b>';
    echo ' &nbsp;|&nbsp; ODS rows: <b>' . count($ods_rows) . '</b>';
    echo ' (names filled: ' . (int) $ods_named . '/' . count($ods_rows) . ', extra name lookups: ' . (int) $looked_up . ')';
    echo ' &nbsp;|&nbsp; duplicates skipped: <b>' . (int) $skipped . '</b>';
    echo ' &nbsp;|&nbsp; merged table: <b>' . count($merged) . '</b><br>';
    echo 'ODS via: ' . h($ods_info['via'] !== '' ? $ods_info['via'] : 'failed');
    echo '</p>';

    if ($offer_error) {
        echo '<p style="color:red">OFFER query error: ' . h($offer_error) . '</p>';
    }
    if ($ods_error) {
        echo '<p style="color:red">ODS query error: ' . h($ods_error) . '</p>';
        echo '<p>If ODS is a separate database, add this to pg_config.inc.php and reload:<br>';
        echo '<code>$PG_ODS_HOST = $PG_HOST;<br>$PG_ODS_PORT = $PG_PORT;<br>$PG_ODS_DBNAME = \'e3_prod_odsdb\';<br>$PG_ODS_USER = $PG_USER;<br>$PG_ODS_PASSWORD = $PG_PASSWORD;<br>$PG_ODS_SCHEMA = \'e3_prod_odsdb\';</code></p>';
    }
    if ($name_error) {
        echo '<p style="color:red">Competition name lookup error: ' . h($name_error) . '</p>';
    }

    RenderTriOutrightsTable($merged);
    pg_try_disconnect($DB_OFFER);
} catch (Exception $e) {
    echo '<p style="color:red">TRI error: ' . h($e->getMessage()) . '</p>';
    pg_try_disconnect($DB_OFFER);
}
?>
</body>
</html>
