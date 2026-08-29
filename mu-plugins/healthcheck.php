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

add_action( 'init', 'ncg_healthcheck_rewrite' );

function ncg_healthcheck_rewrite() {
	add_rewrite_rule( '^healthz/?$', 'index.php?ncg_healthz=1', 'top' );
}

add_filter( 'query_vars', 'ncg_healthcheck_query_vars' );

function ncg_healthcheck_query_vars( $vars ) {
	$vars[] = 'ncg_healthz';
	return $vars;
}

if ( isset( $_SERVER['REQUEST_URI'] ) && ( $_SERVER['REQUEST_URI'] === '/healthz' || $_SERVER['REQUEST_URI'] === '/healthz/' ) ) {
	// Prevent any caching of the healthcheck.
	header( 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0' );
	header( 'X-Accel-Expires: 0' );
	header( 'Content-Type: text/plain; charset=utf-8' );

	status_header( 200 );
	echo 'OK';
	exit;
}

function ncg_healthcheck_respond() {
	if ( ! isset( $_GET['ncg_healthz'] ) && ! get_query_var( 'ncg_healthz' ) ) {
		return;
	}

	// Prevent any caching of the healthcheck.
	header( 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0' );
	header( 'X-Accel-Expires: 0' );
	header( 'Content-Type: text/plain; charset=utf-8' );

	status_header( 200 );
	echo 'OK';
	exit;
}

/**
 * Flush rewrite rules on activation.
 *
 * Since mu-plugins don't have activate/deactivate hooks, this runs once
 * and sets a flag so it doesn't repeat on every page load.
 */
add_action( 'init', 'ncg_healthcheck_maybe_flush_rules', 99 );

function ncg_healthcheck_maybe_flush_rules() {
	if ( get_option( 'ncg_healthcheck_flushed' ) ) {
		return;
	}
	flush_rewrite_rules( false );
	update_option( 'ncg_healthcheck_flushed', '1', true );
}
