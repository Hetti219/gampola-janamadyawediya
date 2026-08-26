<?php
/**
 * Auto-translate posts to English and Tamil using Google Gemini Flash API.
 *
 * When a Sinhala post transitions to "publish" for the first time, this
 * plugin sends the title and content to Gemini Flash, creates translated
 * WordPress posts, and links them via Polylang.
 *
 * Requirements:
 *   - Polylang (free) plugin installed and activated with si, en, ta languages.
 *   - GEMINI_API_KEY environment variable set (from .env → docker-compose).
 *
 * Graceful degradation:
 *   - If Polylang is not active → no-op.
 *   - If GEMINI_API_KEY is empty → no-op.
 *   - If the API call fails → the Sinhala post publishes normally,
 *     translations just don't appear. Errors are logged via error_log().
 *
 * @package Gampola_Janamadyawediya
 * @since   4.0.0
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
 * Gemini model to use. As of August 2026, gemini-3.7-flash is the standard for fast, high-quality tasks.
 */
define( 'GJN_GEMINI_MODEL', 'gemini-3.7-flash' );

/**
 * Interactions API endpoint. This is the modern 2026 API surface for Gemini.
 */
define( 'GJN_GEMINI_ENDPOINT', 'https://generativelanguage.googleapis.com/v1beta/interactions' );

/**
 * Post meta key used to mark a post as "translations already created".
 */
define( 'GJN_META_TRANSLATED', '_gjn_translations_created' );

/* ───────────────────────────────────────────────────────────
   Hook: transition_post_status
   ─────────────────────────────────────────────────────────── */

add_action( 'transition_post_status', 'gjn_auto_translate_on_publish', 10, 3 );

/**
 * When a post transitions to "publish", auto-translate it.
 *
 * @param string  $new_status New post status.
 * @param string  $old_status Old post status.
 * @param WP_Post $post       The post object.
 */
function gjn_auto_translate_on_publish( $new_status, $old_status, $post ) {
	// 1. Only act on transitions TO publish (not re-saves of already-published posts).
	if ( 'publish' !== $new_status || 'publish' === $old_status ) {
		return;
	}

	// 2. Only translate standard posts (not pages, attachments, etc.).
	if ( 'post' !== $post->post_type ) {
		return;
	}

	// 3. Skip autosaves and revisions.
	if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
		return;
	}
	if ( wp_is_post_revision( $post->ID ) ) {
		return;
	}

	// 4. Require Polylang.
	if ( ! function_exists( 'pll_set_post_language' ) || ! function_exists( 'pll_save_post_translations' ) ) {
		return;
	}

	// 5. Require API key.
	$api_key = gjn_get_gemini_api_key();
	if ( empty( $api_key ) ) {
		return;
	}

	// 6. Don't translate twice.
	if ( get_post_meta( $post->ID, GJN_META_TRANSLATED, true ) ) {
		return;
	}

	// 7. Only translate posts that are in Sinhala (or have no language set yet).
	if ( function_exists( 'pll_get_post_language' ) ) {
		$lang = pll_get_post_language( $post->ID, 'slug' );
		if ( $lang && 'si' !== $lang ) {
			return; // This post is already a translation — don't translate it again.
		}
	}

	// 8. Perform the translation.
	gjn_create_translations( $post, $api_key );
}

/* ───────────────────────────────────────────────────────────
   Core: create translations for a post
   ─────────────────────────────────────────────────────────── */

/**
 * Translate a Sinhala post into all target languages and link via Polylang.
 *
 * @param WP_Post $post    The original Sinhala post.
 * @param string  $api_key Gemini API key.
 */
