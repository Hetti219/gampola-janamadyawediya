<?php
/**
 * Plugin Name: Cloudflare Cache Purge
 * Description: Purges specific Cloudflare edge cache URLs when content changes
 *              so published stories appear immediately. Uses the Cloudflare
 *              purge-by-URL API (not purge-everything — no 30/month cap).
 *
 *              Reads CF_ZONE_ID and CF_API_TOKEN from environment; no-ops with
 *              an error_log notice when either is unset (safe for local dev).
 *
 * IMPORTANT — Hook priority:
 *              All hooks run at priority 99 so the existing
 *              graphic-content-auto-blur.php (priority 20) finishes generating
 *              the blurred OG image before this plugin fires a purge. If the
 *              purge ran first, Cloudflare would re-fetch and cache the page
 *              before the blurred image was attached, leaking the uncensored
 *              featured image as the og:image tag.
 */

// --- Post lifecycle hooks (priority 99) ---
// Only register on admin/API requests where content changes happen.
if ( is_admin() ) {
	add_action( 'transition_post_status', 'ncg_cf_purge_on_status_change', 99, 3 );
	add_action( 'edit_post',             'ncg_cf_purge_on_edit',          99, 2 );
	add_action( 'deleted_post',          'ncg_cf_purge_on_delete',        99, 2 );
	add_action( 'set_object_terms',      'ncg_cf_purge_on_terms',         99, 4 );

	// --- Comment hooks (priority 99) ---
	// All three pass the comment ID as their first argument, so one callback
	// serves them all. Guard: only fire if comments are enabled on the post (the
	// child theme never renders comments; these hooks are a safety net for
	// GeneratePress default).
	add_action( 'comment_post',          'ncg_cf_purge_on_comment_change', 99, 1 );
	add_action( 'wp_set_comment_status', 'ncg_cf_purge_on_comment_change', 99, 1 );
	add_action( 'edit_comment',          'ncg_cf_purge_on_comment_change', 99, 1 );
}

/**
 * Purge when a post transitions to or from "publish".
 */
function ncg_cf_purge_on_status_change( $new_status, $old_status, $post ) {
	if ( 'publish' !== $new_status && 'publish' !== $old_status ) {
		return;
	}
	if ( 'post' !== $post->post_type && 'page' !== $post->post_type ) {
		return;
	}
	ncg_cf_purge_post_urls( $post->ID );
}

/**
 * Purge on post edit (catches metadata-only changes that don't trigger
 * transition_post_status).
 */
function ncg_cf_purge_on_edit( $post_id, $post ) {
	if ( 'publish' !== $post->post_status ) {
		return;
	}
	if ( 'post' !== $post->post_type && 'page' !== $post->post_type ) {
		return;
	}
	ncg_cf_purge_post_urls( $post_id );
}

/**
 * Purge on post delete (clears the cached version of a now-deleted post).
 */
function ncg_cf_purge_on_delete( $post_id, $post ) {
	if ( 'post' !== $post->post_type && 'page' !== $post->post_type ) {
		return;
	}
	ncg_cf_purge_post_urls( $post_id );
}

/**
 * Purge when terms (categories, tags) are set — the post may now appear
 * in different archive pages.
 */
function ncg_cf_purge_on_terms( $object_id, $terms, $tt_ids, $taxonomy ) {
	if ( 'category' !== $taxonomy && 'post_tag' !== $taxonomy ) {
		return;
	}
	$post = get_post( $object_id );
	if ( ! $post || 'publish' !== $post->post_status ) {
		return;
	}
	ncg_cf_purge_post_urls( $object_id );
}

/**
 * Purge when a comment is posted, edited, or changes status.
 *
 * Serves comment_post, wp_set_comment_status, and edit_comment — all three
 * pass the comment ID first, and all three need the same "is this post still
 * showing comments?" check.
 */
function ncg_cf_purge_on_comment_change( $comment_id ) {
	$comment = get_comment( $comment_id );
	if ( ! $comment ) {
		return;
	}

	// WP_Comment stores comment_post_ID as a string; comments_open() wants an int.
	$post_id = (int) $comment->comment_post_ID;
	if ( comments_open( $post_id ) ) {
		ncg_cf_purge_post_urls( $post_id );
	}
}

// ──────────────────────────────────────────────────────────
//  Core purge logic
// ──────────────────────────────────────────────────────────

