<?php

/**
 * Backfill English translations of existing Sinhala posts, via the Gemini API.
 *
 * One-off, not a pipeline. The site publishes in Sinhala for a Sinhala
 * readership; AdSense will not review Sinhala content, so the existing corpus
 * is translated into English to give the reviewer something in a supported
 * language to look at. Nothing here publishes: every translation is saved as
 * a DRAFT, and a human reads it before it goes live. That human review is both
 * the quality gate and the reason this is not "scaled content abuse".
 *
 * Run inside the WordPress container — there is no WP-CLI in this stack:
 *
 *   docker compose cp scripts/backfill-translations.php wordpress:/tmp/
 *   docker compose exec wordpress php /tmp/backfill-translations.php --limit=5
 *
 * Resumable. A post is skipped once its English draft exists, so re-running
 * after a crash or an exhausted daily quota picks up where it stopped.
 * Exit codes: 0 = done, 1 = some batches failed, 2 = quota exhausted,
 * 3 = configuration problem.
 *
 * The helper functions above the runner are free of WordPress calls so
 * tests/run.php can require this file without booting WordPress.
 *
 * @package Gampola_Janamadyawediya
 */

const GJN_BT_MODEL      = 'gemini-3.8-flash';
const GJN_BT_BATCH      = 10;      // Articles per request. RPD is the binding
// limit, so bigger batches mean fewer days.
const GJN_BT_RPM        = 5;       // Free-tier requests/minute for this model.
const GJN_BT_TIMEOUT    = 60;      // wp_remote_post defaults to 5s — far too
// short for a 10-article generation.
const GJN_BT_MAX_TOKENS = 65536;   // Ceiling for thinking + output together.

/**
 * Recurring local terms, pinned so the model does not invent romanisations.
 *
 * Edit this to taste — it is the one part of the prompt that is site-specific,
 * and a wrong rendering here is repeated across every article. Terms that do
 * not appear in an article are simply ignored.
 *
 * @return array<string,string> Sinhala term => required English rendering.
 */
function gjn_bt_glossary()
{
	return array(
		'ගම්පොළ'                => 'Gampola',
		'ගම්පොළ ජනමාධ්‍යවේදියා' => 'Gampola Janamadyawediya',
		'ශ්‍රී ලංකාව'             => 'Sri Lanka',
		'ලංකාව'                 => 'Sri Lanka',
		'මහනුවර'                => 'Kandy',
		'නුවරඑළිය'              => 'Nuwara Eliya',
		'කොළඹ'                  => 'Colombo',
		'ප්‍රාදේශීය ලේකම් කාර්යාලය' => 'Divisional Secretariat',
		'ප්‍රාදේශීය සභාව'          => 'Pradeshiya Sabha',
		'පොලිසිය'               => 'Police',
		'රෝහල'                  => 'Hospital',
		'මහා මාර්ගය'            => 'Highway',
	);
}

/**
 * The translator's standing instructions.
 *
 * Documents come first and instructions last, which is the order Google
 * documents for long-input prompts: the model reads the material, then the
 * brief that applies to it.
 *
 * @return string
 */
function gjn_bt_system_instruction()
{
	$lines = array(
		'You are a professional Sinhala-to-English news translator for Gampola Janamadyawediya, a local news outlet in Gampola, Sri Lanka.',
		'',
		'Translate each article from Sinhala into English.',
		'',
		'Rules:',
		'- Translate completely. Never summarise, omit, shorten, or add facts.',
		'- Preserve every name, place, date, number, age and direct quotation exactly as stated.',
		'- Preserve the HTML markup exactly, including any <!-- wp:... --> block comments and every attribute value. Translate only the human-readable text.',
		'- Write in the register of an English-language local newspaper: plain, factual, third person.',
		'- Output English only. Never leave Sinhala text in a translation.',
		'',
		'Use exactly these renderings for recurring local terms:',
	);

	foreach (gjn_bt_glossary() as $sinhala => $english) {
		$lines[] = '- ' . $sinhala . ' => ' . $english;
	}

	return implode("\n", $lines);
}

/**
 * The response schema, sized to the batch.
 *
 * maxItems is pinned because Google publishes no default limit on array
 * length; without it the model can return fewer objects than asked and the
 * batch has to be thrown away.
 *
 * @param int $batch_size Number of articles in this request.
 * @return array
 */
