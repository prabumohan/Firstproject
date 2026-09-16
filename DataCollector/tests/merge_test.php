<?php
/**
 * CLI tests for TRI outright merge + competition-name lookup.
 *
 * Run: php DataCollector/tests/merge_test.php
 */
error_reporting(E_ALL);
ini_set('display_errors', '1');

require_once dirname(__DIR__) . '/lib_outrights.inc.php';

$failures = 0;

function assert_true($cond, $msg)
{
    global $failures;
    if ($cond) {
        echo "PASS  $msg\n";
        return;
    }
    $failures++;
    echo "FAIL  $msg\n";
}

function assert_eq($actual, $expected, $msg)
{
    if ($actual === $expected) {
        assert_true(true, $msg);
        return;
    }
    assert_true(false, $msg . ' (expected ' . var_export($expected, true) . ', got ' . var_export($actual, true) . ')');
}

class FakePostgres
{
    public $queries = array();
    /** @var array  query-substring => rows */
    private $responses = array();

    public function __construct($responses)
    {
        $this->responses = $responses;
    }

    public function ExecSQL($query)
    {
        $this->queries[] = $query;
        foreach ($this->responses as $needle => $rows) {
            if ($needle === '*' || strpos($query, $needle) !== false) {
                return $rows;
            }
        }
        throw new Exception('No fake response for query: ' . $query);
    }

    public function FetchAll($result)
    {
        return $result;
    }

    public function FetchRow($result)
    {
        return false;
    }
}

// --- pg_ident ---
assert_eq(pg_ident('e3_prod_offer'), 'e3_prod_offer', 'pg_ident accepts schema name');
try {
    pg_ident('e3_prod_offer; drop table x');
    assert_true(false, 'pg_ident rejects injection');
} catch (InvalidArgumentException $e) {
    assert_true(true, 'pg_ident rejects injection');
}

// --- pg_digit_ids ---
assert_eq(pg_digit_ids(array('10', ' 11 ', 'abc', '', '12')), array('10', '11', '12'), 'pg_digit_ids keeps numeric ids');
assert_eq(pg_digit_ids(array('999999999999')), array('999999999999'), 'pg_digit_ids keeps bigint as string');

// --- names from offer rows ---
$offer = array(
    array(
        'COMPETITION_ID' => '100',
        'COMPETITION_NAME' => 'Premier League',
        'EVENT_ID' => '1',
        'EVENT_NAME' => 'Winner 2026',
        'SOURCE' => 'OFFER',
        'STATUS' => 'OFFERED',
        'TRADE_STATUS' => 'TRADABLE',
        'ESTARTTIME' => '01/01/2026 12:00:00',
    ),
);
$ods = array(
    array(
        'COMPETITION_ID' => '100',
        'COMPETITION_NAME' => '',
        'EVENT_ID' => '2',
        'EVENT_NAME' => 'Top Scorer',
        'SOURCE' => 'ODS',
        'STATUS' => 'OFFERED',
        'TRADE_STATUS' => 'NONTRADABLE/HIDDEN',
        'ESTARTTIME' => '02/01/2026 12:00:00',
    ),
    array(
        'COMPETITION_ID' => '200',
        'COMPETITION_NAME' => '',
        'EVENT_ID' => '3',
        'EVENT_NAME' => 'Championship Winner',
        'SOURCE' => 'ODS',
        'STATUS' => 'OFFERED',
        'TRADE_STATUS' => 'UNKNOWN',
        'ESTARTTIME' => '03/01/2026 12:00:00',
    ),
    array(
        'COMPETITION_ID' => '100',
        'COMPETITION_NAME' => '',
        'EVENT_ID' => '1',
        'EVENT_NAME' => 'Winner 2026 (ods copy)',
        'SOURCE' => 'ODS',
        'STATUS' => 'OFFERED',
        'TRADE_STATUS' => 'TRADABLE',
        'ESTARTTIME' => '01/01/2026 12:00:00',
    ),
);

$from_offer = CompetitionNamesFromRows($offer);
assert_eq($from_offer['100'], 'Premier League', 'name reused from offer rows');

$missing = MissingCompetitionIds($ods, $from_offer);
sort($missing);
assert_eq($missing, array('200'), 'only competition 200 needs a second query');

$ods_named = $ods;
ApplyCompetitionNames($ods_named, array('100' => 'Premier League', '200' => 'Championship'));
assert_eq($ods_named[0]['COMPETITION_NAME'], 'Premier League', 'ODS row gets name for id 100');
assert_eq($ods_named[1]['COMPETITION_NAME'], 'Championship', 'ODS row gets name for id 200');
assert_eq($ods_named[2]['COMPETITION_NAME'], 'Premier League', 'duplicate ODS row also gets name');

$merged = MergeOutrightRows($offer, $ods_named);
$ids = array();
foreach ($merged as $row) {
    $ids[] = $row['EVENT_ID'];
}
assert_eq($ids, array('3', '2', '1'), 'merged sorted by event name; event 1 not duplicated');
assert_eq(count($merged), 3, 'three unique events after merge');

