<?php
/**
 * Auto-translate posts to English and Tamil using Google Gemini Flash API.
 *
 * Architecture: ASYNC QUEUE via WP-Cron.
 *
 * When a Sinhala post transitions to "publish", this plugin queues it for
 * translation. A WP-Cron job runs every 2 minutes and processes ONE
 * translation at a time (one language per tick). This keeps API usage
 * well under the free-tier limits (5 RPM, 20 RPD).
 *
 * Requirements:
 *   - Polylang (free) plugin installed and activated with si, en, ta languages.
 *   - GEMINI_API_KEY environment variable set (from .env → docker-compose).
 *   - WP-Cron must fire (either via page visits or a real system cron).
 *
 * Graceful degradation:
 *   - If Polylang is not active → no-op.
 *   - If GEMINI_API_KEY is empty → no-op.
 *   - If the API call fails → the job is retried on the next cron tick
 *     (up to 3 attempts per language). The Sinhala post publishes normally.
 *
 * @package Gampola_Janamadyawediya
 * @since   4.1.0
 */

defined( 'ABSPATH' ) || exit;

/* ───────────────────────────────────────────────────────────
   Configuration
   ─────────────────────────────────────────────────────────── */

/**
 * Target languages: language_code => language name for the Gemini prompt.
 * Sinhala (si) is the source language and is NOT listed here.
 */
define( 'GJN_TRANSLATE_TARGETS', array(
	'en' => 'English',
	'ta' => 'Tamil',
) );

/**
 * Gemini model to use. As of August 2026, gemini-3.7-flash is the standard.
 */
define( 'GJN_GEMINI_MODEL', 'gemini-3.7-flash' );

/**
 * Interactions API endpoint. This is the modern 2026 API surface for Gemini.
 */
define( 'GJN_GEMINI_ENDPOINT', 'https://generativelanguage.googleapis.com/v1beta/interactions' );

/**
 * Post meta key: stores the queue of remaining languages to translate.
 * Value: serialized array like ['en', 'ta'] or ['ta'] (partially done).
 */
define( 'GJN_META_QUEUE', '_gjn_translation_queue' );

/**
 * Post meta key: retry counter per language (e.g., '_gjn_retry_en' => 2).
 */
define( 'GJN_META_RETRY_PREFIX', '_gjn_retry_' );

/**
 * Post meta key: marks a post as fully translated (all languages done).
 */
define( 'GJN_META_TRANSLATED', '_gjn_translations_created' );

/**
 * Maximum retry attempts per language before giving up.
 */
define( 'GJN_MAX_RETRIES', 3 );

/**
 * Option key: tracks daily API request count to enforce budget.
 * Value: array('date' => 'Y-m-d', 'count' => int).
 */
define( 'GJN_OPTION_DAILY_USAGE', 'gjn_gemini_daily_usage' );

/**
 * Maximum API requests per day. Set below the hard limit (20) to leave headroom.
 */
define( 'GJN_DAILY_BUDGET', 18 );

/**
 * Cron hook name.
 */
define( 'GJN_CRON_HOOK', 'gjn_process_translation_queue' );

/* ───────────────────────────────────────────────────────────
   1. On Publish: Queue the post (instant, no API calls)
   ─────────────────────────────────────────────────────────── */

add_action( 'transition_post_status', 'gjn_queue_for_translation', 10, 3 );

function gjn_queue_for_translation( $new_status, $old_status, $post ) {
	// Only act on transitions TO publish.
	if ( 'publish' !== $new_status || 'publish' === $old_status ) {
		return;
	}

	// Only translate standard posts.
	if ( 'post' !== $post->post_type ) {
		return;
	}

	// Skip autosaves and revisions.
	if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
		return;
	}
	if ( wp_is_post_revision( $post->ID ) ) {
		return;
	}

	// Require Polylang.
	if ( ! function_exists( 'pll_set_post_language' ) ) {
		return;
	}

	// Require API key.
	if ( empty( gjn_get_gemini_api_key() ) ) {
		return;
	}

	// Don't queue if already translated or already queued.
	if ( get_post_meta( $post->ID, GJN_META_TRANSLATED, true ) ) {
		return;
	}
	if ( get_post_meta( $post->ID, GJN_META_QUEUE, true ) ) {
		return;
	}

	// Only translate Sinhala posts.
	if ( function_exists( 'pll_get_post_language' ) ) {
		$lang = pll_get_post_language( $post->ID, 'slug' );
		if ( $lang && 'si' !== $lang ) {
			return;
		}
	}

	// Ensure the post is tagged as Sinhala.
	pll_set_post_language( $post->ID, 'si' );

	// Queue all target languages.
	$queue = array_keys( GJN_TRANSLATE_TARGETS );
	update_post_meta( $post->ID, GJN_META_QUEUE, $queue );

	error_log( '[gjn-auto-translate] Queued post #' . $post->ID . ' for translation: ' . implode( ', ', $queue ) );

	// Ensure the cron job is scheduled.
	gjn_ensure_cron_scheduled();
}

