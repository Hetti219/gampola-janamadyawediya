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
// content-flag-taxonomy.php exits without ABSPATH; the mu-plugins register
// hooks at load.
define( 'ABSPATH', __DIR__ . '/' );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'DAY_IN_SECONDS', 86400 );

class WP_Error {
	private $code;
	private $message;
	public function __construct( $code = '', $message = '' ) {
		$this->code    = (string) $code;
		$this->message = (string) $message;
	}
	public function get_error_code() {
		return $this->code;
	}
	public function get_error_message() {
		return $this->message;
	}
}

function wp_date( $format, $timestamp ) {
	return gmdate( $format, (int) $timestamp );
}

// Overridable "now" so relative-time tests are deterministic. A top-level
// stub function has no scope but the global one to receive it through, so
// this is the one value that cannot be narrowed any further.
$GLOBALS['gjn_test_now'] = null;

function current_time( $type ) {
	return null !== $GLOBALS['gjn_test_now'] ? (int) $GLOBALS['gjn_test_now'] : time();
}

function is_admin() {
	return false;
}

function add_action() {}
function add_filter() {}

// --- Fake option store, term store and link builders -----------------------
// Each is read and written by the stubs below, so a test puts WordPress into
// the state it wants through fake_wp_state() rather than piecemeal globals.
$GLOBALS['gjn_options']    = array();
$GLOBALS['gjn_terms']      = array();
$GLOBALS['gjn_fail_slugs'] = array();
$GLOBALS['gjn_permalink']  = null;
$GLOBALS['gjn_categories'] = array();
$GLOBALS['gjn_tags']       = false;

/**
 * Reset the fake WordPress stores to a known state.
 *
 * @param array $terms      slug => term_id already in the database.
 * @param array $fail_slugs Slugs whose wp_insert_term() call fails.
 * @param array $options    option name => value.
 */
function fake_wp_state( array $terms = array(), array $fail_slugs = array(), array $options = array() ) {
	$GLOBALS['gjn_terms']      = $terms;
	$GLOBALS['gjn_fail_slugs'] = $fail_slugs;
	$GLOBALS['gjn_options']    = $options;
}

function get_option( $name, $default = false ) {
	return array_key_exists( $name, $GLOBALS['gjn_options'] )
		? $GLOBALS['gjn_options'][ $name ]
		: $default;
}

function update_option( $name, $value, $autoload = null ) {
	$GLOBALS['gjn_options'][ $name ] = $value;
	return true;
}

function term_exists( $term, $taxonomy = '', $parent = null ) {
	return isset( $GLOBALS['gjn_terms'][ $term ] )
		? array( 'term_id' => $GLOBALS['gjn_terms'][ $term ] )
		: null;
}

function wp_insert_term( $term, $taxonomy, $args = array() ) {
	$slug = isset( $args['slug'] ) ? $args['slug'] : $term;
	if ( in_array( $slug, $GLOBALS['gjn_fail_slugs'], true ) ) {
		return new WP_Error( 'db_insert_error', 'simulated insert failure: ' . $slug );
	}
	$GLOBALS['gjn_terms'][ $slug ] = count( $GLOBALS['gjn_terms'] ) + 1;
	return array( 'term_id' => $GLOBALS['gjn_terms'][ $slug ] );
}

function is_wp_error( $thing ) {
	return $thing instanceof WP_Error;
}

function get_permalink( $post = 0 ) {
	return null !== $GLOBALS['gjn_permalink']
		? $GLOBALS['gjn_permalink']
		: 'https://example.test/?p=' . (int) $post;
}

function home_url( $path = '' ) {
	return 'https://example.test' . $path;
}

function get_feed_link( $feed = '' ) {
	return 'https://example.test/feed/';
}

function get_the_category( $post_id = 0 ) {
	return $GLOBALS['gjn_categories'];
}

// WordPress returns '' for a term that no longer exists; term_id 0 models
// that, so the purge code's falsy-URL filter has something to drop.
function get_category_link( $category ) {
	return (int) $category > 0 ? 'https://example.test/category/' . (int) $category : '';
}

function get_the_tags( $post_id = 0 ) {
	return $GLOBALS['gjn_tags'];
}

function get_tag_link( $tag ) {
	return (int) $tag > 0 ? 'https://example.test/tag/' . (int) $tag : '';
}

// --- Code under test ------------------------------------------------------
require __DIR__ . '/../theme/gampola-janamadyawediya/inc/sinhala.php';
require __DIR__ . '/../mu-plugins/graphic-content-auto-blur.php';
require __DIR__ . '/../mu-plugins/cloudflare-purge.php';
require __DIR__ . '/../mu-plugins/content-flag-taxonomy.php';

// The backfill script runs on include unless told otherwise — it would boot
// WordPress and start spending API quota.
define( 'GJN_BT_LIB_ONLY', true );
require __DIR__ . '/../scripts/backfill-translations.php';

