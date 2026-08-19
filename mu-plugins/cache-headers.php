<?php
/**
 * Plugin Name: Cache Headers
 * Description: Emits per-page-type Cache-Control and X-Accel-Expires headers
 *              so nginx fastcgi_cache and Cloudflare edge cache respect the
 *              TTL table defined in CACHING_PLAN.md. One hook drives both
 *              cache layers. Logged-in and comment-author requests get
 *              no-store (belt-and-braces on top of the nginx bypass map).
 */

add_action( 'send_headers', 'ncg_cache_send_headers', 10 );

function ncg_cache_send_headers() {
	// Never cache admin, REST API, AJAX, or cron requests.
	if ( is_admin() || wp_doing_ajax() || wp_doing_cron() ) {
		return;
	}

	// REST API requests (covers /wp-json/).
	if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
		return;
	}

	// Logged-in users and comment authors: no caching at all.
	if ( is_user_logged_in() || ncg_has_comment_author_cookie() ) {
		header( 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0', true );
		header( 'X-Accel-Expires: 0', true );
		return;
	}

	// Post preview: never cache.
	if ( is_preview() ) {
		header( 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0', true );
		header( 'X-Accel-Expires: 0', true );
		return;
	}

	// Determine TTLs per the plan's TTL table.
	$origin = 60;  // default fallback
	$edge   = 120;
	$browser = 60;

	if ( is_front_page() || is_home() ) {
		$origin  = 10;
		$edge    = 60;
		$browser = 10;
	} elseif ( is_singular() ) {
		$origin  = 300;
		$edge    = 600;
		$browser = 300;
	} elseif ( is_category() || is_archive() || is_tag() || is_author() || is_date() ) {
		$origin  = 60;
		$edge    = 120;
		$browser = 60;
	} elseif ( is_search() ) {
		// Search results are not cached.
		header( 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0', true );
		header( 'X-Accel-Expires: 0', true );
		return;
	} elseif ( is_feed() ) {
		$origin  = 60;
		$edge    = 60;
		$browser = 60;
	}

	// X-Accel-Expires overrides the nginx fastcgi_cache_valid per-request.
	header( sprintf( 'X-Accel-Expires: %d', $origin ), true );

	// Cache-Control for Cloudflare (s-maxage) and browser (max-age).
	header(
		sprintf(
			'Cache-Control: public, max-age=%d, s-maxage=%d',
			$browser,
			$edge
		),
		true
	);
}

/**
 * Check if the request carries a comment_author cookie.
 */
function ncg_has_comment_author_cookie() {
	foreach ( array_keys( $_COOKIE ) as $name ) {
		if ( 0 === strpos( $name, 'comment_author_' ) ) {
			return true;
		}
	}
	return false;
}