/* ───────────────────────────────────────────────────────────
   2. WP-Cron: Process ONE translation per tick
   ─────────────────────────────────────────────────────────── */

/**
 * Register a custom 2-minute cron interval.
 */
add_filter( 'cron_schedules', 'gjn_add_cron_interval' );

function gjn_add_cron_interval( $schedules ) {
	$schedules['gjn_every_2_min'] = array(
		'interval' => 120,
		'display'  => __( 'Every 2 minutes (GJN Translation)', 'gampola-janamadyawediya' ),
	);
	return $schedules;
}

/**
 * Schedule the cron job if not already scheduled.
 */
function gjn_ensure_cron_scheduled() {
	if ( ! wp_next_scheduled( GJN_CRON_HOOK ) ) {
		wp_schedule_event( time() + 30, 'gjn_every_2_min', GJN_CRON_HOOK );
	}
}

/**
 * Cron callback: find the oldest queued post, translate ONE language, re-queue.
 */
add_action( GJN_CRON_HOOK, 'gjn_cron_process_one' );

function gjn_cron_process_one() {
	// Require Polylang.
	if ( ! function_exists( 'pll_set_post_language' ) || ! function_exists( 'pll_save_post_translations' ) ) {
		return;
	}

	// Require API key.
	$api_key = gjn_get_gemini_api_key();
	if ( empty( $api_key ) ) {
		return;
	}

	// Check daily budget.
	if ( gjn_daily_usage_exceeded() ) {
		error_log( '[gjn-auto-translate] Daily API budget (' . GJN_DAILY_BUDGET . ') exhausted. Skipping until tomorrow.' );
		return;
	}

	// Find the oldest post with a non-empty translation queue.
	$posts = get_posts( array(
		'post_type'      => 'post',
		'post_status'    => 'publish',
		'meta_key'       => GJN_META_QUEUE,
		'meta_compare'   => '!=',
		'meta_value'     => '',
		'orderby'        => 'date',
		'order'          => 'ASC',
		'posts_per_page' => 1,
		'fields'         => 'ids',
	) );

	if ( empty( $posts ) ) {
		// Nothing to translate — unschedule the cron to save resources.
		wp_clear_scheduled_hook( GJN_CRON_HOOK );
		return;
	}

	$post_id = $posts[0];
	$queue   = get_post_meta( $post_id, GJN_META_QUEUE, true );

	if ( ! is_array( $queue ) || empty( $queue ) ) {
		// Corrupt/empty queue — clean up.
		delete_post_meta( $post_id, GJN_META_QUEUE );
		return;
	}

	// Take the first language from the queue.
	$lang_code = $queue[0];
	$lang_name = GJN_TRANSLATE_TARGETS[ $lang_code ] ?? $lang_code;

	$post = get_post( $post_id );
	if ( ! $post ) {
		delete_post_meta( $post_id, GJN_META_QUEUE );
		return;
	}

	error_log( '[gjn-auto-translate] Processing post #' . $post_id . ' → ' . $lang_name );

	// Call the Gemini API.
	$translated = gjn_translate_via_gemini( $post->post_title, $post->post_content, $lang_name, $api_key );

	// Increment daily usage counter.
	gjn_increment_daily_usage();

	if ( ! $translated ) {
		// API call failed — check retry count.
		$retry_key   = GJN_META_RETRY_PREFIX . $lang_code;
		$retry_count = (int) get_post_meta( $post_id, $retry_key, true );
		$retry_count++;

		if ( $retry_count >= GJN_MAX_RETRIES ) {
			// Give up on this language.
			error_log( '[gjn-auto-translate] Giving up on ' . $lang_name . ' for post #' . $post_id . ' after ' . GJN_MAX_RETRIES . ' attempts.' );
			array_shift( $queue );
			update_post_meta( $post_id, GJN_META_QUEUE, $queue );
			delete_post_meta( $post_id, $retry_key );
		} else {
			// Retry on the next cron tick.
			update_post_meta( $post_id, $retry_key, $retry_count );
			error_log( '[gjn-auto-translate] Will retry ' . $lang_name . ' for post #' . $post_id . ' (attempt ' . $retry_count . '/' . GJN_MAX_RETRIES . ')' );
		}

		// If queue is now empty after giving up, finalize.
		if ( empty( $queue ) ) {
			gjn_finalize_post( $post_id );
		}

		return;
	}

	// Translation succeeded — create the translated post.
	$new_post_id = gjn_create_translated_post( $post, $translated, $lang_code );

	if ( $new_post_id ) {
		error_log( '[gjn-auto-translate] Created ' . $lang_name . ' translation (post #' . $new_post_id . ') for post #' . $post_id );
	}

	// Remove this language from the queue.
	array_shift( $queue );
	delete_post_meta( $post_id, GJN_META_RETRY_PREFIX . $lang_code );

	if ( ! empty( $queue ) ) {
		// More languages to go — update the queue.
		update_post_meta( $post_id, GJN_META_QUEUE, $queue );
	} else {
		// All done — finalize.
		gjn_finalize_post( $post_id );
	}
}

