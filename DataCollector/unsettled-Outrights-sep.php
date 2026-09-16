<?php
/**
 * NON-LIVE / Unsettled Matches dashboard — Oracle + PostgreSQL.
 *
 * File name matches live: unsettled-Outrights-sep.php
 * Copy this file to /var/www/sbet/dashboard/DataCollector/
 * (keep class_database.inc.php, common.inc.php, class_postgres.inc.php, pg_config.inc.php).
 *
 * TRI merges two Postgres queries in PHP:
 *   1) e3_prod_offer.event  JOIN competition  (has names)
 *   2) e3_prod_odsdb.odsevent                 (competitionid only)
 * ODS competition names are looked up from e3_prod_offer.competition
 * in a separate query — no cross-database JOIN.
 *
 * Local run against the SAME remote DBs as live (not a fixture DB):
 *   1. Copy live pg_config.inc.php into this folder (gitignored).
 *   2. CLI dashboard:  php unsettled-Outrights-sep.php
 *      Built-in server: php -S localhost:8080
 *      then open /unsettled-Outrights-sep.php
 *   Oracle class_database.inc.php / common.inc.php / TNS are optional;
 *   TRI still runs if they are missing.
 *
 * CLI data test (no HTML): php tests/remote_tri_test.php
 */
$here = dirname(__FILE__);

if (is_file($here . '/class_database.inc.php')) {
    require_once $here . '/class_database.inc.php';
}
if (is_file($here . '/common.inc.php')) {
    require_once $here . '/common.inc.php';
}
if (!class_exists('Postgres')) {
    require_once $here . '/class_postgres.inc.php';
}
if (!isset($PG_HOST)) {
    if (!is_file($here . '/pg_config.inc.php')) {
        if (defined('TRI_LIBRARY_ONLY') && TRI_LIBRARY_ONLY) {
            throw new Exception('pg_config.inc.php not found in ' . $here);
        }
        echo '<p style="color:red">pg_config.inc.php is missing. Copy it from the live DataCollector folder (same values the dashboard uses). See pg_config.example.php.</p>';
        exit(1);
    }
    require_once $here . '/pg_config.inc.php';
}
if (!isset($PG_SCHEMA) || $PG_SCHEMA === '') {
    $PG_SCHEMA = 'e3_prod_offer';
}

date_default_timezone_set('Europe/London');
$now = date('Y-m-d H:i:s');