function gjn_bt_schema($batch_size)
{
	return array(
		'type'     => 'ARRAY',
		'maxItems' => (int) $batch_size,
		'items'    => array(
			'type'                 => 'OBJECT',
			'properties'           => array(
				'index'   => array(
					'type'        => 'INTEGER',
					'description' => 'The index attribute of the <article> this translation came from.',
				),
				'title'   => array(
					'type'        => 'STRING',
					'description' => 'The translated headline.',
				),
				'content' => array(
					'type'        => 'STRING',
					'description' => 'The full translated article body, as HTML.',
				),
				'excerpt' => array(
					'type'        => 'STRING',
					'description' => 'A one-sentence English summary.',
				),
			),
			'required'             => array('index', 'title', 'content', 'excerpt'),
			'propertyOrdering'     => array('index', 'title', 'content', 'excerpt'),
		),
	);
}

/**
 * Build the user turn: the articles, then the task.
 *
 * @param array $articles List of array{id:int,title:string,content:string}.
 * @return string
 */
function gjn_bt_prompt(array $articles)
{
	$articles = array_values($articles);
	$out      = "<articles>\n";

	foreach ($articles as $index => $article) {
		$out .= sprintf(
			"<article index=\"%d\">\n<title>%s</title>\n<body>\n%s\n</body>\n</article>\n",
			$index,
			$article['title'],
			$article['content']
		);
	}

	$out .= "</articles>\n\n";
	$out .= sprintf(
		'Translate all %d articles above into English. Return one object per article, in the same order, with "index" set to that article\'s index attribute.',
		count($articles)
	);

	return $out;
}

/**
 * Does this read as English rather than as the Sinhala original?
 *
 * The failure this catches is the model echoing its input back — which passes
 * every structural check and would otherwise fill the site with 154 "English"
 * drafts that are still in Sinhala. A real translation keeps Sinhala only in
 * the odd transliterated name, a fraction of a percent of the text; an echo is
 * almost entirely Sinhala. The threshold sits in the gap between those.
 *
 * @param string $text Translated body.
 * @return bool
 */
function gjn_bt_is_translated($text)
{
	$text = (string) $text;

	// mbstring ships with the official WordPress image; the fallback keeps the
	// script running (with byte counts instead of character counts) if it is
	// ever stripped out. The ratio is lopsided enough that precision does not
	// matter here.
	$length = function_exists('mb_strlen') ? mb_strlen($text, 'UTF-8') : strlen($text);
	if ($length < 200) {
		return true; // Too short to judge; the editor will see it.
	}

	$sinhala = preg_match_all('/[\x{0D80}-\x{0DFF}]/u', $text);

	return (false === $sinhala) || ($sinhala / $length) < 0.05;
}

/**
 * Parse a generateContent response body into translations.
 *
 * Validates that the model returned exactly the articles it was given, each
 * exactly once. A partially-valid batch is rejected whole: writing some of a
 * batch and then retrying would duplicate drafts, and one wasted request is
 * cheaper than reconciliation.
 *
 * @param string $body     Raw HTTP response body.
 * @param int    $expected Number of articles that were sent.
 * @return array<int,array>|WP_Error Translations keyed by the article index.
 */