/* ───────────────────────────────────────────────────────────
   3. Post creation and finalization
   ─────────────────────────────────────────────────────────── */

/**
 * Create a single translated WordPress post and link it via Polylang.
 *
 * @param WP_Post $source_post The original Sinhala post.
 * @param array   $translated  Array with 'title' and 'content' keys.
 * @param string  $lang_code   Target language code (e.g., 'en', 'ta').
 * @return int|false The new post ID, or false on failure.
 */
function gjn_create_translated_post( $source_post, $translated, $lang_code ) {
	// Prevent our own hook from re-queuing the translated post.
	remove_action( 'transition_post_status', 'gjn_queue_for_translation', 10 );

	$new_post_id = wp_insert_post( array(
		'post_title'    => sanitize_text_field( $translated['title'] ),
		'post_content'  => wp_kses_post( $translated['content'] ),
		'post_status'   => 'publish',
		'post_type'     => 'post',
		'post_author'   => $source_post->post_author,
		'post_date'     => $source_post->post_date,
		'post_date_gmt' => $source_post->post_date_gmt,
	), true );

	// Re-hook immediately.
	add_action( 'transition_post_status', 'gjn_queue_for_translation', 10, 3 );

	if ( is_wp_error( $new_post_id ) ) {
		error_log( '[gjn-auto-translate] wp_insert_post failed for ' . $lang_code . ': ' . $new_post_id->get_error_message() );
		return false;
	}

	// Copy categories, tags, and featured image.
	$categories   = wp_get_post_categories( $source_post->ID );
	$tags         = wp_get_post_tags( $source_post->ID, array( 'fields' => 'ids' ) );
	$thumbnail_id = get_post_thumbnail_id( $source_post->ID );

	if ( ! empty( $categories ) ) {
		wp_set_post_categories( $new_post_id, $categories );
	}
	if ( ! empty( $tags ) ) {
		wp_set_post_tags( $new_post_id, $tags );
	}
	if ( $thumbnail_id ) {
		set_post_thumbnail( $new_post_id, $thumbnail_id );
	}

	// Set language and mark as a translation.
	pll_set_post_language( $new_post_id, $lang_code );
	update_post_meta( $new_post_id, GJN_META_TRANSLATED, '1' );

	// Link translations together.
	$existing = pll_get_post_translations( $source_post->ID );
	$existing[ $lang_code ] = $new_post_id;
	$existing['si']         = $source_post->ID;
	pll_save_post_translations( $existing );

	return $new_post_id;
}

/**
 * Finalize a post after all translations are complete (or retries exhausted).
 *
 * @param int $post_id The original Sinhala post ID.
 */
function gjn_finalize_post( $post_id ) {
	delete_post_meta( $post_id, GJN_META_QUEUE );
	update_post_meta( $post_id, GJN_META_TRANSLATED, '1' );
	error_log( '[gjn-auto-translate] Finalized post #' . $post_id . ' — translation complete.' );
}

/* ───────────────────────────────────────────────────────────
   4. Gemini API call (single, no retries — retries handled by queue)
   ─────────────────────────────────────────────────────────── */

/**
 * Send title + content to Gemini Flash for translation.
 *
 * @param string $title     Post title in Sinhala.
 * @param string $content   Post content (HTML) in Sinhala.
 * @param string $lang_name Target language name (e.g. "English").
 * @param string $api_key   Gemini API key.
 * @return array|false      Array with 'title' and 'content' keys, or false on failure.
 */