function h($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function pg_all_rows($DB, $query)
{
    $result = $DB->ExecSQL($query);
    $rows = array();
    while ($row = $DB->FetchRow($result)) {
        $rows[] = $row;
    }
    return $rows;
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

function FetchOfferOutrights($DB_PG)
{
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
FROM e3_prod_offer.event e
JOIN e3_prod_offer.competition c ON e.competitionid = c.id
WHERE e.estarttime <= now() - interval '2 hours'
  AND e.estarttime >  now() - interval '90 days'
  AND (e.betting->'mb'->'original'->>'EndTime')::timestamp <= now() - interval '2 hours'
  AND e.estatus->>'mb' IN ('2')
  AND e.type = '2'
ORDER BY e.name";

    return pg_all_rows($DB_PG, $query);
}

function FetchOdsOutrights($DB_PG)
{
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
FROM e3_prod_odsdb.odsevent e
WHERE (e.event->'anticipated'->>'startTime')::timestamp <= now() - interval '2 hours'
  AND (e.event->'anticipated'->>'startTime')::timestamp >  now() - interval '90 days'
  AND (e.event->'betting'->>'endTime')::timestamp <= now() - interval '2 hours'
  AND e.event->>'type' = '2'
ORDER BY e.name";

    return pg_all_rows($DB_PG, $query);
}

function LookupCompetitionNames($DB_PG, $ids)
{
    $ids = pg_digit_ids($ids);
    if (!$ids) {
        return array();
    }

    $names = array();
    foreach (array_chunk($ids, 500) as $chunk) {
        $in = implode(',', $chunk);
        $query = "SELECT id, name FROM e3_prod_offer.competition WHERE id IN ({$in})";
        foreach (pg_all_rows($DB_PG, $query) as $row) {
            $id = isset($row['ID']) ? (string) $row['ID'] : '';
            if ($id !== '') {
                $names[$id] = isset($row['NAME']) ? (string) $row['NAME'] : '';
            }
        }
    }
    return $names;
}

function MergeOfferAndOds($offer_rows, $ods_rows)
{
    $names = array();
    foreach ($offer_rows as $row) {
        $id = isset($row['COMPETITION_ID']) ? (string) $row['COMPETITION_ID'] : '';
        $name = isset($row['COMPETITION_NAME']) ? trim((string) $row['COMPETITION_NAME']) : '';
        if ($id !== '' && $name !== '') {
            $names[$id] = $name;
        }
    }

    $merged = array();
    $seen = array();
    $skipped = 0;

    foreach ($offer_rows as $row) {
        $event_id = isset($row['EVENT_ID']) ? (string) $row['EVENT_ID'] : '';
        $merged[] = $row;
        if ($event_id !== '') {
            $seen[$event_id] = true;
        }
    }

    foreach ($ods_rows as $row) {
        $event_id = isset($row['EVENT_ID']) ? (string) $row['EVENT_ID'] : '';
        if ($event_id !== '' && isset($seen[$event_id])) {
            $skipped++;
            continue;
        }
        $cid = isset($row['COMPETITION_ID']) ? (string) $row['COMPETITION_ID'] : '';
        $current = isset($row['COMPETITION_NAME']) ? trim((string) $row['COMPETITION_NAME']) : '';
        if ($current === '' && $cid !== '' && isset($names[$cid])) {
            $row['COMPETITION_NAME'] = $names[$cid];
        }
        $merged[] = $row;
        if ($event_id !== '') {
            $seen[$event_id] = true;
        }
    }

    usort($merged, function ($a, $b) {
        $na = isset($a['EVENT_NAME']) ? (string) $a['EVENT_NAME'] : '';
        $nb = isset($b['EVENT_NAME']) ? (string) $b['EVENT_NAME'] : '';
        $cmp = strcasecmp($na, $nb);
        if ($cmp !== 0) {
            return $cmp;
        }
        $ia = isset($a['EVENT_ID']) ? (string) $a['EVENT_ID'] : '';
        $ib = isset($b['EVENT_ID']) ? (string) $b['EVENT_ID'] : '';
        return strcmp($ia, $ib);
    });

    return array($merged, $skipped, $names);
}

/**
 * Fetch OFFER + ODS, fill ODS competition names from e3_prod_offer.competition
 * in PHP, merge (OFFER wins on the same event_id). Returns data only — no HTML.
 *
 * If the ODS query fails on $DB_PG, retries a second connection to
 * $PG_ODS_DBNAME (or e3_prod_odsdb).
 *
 * @param Postgres $DB_PG
 * @return array
 */
function CollectTriOutrights($DB_PG)
{
    $offer_error = null;
    $ods_error = null;
    $name_error = null;
    $offer_rows = array();
    $ods_rows = array();
    $ods_retried = false;
    $ods_retry_db = null;

    try {
        $offer_rows = FetchOfferOutrights($DB_PG);
    } catch (Exception $e) {
        $offer_error = $e->getMessage();
    }

    try {
        $ods_rows = FetchOdsOutrights($DB_PG);
    } catch (Exception $e) {
        $ods_error = $e->getMessage();
        // Same host/user, different database (cannot JOIN across DBs).
        if (isset($GLOBALS['PG_HOST'])) {
            $ods_db = isset($GLOBALS['PG_ODS_DBNAME']) && $GLOBALS['PG_ODS_DBNAME'] !== ''
                ? $GLOBALS['PG_ODS_DBNAME'] : 'e3_prod_odsdb';
            $ods_host = isset($GLOBALS['PG_ODS_HOST']) && $GLOBALS['PG_ODS_HOST'] !== ''
                ? $GLOBALS['PG_ODS_HOST'] : $GLOBALS['PG_HOST'];
            $ods_port = isset($GLOBALS['PG_ODS_PORT']) && $GLOBALS['PG_ODS_PORT'] !== ''
                ? $GLOBALS['PG_ODS_PORT'] : $GLOBALS['PG_PORT'];
            $ods_user = isset($GLOBALS['PG_ODS_USER']) && $GLOBALS['PG_ODS_USER'] !== ''
                ? $GLOBALS['PG_ODS_USER'] : $GLOBALS['PG_USER'];
            $ods_pass = isset($GLOBALS['PG_ODS_PASSWORD'])
                ? $GLOBALS['PG_ODS_PASSWORD'] : $GLOBALS['PG_PASSWORD'];
            $ods_retried = true;
            $ods_retry_db = $ods_db;
            $DB_ODS = null;
            try {
                $DB_ODS = new Postgres();
                $DB_ODS->connect($ods_host, $ods_port, $ods_db, $ods_user, $ods_pass);
                $ods_rows = FetchOdsOutrights($DB_ODS);
                $ods_error = null;
                $DB_ODS->Disconnect();
            } catch (Exception $e2) {
                if ($DB_ODS) {
                    $DB_ODS->Disconnect();
                }
                $ods_error = $ods_error . ' | second connection db=' . $ods_db . ': ' . $e2->getMessage();
            }
        }
    }

    list($merged, $skipped, $names) = MergeOfferAndOds($offer_rows, $ods_rows);

    $missing = array();
    foreach ($ods_rows as $row) {
        $cid = isset($row['COMPETITION_ID']) ? (string) $row['COMPETITION_ID'] : '';
        $cname = isset($row['COMPETITION_NAME']) ? trim((string) $row['COMPETITION_NAME']) : '';
        if ($cid !== '' && $cname === '' && !isset($names[$cid])) {
            $missing[$cid] = $cid;
        }
    }

    if ($missing) {
        try {
            $extra = LookupCompetitionNames($DB_PG, array_values($missing));
            foreach ($merged as &$row) {
                if (!isset($row['SOURCE']) || $row['SOURCE'] !== 'ODS') {
                    continue;
                }
                $cid = isset($row['COMPETITION_ID']) ? (string) $row['COMPETITION_ID'] : '';
                $cname = isset($row['COMPETITION_NAME']) ? trim((string) $row['COMPETITION_NAME']) : '';
                if ($cname === '' && $cid !== '' && isset($extra[$cid])) {
                    $row['COMPETITION_NAME'] = $extra[$cid];
                }
            }
            unset($row);
        } catch (Exception $e) {
            $name_error = $e->getMessage();
        }
    }

    $ods_named = 0;
    $ods_in_table = 0;
    foreach ($merged as $row) {
        if (!isset($row['SOURCE']) || $row['SOURCE'] !== 'ODS') {
            continue;
        }
        $ods_in_table++;
        if (isset($row['COMPETITION_NAME']) && trim((string) $row['COMPETITION_NAME']) !== '') {
            $ods_named++;
        }
    }

    return array(
        'offer_rows' => $offer_rows,
        'ods_rows' => $ods_rows,
        'merged' => $merged,
        'skipped' => $skipped,
        'names' => $names,
        'offer_error' => $offer_error,
        'ods_error' => $ods_error,
        'name_error' => $name_error,
        'ods_named' => $ods_named,
        'ods_in_table' => $ods_in_table,
        'ods_retried' => $ods_retried,
        'ods_retry_db' => $ods_retry_db,
    );
}

/**
 * OFFER + ODS, competition names filled from e3_prod_offer.competition in PHP.
 *
 * @param Postgres $DB_PG
 */
function LookupPostgresOfferedOutrights($DB_PG, $schema)
{
    $tri = CollectTriOutrights($DB_PG);
    $offer_rows = $tri['offer_rows'];
    $ods_rows = $tri['ods_rows'];
    $merged = $tri['merged'];
    $skipped = $tri['skipped'];
    $offer_error = $tri['offer_error'];
    $ods_error = $tri['ods_error'];
    $name_error = $tri['name_error'];
    $ods_named = $tri['ods_named'];
    $ods_in_table = $tri['ods_in_table'];

    echo '<p style="text-align:center;background:#f4f4f4;padding:8px">';
    echo 'OFFER: <b>' . count($offer_rows) . '</b>';
    echo ' &nbsp;|&nbsp; ODS: <b>' . count($ods_rows) . '</b>';
    echo ' &nbsp;|&nbsp; ODS names filled: <b>' . $ods_named . '/' . $ods_in_table . '</b>';
    echo ' &nbsp;|&nbsp; duplicates skipped: <b>' . (int) $skipped . '</b>';
    echo ' &nbsp;|&nbsp; merged: <b>' . count($merged) . '</b>';
    echo '</p>';
    if ($offer_error) {
        echo '<p style="color:red">OFFER query error: ' . h($offer_error) . '</p>';
    }
    if ($ods_error) {
        echo '<p style="color:red">ODS query error: ' . h($ods_error) . '</p>';
        echo '<p>If ODS is a separate database, add to pg_config.inc.php: <code>$PG_ODS_DBNAME = \'e3_prod_odsdb\';</code></p>';
    }
    if ($name_error) {
        echo '<p style="color:red">Competition name lookup error: ' . h($name_error) . '</p>';
    }
    ?>
<table border="1" align="center">
<tr class="row">
  <td>Competition ID</td>
  <td>Competition Name</td>
  <td>Event ID</td>
  <td>Event Name</td>
  <td>Source</td>
  <td>Status</td>
  <td>Trade Status</td>
  <td>Event Start Time</td>
</tr>
<?php
    foreach ($merged as $row) {
        echo '<tr class="row">';
        echo '<td>' . h(isset($row['COMPETITION_ID']) ? $row['COMPETITION_ID'] : '') . '</td>';
        echo '<td>' . h(isset($row['COMPETITION_NAME']) ? $row['COMPETITION_NAME'] : '') . '</td>';
        echo '<td>' . h(isset($row['EVENT_ID']) ? $row['EVENT_ID'] : '') . '</td>';
        echo '<td>' . h(isset($row['EVENT_NAME']) ? $row['EVENT_NAME'] : '') . '</td>';
        echo '<td>' . h(isset($row['SOURCE']) ? $row['SOURCE'] : '') . '</td>';
        echo '<td>' . h(isset($row['STATUS']) ? $row['STATUS'] : '') . '</td>';
        echo '<td>' . h(isset($row['TRADE_STATUS']) ? $row['TRADE_STATUS'] : '') . '</td>';
        echo '<td>' . h(isset($row['ESTARTTIME']) ? $row['ESTARTTIME'] : '') . '</td>';
        echo '</tr>';
    }
    echo '</table>';
}

if (defined('TRI_LIBRARY_ONLY') && TRI_LIBRARY_ONLY) {
    return;
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
if (!class_exists('Oracle')) {
    echo '<p style="text-align:center">MM1 skipped: class_database.inc.php / Oracle not available. TRI below still runs against remote pg_config.</p>';
} else {
    try {
        $DB_ORACLE = new Oracle();
        $DB_ORACLE->connectORA('SO_PROD.world', 'reporting', 'R3w1nd##');
        LookupOracleUnsettled($DB_ORACLE);
        $DB_ORACLE->Disconnect();
    } catch (Exception $e) {
        $oracle_error = $e->getMessage();
        echo '<p style="color:red">MM1 error: ' . h($oracle_error) . '</p>';
    }
}
?>

<h1 style="text-align:center">OFFERED Outrights (TRI)</h1>
<?php
$pg_error = null;
try {
    $DB_PG = new Postgres();
    $DB_PG->connect($PG_HOST, $PG_PORT, $PG_DBNAME, $PG_USER, $PG_PASSWORD);
    LookupPostgresOfferedOutrights($DB_PG, $PG_SCHEMA);
    $DB_PG->Disconnect();
} catch (Exception $e) {
    $pg_error = $e->getMessage();
    echo '<p style="color:red">TRI error: ' . h($pg_error) . '</p>';
}
?>
</body>
</html>