// --- Tiny assertion harness -----------------------------------------------
/**
 * Mutable tally, handed out by reference so test() and the summary share one
 * store without either of them reaching for a global.
 *
 * @return array{count:int,failures:string[]}
 */
function &test_tally() {
	static $tally = array(
		'count'    => 0,
		'failures' => array(),
	);
	return $tally;
}

function test( $name, $callback ) {
	$tally = &test_tally();
	$tally['count']++;
	try {
		$callback();
		echo "PASS  $name\n";
	} catch ( Throwable $e ) {
		$tally['failures'][] = $name . ' — ' . $e->getMessage();
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

// --- ncg_scaled_dimensions: the degenerate shapes ------------------------
test( 'ncg_scaled_dimensions: panorama does not round down to zero rows', function () {
	// 2 rows at 600px wide rounds to 0. imagecreatetruecolor( 600, 0 ) throws
	// a ValueError, which would abort the whole post save — so the height is
	// clamped, and the blurred image is merely useless rather than fatal.
	assertSame( array( 600, 1 ), ncg_scaled_dimensions( 4000, 2 ) );
} );

test( 'ncg_scaled_dimensions: downscales to the cap, keeping the ratio', function () {
	assertSame( array( 600, 338 ), ncg_scaled_dimensions( 1200, 675 ) );
} );

test( 'ncg_scaled_dimensions: an image at the cap is unchanged', function () {
	assertSame( array( 600, 400 ), ncg_scaled_dimensions( 600, 400 ) );
} );

test( 'ncg_scaled_dimensions: never upscales a small image', function () {
	assertSame( array( 100, 50 ), ncg_scaled_dimensions( 100, 50 ) );
} );

// --- ncg_cf_purge_urls_for_post: the API limit and the failure modes ------
test( 'ncg_cf_purge_urls_for_post: caps the list at the Cloudflare API limit', function () {
	// 3 fixed URLs plus 40 categories is well over the 30-URL per-call limit.
	$GLOBALS['gjn_categories'] = array();
	for ( $i = 1; $i <= 40; $i++ ) {
		$GLOBALS['gjn_categories'][] = (object) array( 'term_id' => $i );
	}
	$GLOBALS['gjn_tags'] = false;

	$urls = ncg_cf_purge_urls_for_post( 7 );

	assertSame( NCG_CF_PURGE_MAX_URLS, count( $urls ) );
} );

test( 'ncg_cf_purge_urls_for_post: drops failed lookups rather than sending them', function () {
	$GLOBALS['gjn_permalink']  = false;                                   // get_permalink() failed
	$GLOBALS['gjn_categories'] = array( (object) array( 'term_id' => 0 ) ); // term no longer exists
	$GLOBALS['gjn_tags']       = false;

	$urls = ncg_cf_purge_urls_for_post( 7 );

	// A false or '' in the list would be sent as a purge target; a non-string
	// would make array_unique() fatal.
	assertSame(
		array( 'https://example.test/', 'https://example.test/feed/' ),
		$urls
	);
} );

test( 'ncg_cf_purge_urls_for_post: deduplicates repeated archive URLs', function () {
	$GLOBALS['gjn_permalink']  = null;
	$GLOBALS['gjn_categories'] = array(
		(object) array( 'term_id' => 5 ),
		(object) array( 'term_id' => 5 ),
	);
	$GLOBALS['gjn_tags'] = false;

	// permalink + home + feed + one deduplicated category page.
	assertSame( 4, count( ncg_cf_purge_urls_for_post( 7 ) ) );
} );

test( 'ncg_cf_purge_urls_for_post: still purges tag archive pages', function () {
	$GLOBALS['gjn_permalink']  = null;
	$GLOBALS['gjn_categories'] = array();
	$GLOBALS['gjn_tags']       = array( (object) array( 'term_id' => 9 ) );

	$urls = ncg_cf_purge_urls_for_post( 7 );

	// permalink + home + feed + the tag page.
	assertSame( 4, count( $urls ) );
	assertSame( 'https://example.test/tag/9', $urls[3] );
} );

// --- ncg_seed_content_flag_terms: never flag a half-seeded site ----------
test( 'seed: a failed insert leaves the seeded flag unset so it retries', function () {
	fake_wp_state( array(), array( 'graphic-content', 'sensitive-topic' ) );

	ncg_seed_content_flag_terms();

	// Setting the flag here would permanently disable the graphic-content
	// gate, with no retry and no visible symptom.
	assertSame( false, get_option( 'ncg_content_flags_seeded' ), 'seeded option' );
} );

test( 'seed: a partial failure still leaves the seeded flag unset', function () {
	fake_wp_state( array(), array( 'sensitive-topic' ) );

	ncg_seed_content_flag_terms();

	assertSame( array( 'graphic-content' => 1 ), $GLOBALS['gjn_terms'], 'terms created' );
	assertSame( false, get_option( 'ncg_content_flags_seeded' ), 'seeded option' );
} );

test( 'seed: creates both terms and flags the site once they exist', function () {
	fake_wp_state();

	ncg_seed_content_flag_terms();

	assertSame( 2, count( $GLOBALS['gjn_terms'] ) );
	assertSame( '1', get_option( 'ncg_content_flags_seeded' ) );
} );

test( 'seed: an already-flagged site makes no further term queries', function () {
	fake_wp_state( array(), array(), array( 'ncg_content_flags_seeded' => '1' ) );

	ncg_seed_content_flag_terms();

	assertSame( array(), $GLOBALS['gjn_terms'] );
} );

// --- gjn_bt_parse_response: shapes that must never reach wp_insert_post ---
// A batch is written only when it is provably complete. Every one of these
// returns an error instead, because a half-written batch leaves duplicate
// drafts behind that no re-run can tell apart from real ones.

/** Wrap model output the way the generateContent API wraps it. */
function gjn_bt_envelope( $text, $finish = 'STOP' ) {
	return json_encode(
		array(
			'candidates' => array(
				array(
					'content'      => array( 'parts' => array( array( 'text' => $text ) ) ),
					'finishReason' => $finish,
				),
			),
		)
	);
}

/** One translation object, in English and comfortably over the length floor. */
function gjn_bt_item( $index ) {
	return array(
		'index'   => $index,
		'title'   => 'Article ' . $index,
		'content' => str_repeat( 'English words describing the event. ', 40 ),
		'excerpt' => 'A summary.',
	);
}

test( 'parse: a complete batch comes back keyed by index', function () {
	$by_index = gjn_bt_parse_response(
		gjn_bt_envelope( json_encode( array( gjn_bt_item( 0 ), gjn_bt_item( 1 ) ) ) ),
		2
	);

	assertSame( array( 0, 1 ), array_keys( $by_index ) );
	assertSame( 'Article 1', $by_index[1]['title'] );
} );

test( 'parse: a short batch is rejected', function () {
	// The model returned 1 of the 2 articles asked for. Writing it and moving
	// on would silently lose the other one.
	$result = gjn_bt_parse_response(
		gjn_bt_envelope( json_encode( array( gjn_bt_item( 0 ) ) ) ),
		2
	);

	assertSame( 'gjn_bt_count', $result->get_error_code() );
} );

test( 'parse: a duplicated index is rejected', function () {
	// Two objects both claiming article 0 means one article was translated
	// twice and another not at all.
	$result = gjn_bt_parse_response(
		gjn_bt_envelope( json_encode( array( gjn_bt_item( 0 ), gjn_bt_item( 0 ) ) ) ),
		2
	);

	assertSame( 'gjn_bt_shape', $result->get_error_code() );
} );

test( 'parse: a gap in the indexes is rejected', function () {
	// Right count, wrong slots — article 1 has no translation.
	$result = gjn_bt_parse_response(
		gjn_bt_envelope( json_encode( array( gjn_bt_item( 0 ), gjn_bt_item( 2 ) ) ) ),
		2
	);

	assertSame( 'gjn_bt_shape', $result->get_error_code() );
} );

test( 'parse: an empty title is rejected', function () {
	$item             = gjn_bt_item( 0 );
	$item['title']    = '';
	$other            = gjn_bt_item( 1 );
	$result           = gjn_bt_parse_response( gjn_bt_envelope( json_encode( array( $item, $other ) ) ), 2 );

	assertSame( 'gjn_bt_shape', $result->get_error_code() );
} );

test( 'parse: an empty excerpt is allowed', function () {
	// WordPress derives an excerpt from the body, so a thin one is not data
	// loss — and failing the batch would cost a request from a 20/day budget.
	$item            = gjn_bt_item( 0 );
	$item['excerpt'] = '';
	$by_index        = gjn_bt_parse_response(
		gjn_bt_envelope( json_encode( array( $item, gjn_bt_item( 1 ) ) ) ),
		2
	);

	assertSame( '', $by_index[0]['excerpt'] );
} );

test( 'parse: an untranslated article is rejected', function () {
	// The model echoed the Sinhala original back. This passes every structural
	// check, so without the script check the site fills with 154 drafts that
	// are not in English at all.
	$item            = gjn_bt_item( 0 );
	$item['content'] = str_repeat( 'ගම්පොළ ප්‍රදේශයේ පුවත් වාර්තා ', 20 );
	$result          = gjn_bt_parse_response(
		gjn_bt_envelope( json_encode( array( $item, gjn_bt_item( 1 ) ) ) ),
		2
	);

	assertSame( 'gjn_bt_untranslated', $result->get_error_code() );
} );

test( 'parse: MAX_TOKENS truncation names the reason', function () {
	// The JSON is cut off mid-array. Reporting the finish reason says why,
	// where "invalid JSON" would send you looking in the wrong place.
	$result = gjn_bt_parse_response( gjn_bt_envelope( '[{"index":0,"ti', 'MAX_TOKENS' ), 2 );

	assertSame( 'gjn_bt_finish', $result->get_error_code() );
} );

test( 'parse: an API error envelope is surfaced', function () {
	$result = gjn_bt_parse_response(
		json_encode( array( 'error' => array( 'message' => 'API key not valid' ) ) ),
		1
	);

	assertSame( 'gjn_bt_api', $result->get_error_code() );
	assertSame( true, false !== strpos( $result->get_error_message(), 'API key not valid' ) );
} );

test( 'parse: a non-JSON body is surfaced, not fatal', function () {
	// A Cloudflare or proxy error page arrives here, not a Gemini response.
	$result = gjn_bt_parse_response( '<html><body>502 Bad Gateway</body></html>', 1 );

	assertSame( 'gjn_bt_envelope', $result->get_error_code() );
} );

test( 'parse: thinking parts are not spliced into the JSON', function () {
	// Gemini 3 returns reasoning alongside the answer. Concatenating both
	// corrupts the JSON and costs the whole batch a request.
	$body = json_encode(
		array(
			'candidates' => array(
				array(
					'content'      => array(
						'parts' => array(
							array( 'text' => 'Let me consider the names…', 'thought' => true ),
							array( 'text' => json_encode( array( gjn_bt_item( 0 ) ) ) ),
						),
					),
					'finishReason' => 'STOP',
				),
			),
		)
	);

	$by_index = gjn_bt_parse_response( $body, 1 );

	assertSame( 'Article 0', $by_index[0]['title'] );
} );

// --- gjn_bt_prompt / schema / model knobs ---------------------------------
test( 'prompt: articles carry the same indexes the schema expects back', function () {
	$prompt = gjn_bt_prompt(
		array(
			array(
				'id'      => 7,
				'title'   => 'පළමු',
				'content' => 'body one',
			),
			array(
				'id'      => 9,
				'title'   => 'දෙවන',
				'content' => 'body two',
			),
		)
	);

	// The index in the prompt is what the model echoes back as `index`, and
	// that is what maps a translation onto a post. An off-by-one here
	// publishes the wrong translation against the wrong post.
	foreach ( array( 'index="0"', 'index="1"', 'පළමු', 'දෙවන', 'all 2 articles' ) as $needle ) {
		assertSame( true, false !== strpos( $prompt, $needle ), $needle );
	}
} );

test( 'schema: maxItems follows the batch size', function () {
	// Google documents no default cap on array length, so the cap has to be
	// pinned — and it has to match the batch, or a short batch is legal.
	assertSame( 10, gjn_bt_schema( 10 )['maxItems'] );
	assertSame( 5, gjn_bt_schema( 5 )['maxItems'] );
} );

test( 'thinking: Gemini 3 is turned down, Gemini 2.5 is left alone', function () {
	// "minimal" is not accepted on Gemini 3; sending it fails the request.
	assertSame( array( 'thinkingLevel' => 'low' ), gjn_bt_thinking_config( 'gemini-3.8-flash' ) );
	assertSame( array(), gjn_bt_thinking_config( 'gemini-2.5-flash-lite' ) );
} );

test( 'is_translated: a short body is not judged', function () {
	// Below the floor the ratio is noise; the editor sees the draft anyway.
	assertSame( true, gjn_bt_is_translated( 'ගම්පොළ' ) );
} );

test( 'usage: token counts are read off the response', function () {
	$usage = gjn_bt_usage(
		json_encode(
			array(
				'usageMetadata' => array(
					'promptTokenCount'     => 28411,
					'candidatesTokenCount' => 31204,
					'thoughtsTokenCount'   => 1208,
				),
			)
		)
	);

	assertSame( 28411, $usage['prompt'] );
	assertSame( 31204, $usage['output'] );
	assertSame( 1208, $usage['thinking'] );
} );

test( 'usage: a response without metadata does not warn', function () {
	assertSame(
		array(
			'prompt'   => 0,
			'output'   => 0,
			'thinking' => 0,
		),
		gjn_bt_usage( '<html>502</html>' )
	);
} );

// --- Summary --------------------------------------------------------------
$tally = &test_tally();
echo "\n{$tally['count']} tests, " . count( $tally['failures'] ) . " failures.\n";
if ( ! empty( $tally['failures'] ) ) {
	fwrite( STDERR, "Failing tests:\n" );
	foreach ( $tally['failures'] as $failure ) {
		fwrite( STDERR, "  - $failure\n" );
	}
	exit( 1 );
}
exit( 0 );