function gjn_translate_via_gemini( $title, $content, $lang_name, $api_key ) {
	$endpoint = GJN_GEMINI_ENDPOINT;

	// Strip HTML to reduce token usage, but preserve paragraph structure.
	$plain_content = wp_strip_all_tags( $content );

	$prompt = <<<PROMPT
You are a professional news translator. Translate the following Sinhala news article into {$lang_name}.

Rules:
1. Translate ONLY the text. Do not add commentary, explanations, or notes.
2. Preserve the journalistic tone and formality of the original.
3. Keep proper nouns (place names, person names) in their commonly accepted {$lang_name} form. If no standard form exists, transliterate them.
4. The "content" value should use HTML paragraph tags (<p>...</p>) to preserve paragraph structure.

Article title:
{$title}

Article content:
{$plain_content}
PROMPT;

	$body = array(
		'model' => GJN_GEMINI_MODEL,
		'input' => $prompt,
		'response_format' => array(
			'type'      => 'text',
			'mime_type' => 'application/json',
			'schema'    => array(
				'type'       => 'object',
				'properties' => array(
					'title'   => array( 'type' => 'string' ),
					'content' => array( 'type' => 'string' ),
				),
				'required'   => array( 'title', 'content' ),
			),
		),
	);

	$response = wp_remote_post( $endpoint, array(
		'headers' => array(
			'Content-Type'   => 'application/json',
			'x-goog-api-key' => $api_key,
		),
		'body'    => wp_json_encode( $body ),
		'timeout' => 90,
	) );

	if ( is_wp_error( $response ) ) {
		error_log( '[gjn-auto-translate] HTTP error for ' . $lang_name . ': ' . $response->get_error_message() );
		return false;
	}

	$status = wp_remote_retrieve_response_code( $response );
	if ( 200 !== $status ) {
		error_log( '[gjn-auto-translate] API returned HTTP ' . $status . ' for ' . $lang_name . ': ' . wp_remote_retrieve_body( $response ) );
		return false;
	}

	$data = json_decode( wp_remote_retrieve_body( $response ), true );

	// Extract from Gemini Interactions API response.
	$text = $data['output_text'] ?? '';
	if ( empty( $text ) ) {
		error_log( '[gjn-auto-translate] Empty response from Gemini for ' . $lang_name );
		return false;
	}

	// Parse the JSON.
	$translated = json_decode( $text, true );
	if ( ! is_array( $translated ) || empty( $translated['title'] ) || empty( $translated['content'] ) ) {
		// Fallback: strip markdown fences.
		$cleaned    = preg_replace( '/^```(?:json)?\s*|\s*```$/s', '', trim( $text ) );
		$translated = json_decode( $cleaned, true );

		if ( ! is_array( $translated ) || empty( $translated['title'] ) || empty( $translated['content'] ) ) {
			error_log( '[gjn-auto-translate] Could not parse Gemini JSON for ' . $lang_name . '. Raw: ' . substr( $text, 0, 500 ) );
			return false;
		}
	}

	return array(
		'title'   => $translated['title'],
		'content' => $translated['content'],
	);
}

/* ───────────────────────────────────────────────────────────
   5. Daily usage tracking
   ─────────────────────────────────────────────────────────── */

/**
 * Check if the daily API budget has been exceeded.
 *
 * @return bool True if we've hit the limit for today.
 */
function gjn_daily_usage_exceeded() {
	$usage = get_option( GJN_OPTION_DAILY_USAGE, array() );
	$today = gmdate( 'Y-m-d' );

	if ( empty( $usage['date'] ) || $usage['date'] !== $today ) {
		return false; // New day, counter resets.
	}

	return ( $usage['count'] ?? 0 ) >= GJN_DAILY_BUDGET;
}

/**
 * Increment the daily API request counter.
 */
function gjn_increment_daily_usage() {
	$usage = get_option( GJN_OPTION_DAILY_USAGE, array() );
	$today = gmdate( 'Y-m-d' );

	if ( empty( $usage['date'] ) || $usage['date'] !== $today ) {
		// New day — reset.
		$usage = array( 'date' => $today, 'count' => 1 );
	} else {
		$usage['count'] = ( $usage['count'] ?? 0 ) + 1;
	}

	update_option( GJN_OPTION_DAILY_USAGE, $usage, false );
}

/* ───────────────────────────────────────────────────────────
   6. Helpers
   ─────────────────────────────────────────────────────────── */

/**
 * Get the Gemini API key from the environment.
 *
 * @return string The API key, or empty string if not set.
 */
function gjn_get_gemini_api_key() {
	// 1. WordPress constant (can be defined in wp-config.php).
	if ( defined( 'GEMINI_API_KEY' ) && GEMINI_API_KEY ) {
		return GEMINI_API_KEY;
	}

	// 2. Environment variable (set via docker-compose).
	$env = getenv( 'GEMINI_API_KEY' );
	if ( $env ) {
		return $env;
	}

	// 3. $_ENV / $_SERVER superglobals as fallback.
	if ( ! empty( $_ENV['GEMINI_API_KEY'] ) ) {
		return $_ENV['GEMINI_API_KEY'];
	}
	if ( ! empty( $_SERVER['GEMINI_API_KEY'] ) ) {
		return $_SERVER['GEMINI_API_KEY'];
	}

	return '';
}