$by_id = array();
foreach ($merged as $row) {
    $by_id[$row['EVENT_ID']] = $row;
}
assert_eq($by_id['1']['SOURCE'], 'OFFER', 'duplicate event_id keeps OFFER row');
assert_eq($by_id['1']['EVENT_NAME'], 'Winner 2026', 'OFFER event name preserved');
assert_eq($by_id['2']['SOURCE'], 'ODS', 'ODS-only event 2 kept');
assert_eq($by_id['3']['COMPETITION_NAME'], 'Championship', 'ODS-only event keeps looked-up name');

// --- LookupCompetitionNames talks to OFFER schema, not ODS ---
$fake = new FakePostgres(array(
    'FROM e3_prod_offer.competition' => array(
        array('ID' => '200', 'NAME' => 'Championship'),
        array('ID' => '300', 'NAME' => 'League One'),
    ),
));
$looked_up = LookupCompetitionNames($fake, 'e3_prod_offer', array('200', '300', 'nope'));
assert_eq($looked_up['200'], 'Championship', 'lookup returns name 200');
assert_eq($looked_up['300'], 'League One', 'lookup returns name 300');
assert_true(strpos($fake->queries[0], 'FROM e3_prod_offer.competition') !== false, 'lookup queries offer.competition');
assert_true(strpos($fake->queries[0], 'IN (200,300)') !== false, 'lookup IN list is sanitized digits');
assert_true(strpos($fake->queries[0], 'nope') === false, 'non-numeric id is not sent to SQL');

assert_eq(LookupCompetitionNames($fake, 'e3_prod_offer', array()), array(), 'empty id list skips query');

// --- Fetch SQL shape ---
class CapturePostgres extends FakePostgres
{
    public function ExecSQL($query)
    {
        $this->queries[] = $query;
        return array();
    }
}

$cap = new CapturePostgres(array());
FetchOfferOutrights($cap, 'e3_prod_offer');
$q = $cap->queries[0];
assert_true(strpos($q, 'e3_prod_offer.event') !== false, 'offer query hits offer.event');
assert_true(strpos($q, 'JOIN e3_prod_offer.competition') !== false, 'offer query joins competition');
assert_true(strpos($q, "'OFFER' AS source") !== false, 'offer query tags source OFFER');
assert_true(strpos($q, "e.type = '2'") !== false, 'offer query filters outright type');

$cap = new CapturePostgres(array());
FetchOdsOutrights($cap, 'e3_prod_odsdb');
$q = $cap->queries[0];
assert_true(strpos($q, 'e3_prod_odsdb.odsevent') !== false, 'ods query hits odsevent');
assert_true(strpos($q, 'JOIN') === false, 'ods query does not JOIN competition');
assert_true(strpos($q, "'ODS' AS source") !== false, 'ods query tags source ODS');
assert_true(strpos($q, "e.event->>'type' = '2'") !== false, 'ods query filters outright type');
assert_true(strpos($q, "e.event->>'status' in") === false, 'ods status filter stays off');

// --- LoadMergedTriOutrights: ODS names come from offer.competition, not a JOIN ---
class RoutingPostgres
{
    public $queries = array();
    public $offer_rows;
    public $ods_rows;
    public $competition_rows;

    public function __construct($offer_rows, $ods_rows, $competition_rows)
    {
        $this->offer_rows = $offer_rows;
        $this->ods_rows = $ods_rows;
        $this->competition_rows = $competition_rows;
    }

    public function ExecSQL($query)
    {
        $this->queries[] = $query;
        if (strpos($query, '.odsevent') !== false) {
            return $this->ods_rows;
        }
        if (strpos($query, '.event ') !== false || strpos($query, ".event\n") !== false) {
            return $this->offer_rows;
        }
        if (strpos($query, '.competition') !== false) {
            $wanted = array();
            if (preg_match('/IN \(([^)]+)\)/', $query, $m)) {
                foreach (explode(',', $m[1]) as $id) {
                    $wanted[trim($id)] = true;
                }
            }
            $out = array();
            foreach ($this->competition_rows as $row) {
                if (isset($wanted[(string) $row['ID']])) {
                    $out[] = $row;
                }
            }
            return $out;
        }
        throw new Exception('Unexpected query: ' . $query);
    }

    public function FetchAll($result)
    {
        return $result;
    }
}