function gjn_bt_parse_response($body, $expected)
{
	$body     = (string) $body;
	$envelope = json_decode($body, true);

	if (! is_array($envelope)) {
		return new WP_Error(
			'gjn_bt_envelope',
			'Response was not JSON: ' . substr($body, 0, 300)
		);
	}

	if (isset($envelope['error'])) {
		$message = isset($envelope['error']['message']) ? $envelope['error']['message'] : 'unspecified';
		return new WP_Error('gjn_bt_api', 'Gemini returned an error: ' . $message);
	}

	$candidate = isset($envelope['candidates'][0]) ? $envelope['candidates'][0] : null;
	if (! is_array($candidate)) {
		return new WP_Error('gjn_bt_no_candidate', 'No candidates in the response (prompt blocked?).');
	}

	// MAX_TOKENS truncates the JSON mid-array, SAFETY and RECITATION empty it.
	// Naming the reason beats reporting "invalid JSON" for all three.
	$finish = isset($candidate['finishReason']) ? $candidate['finishReason'] : '';
	if ('' !== $finish && 'STOP' !== $finish) {
		return new WP_Error('gjn_bt_finish', 'Model stopped early: ' . $finish);
	}

	$parts = isset($candidate['content']['parts']) ? $candidate['content']['parts'] : array();
	$text  = '';
	foreach ((array) $parts as $part) {
		// Thinking parts are not the answer. Concatenating one corrupts the
		// JSON and costs the batch a request out of a 20/day budget.
		if (empty($part['thought']) && isset($part['text'])) {
			$text .= $part['text'];
		}
	}

	$items = json_decode($text, true);
	if (! is_array($items)) {
		return new WP_Error('gjn_bt_json', 'Model output was not valid JSON: ' . substr($text, 0, 200));
	}

	if (count($items) !== (int) $expected) {
		return new WP_Error(
			'gjn_bt_count',
			sprintf('Asked for %d translations, got %d.', $expected, count($items))
		);
	}

	$by_index = array();
	foreach ($items as $item) {
		if (! is_array($item) || ! isset($item['index'])) {
			return new WP_Error('gjn_bt_shape', 'A translation was missing its index.');
		}

		$index = (int) $item['index'];
		if (isset($by_index[$index])) {
			return new WP_Error('gjn_bt_shape', sprintf('Index %d appeared twice.', $index));
		}

		// An empty excerpt is not fatal — WordPress derives one from the body.
		// An empty title or body is data loss, so it fails the batch.
		foreach (array('title', 'content') as $key) {
			if (! isset($item[$key]) || ! is_string($item[$key]) || '' === trim($item[$key])) {
				return new WP_Error('gjn_bt_shape', sprintf('Translation %d has an empty %s.', $index, $key));
			}
		}

		if (! gjn_bt_is_translated($item['content'])) {
			return new WP_Error('gjn_bt_untranslated', sprintf('Translation %d is still in Sinhala.', $index));
		}

		$by_index[$index] = $item;
	}

	for ($index = 0; $index < (int) $expected; $index++) {
		if (! isset($by_index[$index])) {
			return new WP_Error('gjn_bt_shape', sprintf('No translation for article %d.', $index));
		}
	}

	ksort($by_index);

	return $by_index;
}

/**
 * Token counts from a generateContent response, for the progress line.
 *
 * Reading them off the response costs nothing. Calling countTokens() would
 * cost a request, and Google does not document whether those draw on the same
 * daily quota — 16 extra calls against a 20/day budget is not a risk worth
 * taking for a log line.
 *
 * @param string $body Raw HTTP response body.
 * @return array{prompt:int,output:int,thinking:int}
 */
function gjn_bt_usage($body)
{
	$envelope = json_decode((string) $body, true);
	$usage    = isset($envelope['usageMetadata']) ? $envelope['usageMetadata'] : array();

	return array(
		'prompt'   => isset($usage['promptTokenCount']) ? (int) $usage['promptTokenCount'] : 0,
		'output'   => isset($usage['candidatesTokenCount']) ? (int) $usage['candidatesTokenCount'] : 0,
		'thinking' => isset($usage['thoughtsTokenCount']) ? (int) $usage['thoughtsTokenCount'] : 0,
	);
}

/**
 * Thinking configuration for a model.
 *
 * Gemini 3 models think by default and must be turned down: "minimal" is not
 * accepted on them, but "low" is, and translation does not need deliberation.
 * Gemini 2.5 models do not think unless asked, so they get nothing.
 *
 * @param string $model Model name.
 * @return array
 */
function gjn_bt_thinking_config($model)
{
	return (0 === strpos($model, 'gemini-3')) ? array('thinkingLevel' => 'low') : array();
}

// ---------------------------------------------------------------------------
// Everything below talks to WordPress.
// ---------------------------------------------------------------------------

/**
 * The English draft already linked to a Sinhala post, if there is one.
 *
 * @param int $source_id Sinhala post ID.
 * @return int Draft ID, or 0.
 */
function gjn_bt_translation_id($source_id)
{
	$translations = pll_get_post_translations($source_id);

	return empty($translations['en']) ? 0 : (int) $translations['en'];
}

/**
 * Re-link drafts orphaned by a crash between wp_insert_post() and
 * pll_save_post_translations().
 *
 * Without this the next run sees no translation, translates the post again,
 * and leaves a duplicate draft behind. Harmless when there is nothing to fix.
 *
 * @return int Number of links repaired.
 */
function gjn_bt_repair_links()
{
	$orphans = get_posts(
		array(
			'post_type'      => 'post',
			'post_status'    => 'any',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'meta_key'       => '_gjn_source', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
		)
	);

	$repaired = 0;
	foreach ($orphans as $draft_id) {
		$source_id = (int) get_post_meta($draft_id, '_gjn_source', true);
		if (! $source_id || gjn_bt_translation_id($source_id)) {
			continue;
		}
		pll_set_post_language($draft_id, 'en');
		pll_save_post_translations(
			array(
				'si' => $source_id,
				'en' => $draft_id,
			)
		);
		$repaired++;
	}

	return $repaired;
}

