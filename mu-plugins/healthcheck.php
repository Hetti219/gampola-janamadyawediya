<?php
/**
 * Plugin Name: Healthcheck Endpoint
 * Description: Registers a lightweight /healthz endpoint that returns 200 OK
 *              in plain text. Used by the nginx container healthcheck (instead
 *              of the REST API, which security plugins may disable).
 *
 *              Proves: PHP-FPM alive, WordPress loaded, mu-plugins mount OK.
 *              Excluded from caching via the $skip_cache map in nginx.
 */

// mu-plugins load early in wp-settings.php, before the main query, so this
// check can answer directly — no rewrite rules, query vars, or a flushed-
// rules option needed. nginx routes /healthz to index.php (see
// nginx/prod/wordpress.conf and nginx/local/local.conf's try_files), and
// REQUEST_URI is preserved through the internal redirect.
if ( isset( $_SERVER['REQUEST_URI'] ) && in_array( $_SERVER['REQUEST_URI'], array( '/healthz', '/healthz/' ), true ) ) {
	// Prevent any caching of the healthcheck.
	header( 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0' );
	header( 'X-Accel-Expires: 0' );
	header( 'Content-Type: text/plain; charset=utf-8' );

	status_header( 200 );
	echo 'OK';
	exit;
}