/**
 * Cloudflare's purge_cache endpoint accepts at most 30 URLs per call.
 *
 * Used as the single bound for this file: it caps each taxonomy loop as it
 * collects, and caps the finished list again. The final cap is the binding
 * one (3 fixed URLs + up to 30 categories + 30 tags can exceed it), so the
 * API limit holds no matter how the term counts add up.
 */
const NCG_CF_PURGE_MAX_URLS = 30;

/**
 * Collect the URLs whose cached copies go stale when $post_id changes.
 *
 * Every loop here is bounded by NCG_CF_PURGE_MAX_URLS, so a post with an
 * unusual number of terms cannot grow the request body without limit.
 *
 * @return string[] URLs, deduplicated, at most NCG_CF_PURGE_MAX_URLS long.
 */
function ncg_cf_purge_urls_for_post( $post_id ) {
	$urls = array();

	// The post itself, the front page, and the main feed.
	$permalink = get_permalink( $post_id );
	if ( $permalink ) {
		$urls[] = $permalink;
	}
	$urls[] = home_url( '/' );
	$urls[] = get_feed_link();

	// Category archive pages for this post's categories.
	foreach ( array_slice( get_the_category( $post_id ), 0, NCG_CF_PURGE_MAX_URLS ) as $cat ) {
		$urls[] = get_category_link( $cat->term_id );
	}

	// Tag archive pages. get_the_tags() returns false — not an empty array —
	// when the post has no tags, and (array) false is array( false ), which
	// would put a bogus term in the loop.
	$tags = get_the_tags( $post_id );
	if ( $tags ) {
		foreach ( array_slice( $tags, 0, NCG_CF_PURGE_MAX_URLS ) as $tag ) {
			$urls[] = get_tag_link( $tag->term_id );
		}
	}

	// get_permalink() returns false on failure; get_*_link() return '' when the
	// term is missing. Neither may reach the request body — and a non-string
	// would make array_unique() below fatal — so the falsy returns go here.
	$urls = array_filter( $urls );

	return array_slice( array_unique( $urls ), 0, NCG_CF_PURGE_MAX_URLS );
}

/**
 * Debounce flag — prevents duplicate purge calls within the same request
 * (e.g. save_post + set_object_terms both fire on publish).
 */
function ncg_cf_purge_post_urls( $post_id ) {
	static $purged = array();
	if ( isset( $purged[ $post_id ] ) ) {
		return;
	}
	$purged[ $post_id ] = true;

	$urls = ncg_cf_purge_urls_for_post( $post_id );
	assert( count( $urls ) <= NCG_CF_PURGE_MAX_URLS );

	ncg_cf_purge_urls( $urls );
}

/**
 * Call the Cloudflare purge-by-URL API.
 *
 * Fire-and-forget with a short timeout. If Cloudflare is unreachable,
 * the short origin TTLs (10s for front page) self-heal.
 */
function ncg_cf_purge_urls( array $urls ) {
	$zone_id   = getenv( 'CF_ZONE_ID' );
	$api_token = getenv( 'CF_API_TOKEN' );

	if ( ! $zone_id || ! $api_token ) {
		error_log( '[ncg-cf-purge] CF_ZONE_ID or CF_API_TOKEN not set — skipping Cloudflare purge.' );
		return;
	}

	if ( empty( $urls ) ) {
		return;
	}

	$api_url = sprintf(
		'https://api.cloudflare.com/client/v4/zones/%s/purge_cache',
		rawurlencode( $zone_id )
	);

	$response = wp_remote_post(
		$api_url,
		array(
			'timeout' => 5,
			'headers' => array(
				'Authorization' => 'Bearer ' . $api_token,
				'Content-Type'  => 'application/json',
			),
			'body' => wp_json_encode( array( 'files' => array_values( $urls ) ) ),
		)
	);

	if ( is_wp_error( $response ) ) {
		error_log( '[ncg-cf-purge] Cloudflare purge failed: ' . $response->get_error_message() );
		return;
	}

	$code = wp_remote_retrieve_response_code( $response );
	if ( 200 !== $code ) {
		$body = wp_remote_retrieve_body( $response );
		error_log( sprintf( '[ncg-cf-purge] Cloudflare purge HTTP %d: %s', $code, $body ) );
	}
}
