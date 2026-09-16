<?php
/**
 * Curl the served TRI page and assert fixture merge behaviour.
 *
 * Usage: php DataCollector/tests/assert_http.php http://127.0.0.1:8080/unsettled-Outrights-sep.php
 */
error_reporting(E_ALL);
ini_set('display_errors', '1');

$url = isset($argv[1]) ? $argv[1] : 'http://127.0.0.1:8080/unsettled-Outrights-sep.php';

$html = @file_get_contents($url);
if ($html === false) {
    fwrite(STDERR, "FAIL  could not fetch $url\n");
    exit(1);
}

$failures = 0;

function assert_contains($html, $needle, $msg)
{
    global $failures;
    if (strpos($html, $needle) !== false) {
        echo "PASS  $msg\n";
        return;
    }
    $failures++;
    echo "FAIL  $msg (missing " . var_export($needle, true) . ")\n";
}

function assert_not_contains($html, $needle, $msg)
{
    global $failures;
    if (strpos($html, $needle) === false) {
        echo "PASS  $msg\n";
        return;
    }
    $failures++;
    echo "FAIL  $msg (unexpected " . var_export($needle, true) . ")\n";
}

assert_contains($html, 'OFFERED Outrights (TRI)', 'TRI heading present');
if (strpos($html, 'MM1 skipped:') !== false || strpos($html, 'MM1 error:') !== false) {
    echo "PASS  MM1/Oracle failed gracefully (skipped or error, TRI still rendered)\n";
} else {
    $failures++;
    echo "FAIL  MM1/Oracle failed gracefully (skipped or error, TRI still rendered)\n";
}
assert_contains($html, 'OFFER: <b>2</b>', 'summary OFFER count = 2');
assert_contains($html, 'ODS fetched: <b>5</b>', 'summary ODS fetched = 5');
assert_contains($html, 'not offered on TRI (dropped): <b>2</b>', 'summary dropped ODS not on TRI = 2');
assert_contains($html, 'ODS names filled: <b>2/2</b>', 'summary ODS names filled = 2/2');
assert_contains($html, 'duplicates skipped: <b>1</b>', 'summary duplicates skipped = 1');
assert_contains($html, 'merged: <b>4</b>', 'summary merged = 4');

assert_contains($html, 'Alpha OFFER Only Winner', 'OFFER-only event listed');
assert_contains($html, 'Bravo ODS Shared Comp', 'ODS-only shared-competition event listed');
assert_contains($html, 'Charlie ODS Lookup Comp', 'ODS-only lookup-competition event listed');
assert_contains($html, 'Echo Duplicate Winner', 'duplicate event keeps OFFER name');
assert_not_contains($html, 'Delta ODS Unknown Comp', 'ODS event not offered on TRI is dropped');
assert_not_contains($html, 'Spanish La Liga Winner', 'Spanish ODS outright not offered on TRI is dropped');
assert_not_contains($html, 'Echo Duplicate Winner ODS copy', 'ODS copy of duplicate event_id is not listed');

assert_contains($html, 'Premier League', 'competition name from OFFER join / reuse');
assert_contains($html, 'Championship', 'competition name from second lookup query');

assert_not_contains($html, 'ZZ Too New OFFER', 'too-new OFFER excluded');
assert_not_contains($html, 'ZZ Too Old OFFER', 'too-old OFFER excluded');
assert_not_contains($html, 'ZZ Wrong Status OFFER', 'non-status-2 OFFER excluded');
assert_not_contains($html, 'ZZ Wrong Type OFFER', 'non-type-2 OFFER excluded');
assert_not_contains($html, 'ZZ Late End OFFER', 'late-end OFFER excluded');
assert_not_contains($html, 'ZZ Too New ODS', 'too-new ODS excluded');
assert_not_contains($html, 'ZZ Too Old ODS', 'too-old ODS excluded');
assert_not_contains($html, 'ZZ Wrong Type ODS', 'non-type-2 ODS excluded');

assert_not_contains($html, 'TRI error:', 'TRI query succeeded');
assert_not_contains($html, 'OFFER query error:', 'OFFER query succeeded');
assert_not_contains($html, 'ODS query error:', 'ODS query succeeded');
assert_not_contains($html, 'Competition name lookup error:', 'name lookup succeeded');

// Bravo (ODS, competition 100) should show Premier League; Charlie (ODS, 200) Championship.
if (preg_match('/Charlie ODS Lookup Comp.*?<\/tr>/s', $html, $m) === 1
    || preg_match('/<tr class="row">(?:(?!<\/tr>).)*Charlie ODS Lookup Comp(?:(?!<\/tr>).)*<\/tr>/s', $html, $m) === 1) {
    // row order is Competition ID, Competition Name, Event ID, Event Name, Source
}

if (!preg_match('/<tr class="row">\s*<td>200<\/td>\s*<td>Championship<\/td>\s*<td>3<\/td>\s*<td>Charlie ODS Lookup Comp<\/td>\s*<td>ODS<\/td>/s', $html)) {
    $failures++;
    echo "FAIL  Charlie row is ODS with Championship from lookup\n";
} else {
    echo "PASS  Charlie row is ODS with Championship from lookup\n";
}

if (!preg_match('/<tr class="row">\s*<td>100<\/td>\s*<td>Premier League<\/td>\s*<td>2<\/td>\s*<td>Bravo ODS Shared Comp<\/td>\s*<td>ODS<\/td>/s', $html)) {
    $failures++;
    echo "FAIL  Bravo row is ODS with Premier League reused from OFFER\n";
} else {
    echo "PASS  Bravo row is ODS with Premier League reused from OFFER\n";
}

if (!preg_match('/<tr class="row">\s*<td>999<\/td>\s*<td><\/td>\s*<td>4<\/td>\s*<td>Delta ODS Unknown Comp<\/td>\s*<td>ODS<\/td>/s', $html)) {
    echo "PASS  Delta row not listed (not offered on TRI)\n";
} else {
    $failures++;
    echo "FAIL  Delta row should have been dropped (not offered on TRI)\n";
}

if (!preg_match('/<tr class="row">\s*<td>100<\/td>\s*<td>Premier League<\/td>\s*<td>1<\/td>\s*<td>Alpha OFFER Only Winner<\/td>\s*<td>OFFER<\/td>/s', $html)) {
    $failures++;
    echo "FAIL  Alpha row is OFFER with Premier League\n";
} else {
    echo "PASS  Alpha row is OFFER with Premier League\n";
}

if (!preg_match('/<tr class="row">\s*<td>100<\/td>\s*<td>Premier League<\/td>\s*<td>10<\/td>\s*<td>Echo Duplicate Winner<\/td>\s*<td>OFFER<\/td>/s', $html)) {
    $failures++;
    echo "FAIL  Echo duplicate keeps OFFER source\n";
} else {
    echo "PASS  Echo duplicate keeps OFFER source\n";
}

if ($failures) {
    $dump = sys_get_temp_dir() . '/tri-page.html';
    file_put_contents($dump, $html);
    echo "\n$failures HTTP assertion(s) failed. Page dumped to $dump\n";
    exit(1);
}

echo "\nAll HTTP assertions passed for $url\n";
exit(0);
