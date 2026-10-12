<?php
/**
 * Plugin Name: Content Flag Taxonomy
 * Description: Registers the "content_flag" custom taxonomy for marking posts
 *              with content-sensitivity flags (e.g., Graphic Content, Sensitive
 *              Topic). Keeps content warnings separate from editorial
 *              categories so the nav menu stays clean and ad-network-safe.
 *
 *              Terms created on first run:
 *              - "Graphic Content" (slug: graphic-content)
 *                → full-screen warning gate + blurred social images
 *              - "Sensitive Topic" (slug: sensitive-topic)
 *                → softer dismissible notice, no blur
 *
 * @package Gampola_Janamadyawediya
 * @since   1.0.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Register the content_flag taxonomy on the "post" post type.
 *
 * Hierarchical = true so it renders as checkboxes (like categories)
 * in the editor sidebar, not a free-text tag input. The journalist
 * picks from a controlled list — they never type flags by hand.
 */
add_action( 'init', 'ncg_register_content_flag_taxonomy' );

function ncg_register_content_flag_taxonomy() {
	$labels = array(
		'name'                  => 'Content Flags',
		'singular_name'         => 'Content Flag',
		'search_items'          => 'Search Content Flags',
		'all_items'             => 'All Content Flags',
		'edit_item'             => 'Edit Content Flag',
		'update_item'           => 'Update Content Flag',
		'add_new_item'          => 'Add New Content Flag',
		'new_item_name'         => 'New Content Flag Name',
		'menu_name'             => 'Content Flags',
		'not_found'             => 'No content flags found.',
		'no_terms'              => 'No content flags',
		'items_list_navigation' => 'Content flags list navigation',
		'items_list'            => 'Content flags list',
	);

	$args = array(
		'labels'            => $labels,
		'hierarchical'      => true,
		'public'            => false,   // No front-end archive pages.
		'show_ui'           => true,    // Show in wp-admin.
		'show_in_menu'      => true,
		'show_admin_column' => true,    // Column in Posts list table.
		'show_in_rest'      => true,    // Block editor support.
		'show_in_nav_menus' => false,   // Keep out of nav menus.
		'rewrite'           => false,   // No URL rewrites — flags are metadata, not browseable sections.
		'query_var'         => false,
	);

	register_taxonomy( 'content_flag', array( 'post' ), $args );
}

/**
 * Seed the two default terms on every request if they don't exist yet.
 *
 * Using `init` (after the taxonomy is registered) with a lightweight
 * term_exists check. After the first run this is a single DB query
 * returning true — negligible overhead.
 */
add_action( 'init', 'ncg_seed_content_flag_terms', 20 );

function ncg_seed_content_flag_terms() {
	// One-time flag: skip entirely after first successful seed.
	if ( get_option( 'ncg_content_flags_seeded' ) ) {
		return;
	}

	$defaults = array(
		array(
			'name'        => 'Graphic Content',
			'slug'        => 'graphic-content',
			'description' => 'Explicit imagery: violence, blood, gore, accident scenes. Triggers full-screen warning gate and blurred social-share images.',
		),
		array(
			'name'        => 'Sensitive Topic',
			'slug'        => 'sensitive-topic',
			'description' => 'Sensitive subject matter (suicide, abuse, mental health) without necessarily graphic imagery. Shows a softer dismissible notice.',
		),
	);

	foreach ( $defaults as $term ) {
		if ( term_exists( $term['slug'], 'content_flag' ) ) {
			continue;
		}

		$result = wp_insert_term(
			$term['name'],
			'content_flag',
			array(
				'slug'        => $term['slug'],
				'description' => $term['description'],
			)
		);

		if ( is_wp_error( $result ) ) {
			error_log( sprintf(
				'[ncg-content-flags] Could not create the "%s" term: %s',
				$term['slug'],
				$result->get_error_message()
			) );
		}
	}

	// Only record success once every term is really there. The graphic-content
	// gate and the blur plugin both match on these slugs, so setting the flag
	// after a failed insert would disable the warning for every post, with no
	// retry and no visible symptom. Leaving it unset retries on the next
	// request; terms that did get created are skipped by term_exists() above.
	foreach ( $defaults as $term ) {
		if ( ! term_exists( $term['slug'], 'content_flag' ) ) {
			return;
		}
	}

	update_option( 'ncg_content_flags_seeded', '1', true );
}