function gjn_create_translations( $post, $api_key ) {
	// Prevent infinite loop: unhook ourselves before inserting translated posts.
	remove_action( 'transition_post_status', 'gjn_auto_translate_on_publish', 10 );

	// Ensure the original post is tagged as Sinhala in Polylang.
	pll_set_post_language( $post->ID, 'si' );

	// Collect translation post IDs: start with the original.
	$translations = array( 'si' => $post->ID );

	// Get post metadata to copy to translations.
	$categories    = wp_get_post_categories( $post->ID );
	$tags          = wp_get_post_tags( $post->ID, array( 'fields' => 'ids' ) );
	$thumbnail_id  = get_post_thumbnail_id( $post->ID );

	$is_first = true;
	foreach ( GJN_TRANSLATE_TARGETS as $lang_code => $lang_name ) {
		// Stagger API calls to stay under the free-tier RPM limit (5 RPM).
		// Wait 15 seconds between calls so we never exceed 4 requests/minute.
		if ( ! $is_first ) {
			sleep( 15 );
		}
		$is_first = false;

		$translated = gjn_translate_via_gemini(
			$post->post_title,
			$post->post_content,
			$lang_name,
			$api_key
		);

		if ( ! $translated ) {
			// API call failed for this language — skip it, log was already emitted.
			continue;
		}

		// Create the translated post.
		$new_post_id = wp_insert_post( array(
			'post_title'   => sanitize_text_field( $translated['title'] ),
			'post_content' => wp_kses_post( $translated['content'] ),
			'post_status'  => 'publish',
			'post_type'    => 'post',
			'post_author'  => $post->post_author,
			'post_date'    => $post->post_date,
			'post_date_gmt'=> $post->post_date_gmt,
		), true );

		if ( is_wp_error( $new_post_id ) ) {
			error_log( '[gjn-auto-translate] wp_insert_post failed for ' . $lang_code . ': ' . $new_post_id->get_error_message() );
			continue;
		}

		// Copy categories, tags, and featured image.
		if ( ! empty( $categories ) ) {
			wp_set_post_categories( $new_post_id, $categories );
		}
		if ( ! empty( $tags ) ) {
			wp_set_post_tags( $new_post_id, $tags );
		}
		if ( $thumbnail_id ) {
			set_post_thumbnail( $new_post_id, $thumbnail_id );
		}

		// Set the language in Polylang.
		pll_set_post_language( $new_post_id, $lang_code );

		// Mark as a translation so we don't re-translate it.
		update_post_meta( $new_post_id, GJN_META_TRANSLATED, '1' );

		$translations[ $lang_code ] = $new_post_id;
	}

	// Link all translations together in Polylang.
	if ( count( $translations ) > 1 ) {
		pll_save_post_translations( $translations );
	}

	// Mark the original post so we don't translate it again.
	update_post_meta( $post->ID, GJN_META_TRANSLATED, '1' );

	// Re-hook ourselves.
	add_action( 'transition_post_status', 'gjn_auto_translate_on_publish', 10, 3 );
}

/* ───────────────────────────────────────────────────────────
   Gemini API call
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
	// We'll wrap translated paragraphs back in <p> tags.
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

	// Retry with exponential backoff on 429 (rate limit) responses.
	$max_retries  = 3;
	$backoff_secs = 20; // Start with 20s — enough to reset the RPM window.

	for ( $attempt = 0; $attempt <= $max_retries; $attempt++ ) {
		if ( $attempt > 0 ) {
			$wait = $backoff_secs * pow( 2, $attempt - 1 ); // 20s, 40s, 80s
			error_log( '[gjn-auto-translate] Rate limited for ' . $lang_name . '. Retrying in ' . $wait . 's (attempt ' . ( $attempt + 1 ) . '/' . ( $max_retries + 1 ) . ')' );
			sleep( $wait );
		}

		$response = wp_remote_post( $endpoint, array(
			'headers' => array(
				'Content-Type'   => 'application/json',
				'x-goog-api-key' => $api_key,
			),
			'body'    => wp_json_encode( $body ),
			'timeout' => 90, // Generous timeout for long articles.
		) );

		if ( is_wp_error( $response ) ) {
			error_log( '[gjn-auto-translate] HTTP error for ' . $lang_name . ': ' . $response->get_error_message() );
			return false;
		}

		$status = wp_remote_retrieve_response_code( $response );

		// 429 = rate limited — retry after backoff.
		if ( 429 === $status && $attempt < $max_retries ) {
			continue;
		}

		if ( 200 !== $status ) {
			error_log( '[gjn-auto-translate] API returned HTTP ' . $status . ' for ' . $lang_name . ': ' . wp_remote_retrieve_body( $response ) );
			return false;
		}

		// Success — break out of the retry loop.
		break;
	}

	$data = json_decode( wp_remote_retrieve_body( $response ), true );

	// Extract the generated text from Gemini's Interactions API response structure.
	$text = $data['output_text'] ?? '';
	if ( empty( $text ) ) {
		error_log( '[gjn-auto-translate] Empty response from Gemini for ' . $lang_name );
		return false;
	}

	// Parse the JSON that Gemini returned. (The schema guarantees it's valid JSON).
	$translated = json_decode( $text, true );
	if ( ! is_array( $translated ) || empty( $translated['title'] ) || empty( $translated['content'] ) ) {
		// Fallback in case of markdown fences (though the schema should prevent this)
		$cleaned = preg_replace( '/^```(?:json)?\s*|\s*```$/s', '', trim( $text ) );
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
   Helpers
   ─────────────────────────────────────────────────────────── */

/**
 * Get the Gemini API key from the environment.
 *
 * Checks (in order): WordPress constant, environment variable, wp-config define.
 *
 * @return string The API key, or empty string if not set.
 */
function gjn_get_gemini_api_key() {
	// 1. Check for a WordPress constant (can be defined in wp-config.php).
	if ( defined( 'GEMINI_API_KEY' ) && GEMINI_API_KEY ) {
		return GEMINI_API_KEY;
	}

	// 2. Check environment variable (set via docker-compose).
	$env = getenv( 'GEMINI_API_KEY' );
	if ( $env ) {
		return $env;
	}

	// 3. Check $_ENV / $_SERVER superglobals as a fallback.
	if ( ! empty( $_ENV['GEMINI_API_KEY'] ) ) {
		return $_ENV['GEMINI_API_KEY'];
	}
	if ( ! empty( $_SERVER['GEMINI_API_KEY'] ) ) {
		return $_SERVER['GEMINI_API_KEY'];
	}

	return '';
}
