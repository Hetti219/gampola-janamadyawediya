<?php
/**
 * Zero-dependency smoke tests for pure helpers and failure modes.
 *
 * Runs without WordPress: stubs the handful of WP functions the included
 * files touch at load time, then exercises the real logic — including the
 * that a happy-path test would miss.
 *
 * Usage:  php tests/run.php
 * Exit:   0 = all passed, 1 = one or more failures.
 */

// --- Minimal WordPress surface for the included files ---------------------
// sinhala.php exits without ABSPATH; the mu-plugin registers hooks at load.
define( 'ABSPATH', __DIR__ . '/' );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'DAY_IN_SECONDS', 86400 );

function wp_date( $format, $timestamp ) {
	return gmdate( $format, (int) $timestamp );
}

// Overridable "now" so relative-time tests are deterministic (see tests below).
function current_time( $type ) {
	return isset( $GLOBALS['gjn_test_now'] ) ? (int) $GLOBALS['gjn_test_now'] : time();
}

function is_admin() {
	return false;
}

function add_action() {}
function add_filter() {}

// --- Code under test ------------------------------------------------------
require __DIR__ . '/../theme/gampola-janamadyawediya/inc/sinhala.php';
require __DIR__ . '/../mu-plugins/graphic-content-auto-blur.php';

// --- Tiny assertion harness -----------------------------------------------
$GLOBALS['test_count'] = 0;
$GLOBALS['test_failures'] = array();

function test( $name, $callback ) {
	$GLOBALS['test_count']++;
	try {
		$callback();
		echo "PASS  $name\n";
	} catch ( Throwable $e ) {
		$GLOBALS['test_failures'][] = $name . ' — ' . $e->getMessage();
		echo "FAIL  $name — {$e->getMessage()}\n";
	}
}

function assertSame( $expected, $actual, $label = 'value' ) {
	if ( $expected !== $actual ) {
		throw new RuntimeException(
			sprintf(
				'expected %s %s, got %s',
				$label,
				var_export( $expected, true ),
				var_export( $actual, true )
			)
		);
	}
}

// --- gjn_sinhala_date -----------------------------------------------------
// 2026-09-06 is a Sunday; anchors are computed backwards from it.
test( 'gjn_sinhala_date: full form with weekday', function () {
	assertSame(
		'ඉරිදා, 16 අගෝස්තු 2026',
		gjn_sinhala_date( strtotime( '2026-08-16 00:00:00 UTC' ) )
	);
} );

test( 'gjn_sinhala_date: month rollover (Jan 1, weekday name)', function () {
	assertSame(
		'බ්‍රහස්පතින්දා, 1 ජනවාරි 2026',
		gjn_sinhala_date( strtotime( '2026-01-01 00:00:00 UTC' ) )
	);
} );

test( 'gjn_sinhala_date: no-weekday form', function () {
	assertSame(
		'16 අගෝස්තු 2026',
		gjn_sinhala_date( strtotime( '2026-08-16 00:00:00 UTC' ), false )
	);
} );

// --- gjn_relative_time: boundaries and failure modes ----------------------
$GLOBALS['gjn_test_now'] = strtotime( '2026-09-06 12:00:00 UTC' );
$NOW = $GLOBALS['gjn_test_now'];

test( 'gjn_relative_time: under a minute', function () use ( $NOW ) {
	assertSame( 'දැන්ම', gjn_relative_time( $NOW - 59 ) );
} );

test( 'gjn_relative_time: exactly one minute', function () use ( $NOW ) {
	assertSame( 'මිනිත්තු 1කට පෙර', gjn_relative_time( $NOW - 60 ) );
} );

test( 'gjn_relative_time: rounds 90 minutes up to 2 hours', function () use ( $NOW ) {
	assertSame( 'පැය 2කට පෙර', gjn_relative_time( $NOW - 90 * 60 ) );
} );

test( 'gjn_relative_time: days', function () use ( $NOW ) {
	assertSame( 'දින 2කට පෙර', gjn_relative_time( $NOW - 2 * DAY_IN_SECONDS ) );
} );

test( 'gjn_relative_time: just under the 30-day cutoff stays in days', function () use ( $NOW ) {
	assertSame( 'දින 29කට පෙර', gjn_relative_time( $NOW - ( 29 * DAY_IN_SECONDS + 3600 ) ) );
} );

test( 'gjn_relative_time: at 30 days falls back to the full date', function () use ( $NOW ) {
	$ts = $NOW - 30 * DAY_IN_SECONDS;
	assertSame( gjn_sinhala_date( $ts, false ), gjn_relative_time( $ts ) );
} );

test( 'gjn_relative_time: future timestamp (failure mode) does not break', function () use ( $NOW ) {
	// Negative diff — must not emit negative numbers or fatal.
	assertSame( 'දැන්ම', gjn_relative_time( $NOW + 3600 ) );
} );

// --- Summary --------------------------------------------------------------
echo "\n{$GLOBALS['test_count']} tests, " . count( $GLOBALS['test_failures'] ) . " failures.\n";
if ( ! empty( $GLOBALS['test_failures'] ) ) {
	fwrite( STDERR, "Failing tests:\n" );
	foreach ( $GLOBALS['test_failures'] as $failure ) {
		fwrite( STDERR, "  - $failure\n" );
	}
	exit( 1 );
}
exit( 0 );