/**
 * Save one translation as an English draft, linked to its Sinhala original.
 *
 * @param array $article     array{id:int,title:string,content:string}.
 * @param array $translation Parsed translation for this article.
 * @return int|WP_Error Draft ID.
 */
function gjn_bt_insert_draft(array $article, array $translation)
{
	$draft_id = wp_insert_post(
		array(
			'post_type'    => 'post',
			'post_status'  => 'draft',
			'post_title'   => $translation['title'],
			'post_content' => $translation['content'],
			'post_excerpt' => isset($translation['excerpt']) ? $translation['excerpt'] : '',
			// get_post_field() returns the raw column, which is a string.
			'post_author'  => (int) get_post_field('post_author', $article['id']),
		),
		true
	);

	if (is_wp_error($draft_id)) {
		return $draft_id;
	}

	// The idempotency guard: a draft carrying this meta is recognised even if
	// the process dies before the Polylang link below is written.
	update_post_meta($draft_id, '_gjn_source', $article['id']);

	pll_set_post_language($draft_id, 'en');
	pll_save_post_translations(
		array(
			'si' => $article['id'],
			'en' => $draft_id,
		)
	);

	// Carries the image so the English post looks like a real article to the
	// reviewer rather than a bare wall of text.
	$thumbnail = get_post_thumbnail_id($article['id']);
	if ($thumbnail) {
		set_post_thumbnail($draft_id, $thumbnail);
	}

	return $draft_id;
}

/**
 * One generateContent call.
 *
 * @param array  $articles Articles to translate.
 * @param string $api_key  Gemini API key.
 * @return array{body:string,code:int}|WP_Error
 */
function gjn_bt_generate(array $articles, $api_key)
{
	$payload = array(
		'system_instruction' => array(
			'parts' => array(array('text' => gjn_bt_system_instruction())),
		),
		'contents'           => array(
			array(
				'role'  => 'user',
				'parts' => array(array('text' => gjn_bt_prompt($articles))),
			),
		),
		'generationConfig'   => array(
			'responseMimeType' => 'application/json',
			'responseSchema'   => gjn_bt_schema(count($articles)),
			'maxOutputTokens'  => GJN_BT_MAX_TOKENS,
			'thinkingConfig'   => gjn_bt_thinking_config(GJN_BT_MODEL),
		),
	);

	$response = wp_remote_post(
		sprintf(
			'https://generativelanguage.googleapis.com/v1beta/models/%s:generateContent',
			GJN_BT_MODEL
		),
		array(
			'timeout' => GJN_BT_TIMEOUT,
			'headers' => array(
				'Content-Type'   => 'application/json',
				// Header rather than ?key= so the key stays out of access logs.
				'x-goog-api-key' => $api_key,
			),
			'body'    => wp_json_encode($payload),
		)
	);

	if (is_wp_error($response)) {
		return $response;
	}

	return array(
		'body' => wp_remote_retrieve_body($response),
		'code' => (int) wp_remote_retrieve_response_code($response),
	);
}

/**
 * Translate one batch and write its drafts.
 *
 * @param array  $articles Articles to translate.
 * @param string $api_key  Gemini API key.
 * @return array{written:int,usage:array}|WP_Error
 */
function gjn_bt_run_batch(array $articles, $api_key)
{
	$articles = array_values($articles);
	$response = gjn_bt_generate($articles, $api_key);

	if (is_wp_error($response)) {
		return $response;
	}

	if (429 === $response['code']) {
		return new WP_Error('gjn_bt_quota', 'HTTP 429 — quota exhausted.');
	}

	if (200 !== $response['code']) {
		return new WP_Error(
			'gjn_bt_http',
			sprintf('HTTP %d: %s', $response['code'], substr($response['body'], 0, 300))
		);
	}

	$translations = gjn_bt_parse_response($response['body'], count($articles));
	if (is_wp_error($translations)) {
		return $translations;
	}

	$written = 0;
	foreach ($articles as $index => $article) {
		$draft_id = gjn_bt_insert_draft($article, $translations[$index]);
		if (is_wp_error($draft_id)) {
			// Abandon the rest of the batch rather than leave it half written;
			// the run is resumable, so the next pass picks these up.
			return new WP_Error(
				'gjn_bt_insert',
				sprintf('Post %d: %s (%d of %d drafts written)', $article['id'], $draft_id->get_error_message(), $written, count($articles))
			);
		}
		$written++;
	}

	return array(
		'written' => $written,
		'usage'   => gjn_bt_usage($response['body']),
	);
}

