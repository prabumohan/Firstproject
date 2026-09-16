<?php
/**
 * TRI outright helpers: OFFER + ODS queries, competition-name lookup, merge.
 *
 * Competition names for ODS rows cannot be JOINed in Postgres (ods event
 * table and competition table live in different databases). Names are
 * loaded from e3_prod_offer.competition in a second query and applied in PHP.
 */

/**
 * Allow only simple SQL identifiers (schema / table names from config).
 *
 * @param string $name
 * @return string
 */
function pg_ident($name)
{
    if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', (string) $name)) {
        throw new InvalidArgumentException('Invalid SQL identifier');
    }
    return $name;
}

/**
 * Keep only digit IDs for an IN (...) list (bigint-safe, no quoting).
 *
 * @param array $ids
 * @return string[]
 */
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
 * @param Postgres $DB
 * @param string   $query
 * @return array[]
 */
function pg_fetch_all_rows($DB, $query)
{
    $result = $DB->ExecSQL($query);
    if (method_exists($DB, 'FetchAll')) {
        return $DB->FetchAll($result);
    }
    $rows = array();
    while ($row = $DB->FetchRow($result)) {
        $rows[] = $row;
    }
    return $rows;
}

/**
 * Offered outrights still open on e3_prod_offer.
 *
 * @param Postgres $DB
 * @param string   $schema
 * @return array[]
 */
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

/**
 * ODS outright events. No competition name — that lives in another database.
 *
 * Status filter is on (must match TRI offer: currently offered).
 * ODS-only rows are then dropped unless the same event_id is offered on
 * e3_prod_offer (status 2, type 2) — so leagues we are not offering (e.g.
 * Spanish outrights) do not appear.
 *
 * @param Postgres $DB
 * @param string   $schema
 * @return array[]
 */
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

/**
 * Names already present on offer rows (avoids extra lookups where possible).
 *
 * @param array[] $rows
 * @return array  id => name
 */
function CompetitionNamesFromRows($rows)
{
    $names = array();
    foreach ($rows as $row) {
        $id = isset($row['COMPETITION_ID']) ? trim((string) $row['COMPETITION_ID']) : '';
        $name = isset($row['COMPETITION_NAME']) ? trim((string) $row['COMPETITION_NAME']) : '';
        if ($id !== '' && $name !== '') {
            $names[$id] = $name;
        }
    }
    return $names;
}

/**
 * Competition IDs on ODS rows that are still missing a name.
 *
 * @param array[] $ods_rows
 * @param array   $known_names  id => name
 * @return string[]
 */
function MissingCompetitionIds($ods_rows, $known_names)
{
    $missing = array();
    foreach ($ods_rows as $row) {
        $id = isset($row['COMPETITION_ID']) ? trim((string) $row['COMPETITION_ID']) : '';
        if ($id === '') {
            continue;
        }
        $existing = isset($row['COMPETITION_NAME']) ? trim((string) $row['COMPETITION_NAME']) : '';
        if ($existing !== '') {
            continue;
        }
        if (!isset($known_names[$id])) {
            $missing[$id] = $id;
        }
    }
    return array_values($missing);
}

/**
 * Load competition names from the OFFER database (not ODS).
 *
 * @param Postgres $DB_OFFER
 * @param string   $offer_schema
 * @param array    $ids
 * @return array  id => name
 */
function LookupCompetitionNames($DB_OFFER, $offer_schema, $ids)
{
    $ids = pg_digit_ids($ids);
    if (!$ids) {
        return array();
    }

    $schema = pg_ident($offer_schema);
    $names = array();
    $chunk_size = 500;
    foreach (array_chunk($ids, $chunk_size) as $chunk) {
        $in = implode(',', $chunk);
        $query = "SELECT id, name FROM {$schema}.competition WHERE id IN ({$in})";
        foreach (pg_fetch_all_rows($DB_OFFER, $query) as $row) {
            $id = isset($row['ID']) ? (string) $row['ID'] : '';
            if ($id !== '') {
                $names[$id] = isset($row['NAME']) ? (string) $row['NAME'] : '';
            }
        }
    }
    return $names;
}

function EventIdsFromRows($rows)
{
    $ids = array();
    foreach ($rows as $row) {
        $id = isset($row['EVENT_ID']) ? (string) $row['EVENT_ID'] : '';
        if ($id !== '') {
            $ids[$id] = $id;
        }
    }
    return $ids;
}