$router = new RoutingPostgres(
    array(
        array(
            'COMPETITION_ID' => '100',
            'COMPETITION_NAME' => 'Premier League',
            'EVENT_ID' => '1',
            'EVENT_NAME' => 'Winner 2026',
            'SOURCE' => 'OFFER',
            'STATUS' => 'OFFERED',
            'TRADE_STATUS' => 'TRADABLE',
            'ESTARTTIME' => '01/01/2026 12:00:00',
        ),
    ),
    array(
        array(
            'COMPETITION_ID' => '200',
            'COMPETITION_NAME' => '',
            'EVENT_ID' => '9',
            'EVENT_NAME' => 'Cup Winner',
            'SOURCE' => 'ODS',
            'STATUS' => 'OFFERED',
            'TRADE_STATUS' => 'TRADABLE',
            'ESTARTTIME' => '04/01/2026 12:00:00',
        ),
    ),
    array(
        array('ID' => '200', 'NAME' => 'FA Cup'),
    )
);
$loaded = LoadMergedTriOutrights($router, $router, 'e3_prod_offer', 'e3_prod_odsdb');
assert_eq(count($loaded), 2, 'load merges offer + ods');
$loaded_by_id = array();
foreach ($loaded as $row) {
    $loaded_by_id[$row['EVENT_ID']] = $row;
}
assert_eq($loaded_by_id['9']['COMPETITION_NAME'], 'FA Cup', 'load fills ODS name from offer.competition');
$joined_ods = false;
foreach ($router->queries as $query) {
    if (strpos($query, 'odsevent') !== false && stripos($query, 'JOIN') !== false) {
        $joined_ods = true;
    }
}
assert_true(!$joined_ods, 'load never JOINs odsevent to competition');

// --- Local Postgres fixture only (optional; run tests/run_local.sh first).
// Do not run these assertions against a live remote pg_config.inc.php.
$pg_config = dirname(__DIR__) . '/pg_config.inc.php';
$pg_class = dirname(__DIR__) . '/class_postgres.inc.php';
if (is_file($pg_config) && is_file($pg_class) && function_exists('pg_connect')) {
    require_once $pg_class;
    require $pg_config;
    $local_hosts = array('127.0.0.1', 'localhost', '::1');
    if (!isset($PG_HOST) || (!in_array((string) $PG_HOST, $local_hosts, true) && getenv('TRI_LOCAL_FIXTURE') !== '1')) {
        echo "SKIP local postgres fixture (pg_config.inc.php is not 127.0.0.1)\n";
    } else {
        try {
        $live = new Postgres();
        $live->connect($PG_HOST, $PG_PORT, $PG_DBNAME, $PG_USER, $PG_PASSWORD);
        $offer_live = FetchOfferOutrights($live, 'e3_prod_offer');
        $ods_live = FetchOdsOutrights($live, 'e3_prod_odsdb');
        $loaded_live = LoadMergedTriOutrights($live, $live, 'e3_prod_offer', 'e3_prod_odsdb');
        $live->Disconnect();

        $offer_names = array();
        foreach ($offer_live as $row) {
            $offer_names[] = $row['EVENT_NAME'];
        }
        sort($offer_names);
        assert_eq($offer_names, array('Alpha OFFER Only Winner', 'Echo Duplicate Winner'), 'live OFFER returns 2 included events');

        $ods_names = array();
        foreach ($ods_live as $row) {
            $ods_names[] = $row['EVENT_NAME'];
        }
        sort($ods_names);
        assert_eq(
            $ods_names,
            array('Bravo ODS Shared Comp', 'Charlie ODS Lookup Comp', 'Delta ODS Unknown Comp', 'Echo Duplicate Winner ODS copy'),
            'live ODS returns 4 included events'
        );

        $by_name = array();
        foreach ($loaded_live as $row) {
            $by_name[$row['EVENT_NAME']] = $row;
        }
        assert_eq(count($loaded_live), 5, 'live merge has 5 unique events');
        assert_true(isset($by_name['Alpha OFFER Only Winner']) && $by_name['Alpha OFFER Only Winner']['SOURCE'] === 'OFFER', 'live OFFER-only row kept');
        assert_eq($by_name['Bravo ODS Shared Comp']['COMPETITION_NAME'], 'Premier League', 'live ODS reuses OFFER competition name');
        assert_eq($by_name['Charlie ODS Lookup Comp']['COMPETITION_NAME'], 'Championship', 'live ODS name from second competition lookup');
        assert_eq(trim($by_name['Delta ODS Unknown Comp']['COMPETITION_NAME']), '', 'live unknown competitionid stays blank');
        assert_eq($by_name['Echo Duplicate Winner']['SOURCE'], 'OFFER', 'live duplicate event_id keeps OFFER row');
        assert_true(!isset($by_name['Echo Duplicate Winner ODS copy']), 'live ODS duplicate name is not in merged list');
        foreach (array('ZZ Too New OFFER', 'ZZ Too Old OFFER', 'ZZ Wrong Status OFFER', 'ZZ Wrong Type OFFER', 'ZZ Late End OFFER', 'ZZ Too New ODS', 'ZZ Too Old ODS', 'ZZ Wrong Type ODS') as $excluded) {
            assert_true(!isset($by_name[$excluded]), 'live excluded: ' . $excluded);
        }
        $joined_live = false;
        foreach ($ods_live as $row) {
            if (isset($row['COMPETITION_NAME']) && trim($row['COMPETITION_NAME']) !== '') {
                $joined_live = true;
            }
        }
        assert_true(!$joined_live, 'live ODS query does not return competition names (filled in PHP)');
        } catch (Exception $e) {
            echo "SKIP live postgres fixture: " . $e->getMessage() . "\n";
        }
    }
} else {
    echo "SKIP live postgres fixture (no pg_config.inc.php)\n";
}

if ($failures) {
    echo "\n$failures test(s) failed\n";
    exit(1);
}
echo "\nAll tests passed\n";
exit(0);