/**
 * Run the backfill.
 *
 * @param array $argv Raw argv.
 * @return int Exit code.
 */
function gjn_bt_main(array $argv)
{
	$limit = 0;
	foreach (array_slice($argv, 1) as $arg) {
		if (0 === strpos($arg, '--limit=')) {
			$limit = (int) substr($arg, strlen('--limit='));
		}
	}

	$api_key = getenv('GEMINI_API_KEY');
	if (! $api_key) {
		fwrite(STDERR, "GEMINI_API_KEY is not set in the WordPress container's environment.\n");
		return 3;
	}

	if (! function_exists('pll_save_post_translations')) {
		fwrite(STDERR, "Polylang is not active — it provides the language links this script writes.\n");
		return 3;
	}

	// Without English configured, pll_set_post_language() no-ops silently and
	// every draft is left unlinked. The next run would read those as "not
	// translated yet" and translate the whole corpus a second time.
	if (! in_array('en', (array) pll_languages_list(), true)) {
		fwrite(STDERR, "Polylang has no English language configured. Add it under Languages first.\n");
		return 3;
	}

	$repaired = gjn_bt_repair_links();
	if ($repaired) {
		printf("Repaired %d draft link(s) left by an earlier incomplete run.\n", $repaired);
	}

	$ids     = get_posts(
		array(
			'post_type'      => 'post',
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'orderby'        => 'ID',
			'order'          => 'ASC',
		)
	);
	$pending = array();

	foreach ($ids as $id) {
		// Polylang stores the language per post; an English post is either a
		// translation already made or something written directly in English.
		if ('en' === pll_get_post_language($id)) {
			continue;
		}
		if (gjn_bt_translation_id($id)) {
			continue;
		}
		$pending[] = array(
			'id'      => $id,
			'title'   => get_the_title($id),
			'content' => get_post_field('post_content', $id),
		);
	}

	// Trim before chunking, not after, so --limit=5 sends 5 articles rather
	// than one 10-article batch, and the schema's maxItems still matches.
	if ($limit > 0) {
		$pending = array_slice($pending, 0, $limit);
	}

	$batches = array_chunk($pending, GJN_BT_BATCH);

	printf(
		"gjn backfill — %s\n  %d Sinhala posts pending, %d batch(es) of up to %d\n\n",
		GJN_BT_MODEL,
		count($pending),
		count($batches),
		GJN_BT_BATCH
	);

	if (! $batches) {
		echo "Nothing to do.\n";
		return 0;
	}

	// 5 requests/minute means a request may not start within 12s of the last.
	// Generous on purpose: a 429 mid-run costs the whole remaining day.
	$pace      = (int) ceil(60 / GJN_BT_RPM) + 1;
	$last_call = 0.0;
	$failed    = 0;
	$total     = count($batches);

	foreach ($batches as $number => $batch) {
		$wait = $pace - (microtime(true) - $last_call);
		if ($last_call > 0 && $wait > 0) {
			sleep((int) ceil($wait));
		}
		$started   = microtime(true);
		$last_call = $started;

		$result = gjn_bt_run_batch($batch, $api_key);
		$label  = sprintf('[%2d/%d] %d article(s)', $number + 1, $total, count($batch));

		if (is_wp_error($result)) {
			printf("%s  FAILED — %s\n", $label, $result->get_error_message());

			if ('gjn_bt_quota' === $result->get_error_code()) {
				printf("\nDaily quota exhausted. Re-run after midnight Pacific time; drafts already written are kept.\n");
				return 2;
			}

			$failed++;
			continue;
		}

		printf(
			"%s  %d draft(s) in %s / %s out (%s thinking) | %ds\n",
			$label,
			$result['written'],
			number_format($result['usage']['prompt']),
			number_format($result['usage']['output']),
			number_format($result['usage']['thinking']),
			(int) (microtime(true) - $started)
		);
	}

	if ($failed) {
		printf("\n%d of %d batches failed. Re-run to retry them.\n", $failed, $total);
		return 1;
	}

	printf("\nDone. %d drafts are waiting for review in Posts > Drafts.\n", count($pending));
	return 0;
}

// Only run when executed directly — tests/run.php requires this file for the
// helpers and defines GJN_BT_LIB_ONLY to keep it from firing.
if (! defined('GJN_BT_LIB_ONLY')) {
	require_once '/var/www/html/wp-load.php';
	exit(gjn_bt_main(isset($_SERVER['argv']) ? (array) $_SERVER['argv'] : array()));
}