function LookupTriOfferedEventIds($DB, $schema, $ids)
{
    $ids = pg_digit_ids($ids);
    if (!$ids) {
        return array();
    }
    $schema = pg_ident($schema);
    $offered = array();
    foreach (array_chunk($ids, 500) as $chunk) {
        $in = implode(',', $chunk);
        $query = "SELECT id FROM {$schema}.event
                  WHERE id IN ({$in})
                    AND estatus->>'mb' IN ('2')
                    AND type = '2'";
        foreach (pg_fetch_all_rows($DB, $query) as $row) {
            $id = isset($row['ID']) ? (string) $row['ID'] : '';
            if ($id !== '') {
                $offered[$id] = true;
            }
        }
    }
    return $offered;
}

function KeepOdsOfferedOnTri($ods_rows, $offer_event_ids, $tri_offered_ids)
{
    $kept = array();
    $dropped = 0;
    foreach ($ods_rows as $row) {
        $eid = isset($row['EVENT_ID']) ? (string) $row['EVENT_ID'] : '';
        if ($eid !== '' && isset($offer_event_ids[$eid])) {
            $kept[] = $row;
            continue;
        }
        if ($eid !== '' && isset($tri_offered_ids[$eid])) {
            $kept[] = $row;
            continue;
        }
        $dropped++;
    }
    return array($kept, $dropped);
}

/**
 * Fill COMPETITION_NAME on rows from a id => name map.
 *
 * @param array[] $rows  modified in place
 * @param array   $names
 */
function ApplyCompetitionNames(&$rows, $names)
{
    foreach ($rows as &$row) {
        $id = isset($row['COMPETITION_ID']) ? trim((string) $row['COMPETITION_ID']) : '';
        $current = isset($row['COMPETITION_NAME']) ? trim((string) $row['COMPETITION_NAME']) : '';
        if ($current === '' && $id !== '' && isset($names[$id])) {
            $row['COMPETITION_NAME'] = $names[$id];
        }
    }
    unset($row);
}

/**
 * Merge OFFER + ODS into one list.
 * Same event_id: keep the OFFER row (it already has a competition name and
 * is filtered to still-offered status 2). ODS-only events are appended.
 *
 * @param array[] $offer_rows
 * @param array[] $ods_rows
 * @return array[]
 */
function MergeOutrightRows($offer_rows, $ods_rows)
{
    $merged = array();
    $seen = array();

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
            continue;
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

    return $merged;
}

/**
 * Run both TRI queries, resolve ODS competition names in PHP, merge.
 *
 * @param Postgres      $DB_OFFER
 * @param Postgres|null $DB_ODS     null = reuse $DB_OFFER
 * @param string        $offer_schema
 * @param string        $ods_schema
 * @return array[]
 */
function LoadMergedTriOutrights($DB_OFFER, $DB_ODS, $offer_schema, $ods_schema)
{
    if ($DB_ODS === null) {
        $DB_ODS = $DB_OFFER;
    }

    $offer_rows = FetchOfferOutrights($DB_OFFER, $offer_schema);
    $ods_rows = FetchOdsOutrights($DB_ODS, $ods_schema);

    $offer_event_ids = EventIdsFromRows($offer_rows);
    $ods_only_ids = array();
    foreach ($ods_rows as $row) {
        $eid = isset($row['EVENT_ID']) ? (string) $row['EVENT_ID'] : '';
        if ($eid !== '' && !isset($offer_event_ids[$eid])) {
            $ods_only_ids[] = $eid;
        }
    }
    $tri_offered_ids = LookupTriOfferedEventIds($DB_OFFER, $offer_schema, $ods_only_ids);
    list($ods_rows, $ods_dropped) = KeepOdsOfferedOnTri($ods_rows, $offer_event_ids, $tri_offered_ids);

    $names = CompetitionNamesFromRows($offer_rows);
    $missing = MissingCompetitionIds($ods_rows, $names);
    if ($missing) {
        $looked_up = LookupCompetitionNames($DB_OFFER, $offer_schema, $missing);
        foreach ($looked_up as $id => $name) {
            $names[$id] = $name;
        }
    }
    ApplyCompetitionNames($ods_rows, $names);

    return MergeOutrightRows($offer_rows, $ods_rows);
}
