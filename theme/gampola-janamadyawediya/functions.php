<?php
/**
 * Gampola Janamadyawediya — GeneratePress Child Theme
 *
 * Nothing OS 5.0-inspired monochrome news theme for a Sinhala-language
 * local journalism site. Geist Sans typography, charcoal/white palette,
 * Nothing Red reserved for the breaking-news ticker.
 *
 * @package Gampola_Janamadyawediya
 * @since   1.0.0
 */

defined( 'ABSPATH' ) || exit;

/* ───────────────────────────────────────────────────────────
   0. INCLUDES
   ─────────────────────────────────────────────────────────── */

// Sinhala date/time helpers (no intl dependency).
require get_stylesheet_directory() . '/inc/sinhala.php';

// Site-wide header suite: top bar, site header, nav, news ticker.
// Replaces GeneratePress's default header + navigation.
require get_stylesheet_directory() . '/inc/header-ui.php';

/* ───────────────────────────────────────────────────────────
   1. STYLES & FONTS
   ─────────────────────────────────────────────────────────── */

add_action( 'wp_enqueue_scripts', 'gjn_enqueue_assets', 20 );

function gjn_enqueue_assets() {
	/* The GeneratePress parent enqueues the child stylesheet itself
	   (handle "generate-child"), so style.css needs no enqueue here. */

	// Compiled Tailwind stylesheet for the header suite + front-page hero.
	// Rebuild with ./build-ui-css.sh after changing any UI classes.
	$ui_css_path = get_stylesheet_directory() . '/assets/dist/ui.css';
	$ui_css_ver  = file_exists( $ui_css_path ) ? (string) filemtime( $ui_css_path ) : wp_get_theme()->get( 'Version' );

	wp_enqueue_style(
		'gjn-ui',
		get_stylesheet_directory_uri() . '/assets/dist/ui.css',
		array( 'generate-child' ),
		$ui_css_ver
	);

	wp_enqueue_script(
		'gjn-ui-scripts',
		get_stylesheet_directory_uri() . '/assets/js/ui-scripts.js',
		array(),
		wp_get_theme()->get( 'Version' ),
		true
	);
}

/* ───────────────────────────────────────────────────────────
   1b. GENERATEPRESS PALETTE → NOTHING OS
   GeneratePress injects its Customizer palette (default: #1e73be blue
   accent, #636363 top bar, #55555e buttons) as inline CSS on every page.
   The child stylesheet masks most of it, but Gutenberg blocks using GP
   global-colour classes (.has-accent-color, …) would still render blue.
   These filters pin the palette to the Nothing OS colours at the source,
   on every environment, without writing to the database.
   ─────────────────────────────────────────────────────────── */
add_filter( 'option_generate_settings', 'gjn_nothing_generate_settings' );
add_filter( 'default_option_generate_settings', 'gjn_nothing_generate_settings' );

// GeneratePress bakes its dynamic CSS into the generate_dynamic_css_output
// option on Customizer saves. Skip that cache so the CSS is generated fresh
// per request and the palette filter above always applies.
add_filter( 'generate_dynamic_css_skip_cache', '__return_true' );

function gjn_nothing_generate_settings( $settings ) {
	$palette = array(
		'top_bar_background_color'           => '#1B1B1D',
		'top_bar_text_color'                 => '#F5F5F5',
		'top_bar_link_color'                 => '#F5F5F5',
		'top_bar_link_color_hover'           => '#A1A1A6',
		'form_button_background_color'       => '#1B1B1D',
		'form_button_background_color_hover' => '#2E2E30',
		'form_button_text_color'             => '#F5F5F5',
		'form_button_text_color_hover'       => '#F5F5F5',
		'global_colors'                      => array(
			array( 'name' => __( 'Contrast', 'generatepress' ),   'slug' => 'contrast',   'color' => '#1B1B1D' ),
			array( 'name' => __( 'Contrast 2', 'generatepress' ), 'slug' => 'contrast-2', 'color' => '#404040' ),
			array( 'name' => __( 'Contrast 3', 'generatepress' ), 'slug' => 'contrast-3', 'color' => '#A1A1A6' ),
			array( 'name' => __( 'Base', 'generatepress' ),       'slug' => 'base',       'color' => '#F5F5F5' ),
			array( 'name' => __( 'Base 2', 'generatepress' ),     'slug' => 'base-2',     'color' => '#F5F5F5' ),
			array( 'name' => __( 'Base 3', 'generatepress' ),     'slug' => 'base-3',     'color' => '#FFFFFF' ),
			array( 'name' => __( 'Accent', 'generatepress' ),     'slug' => 'accent',     'color' => '#1B1B1D' ),
		),
	);

	if ( ! is_array( $settings ) ) {
		return $palette;
	}
	return array_merge( $settings, $palette );
}

/* ───────────────────────────────────────────────────────────
   1c. SITE LANGUAGE
   Content is Sinhala-first, but WordPress itself runs in en_US, so
   GeneratePress prints lang="en-US". Pin the lang attribute to si-LK,
   letting Polylang's explicit en/ta versions override.
   ─────────────────────────────────────────────────────────── */
add_filter( 'language_attributes', 'gjn_language_attributes' );

function gjn_language_attributes( $attributes ) {
	$lang = 'si-LK';
	if ( function_exists( 'pll_current_language' ) ) {
		$current = pll_current_language( 'slug' );
		if ( 'en' === $current ) {
			$lang = 'en-US';
		} elseif ( 'ta' === $current ) {
			$lang = 'ta-LK';
		}
	}
	return preg_replace( '/lang="[^"]*"/', 'lang="' . $lang . '"', $attributes );
}

/**
 * The tagline is a single site-wide option, so without this the English
 * homepage title reads "ගම්පොල ජනමාධ්‍යවේදියා - <Sinhala tagline>".
 *
 * Deliberately not applied in wp-admin: Settings > General would otherwise
 * display the English text and saving it would overwrite the Sinhala option.
 */
add_filter( 'option_blogdescription', 'gjn_english_tagline' );

function gjn_english_tagline( $tagline ) {
	if ( is_admin() || ! gjn_is_english() ) {
		return $tagline;
	}
	return 'Responsible, trustworthy, people-centred journalism.';
}



/* ───────────────────────────────────────────────────────────
   2. THEME SETUP
   ─────────────────────────────────────────────────────────── */

add_action( 'after_setup_theme', 'gjn_theme_setup' );

function gjn_theme_setup() {
	// Navigation menus.
	register_nav_menus( array(
		'primary' => __( 'Primary Menu', 'gampola-janamadyawediya' ),
		'footer'  => __( 'Footer Menu', 'gampola-janamadyawediya' ),
	) );

	// Featured images with sizes optimized for news cards.
	add_theme_support( 'post-thumbnails' );
	add_image_size( 'gjn-card', 600, 340, true );
	add_image_size( 'gjn-featured', 1200, 520, true );
	add_image_size( 'gjn-discover', 1200, 675, true ); // 16:9 for Google Discover and Facebook

	// Let WordPress manage the <title> tag.
	add_theme_support( 'title-tag' );

	// HTML5 markup for core components.
	add_theme_support( 'html5', array(
		'search-form',
		'comment-form',
		'comment-list',
		'gallery',
		'caption',
	) );
}

/* ───────────────────────────────────────────────────────────
   3. WIDGET AREAS
   ─────────────────────────────────────────────────────────── */

add_action( 'widgets_init', 'gjn_register_widgets' );

function gjn_register_widgets() {
	register_sidebar( array(
		'name'          => __( 'Footer Column 1', 'gampola-janamadyawediya' ),
		'id'            => 'gjn-footer-1',
		'before_widget' => '<div class="gjn-footer-widget">',
		'after_widget'  => '</div>',
		'before_title'  => '<h4 class="gjn-footer-widget-title">',
		'after_title'   => '</h4>',
	) );

	register_sidebar( array(
		'name'          => __( 'Footer Column 2', 'gampola-janamadyawediya' ),
		'id'            => 'gjn-footer-2',
		'before_widget' => '<div class="gjn-footer-widget">',
		'after_widget'  => '</div>',
		'before_title'  => '<h4 class="gjn-footer-widget-title">',
		'after_title'   => '</h4>',
	) );
}

/* ───────────────────────────────────────────────────────────
   4. HEADER
   ─────────────────────────────────────────────────────────── */

// The site-wide header suite (top bar, site header, nav, ticker) replaces
// GeneratePress's default header + navigation. It lives in inc/header-ui.php,
// which hooks into 'generate_header' after removing 'generate_construct_header'.

// Empty default widgets from the right sidebar so only our custom widget shows
add_filter( 'sidebars_widgets', 'gjn_clear_right_sidebar_widgets' );
function gjn_clear_right_sidebar_widgets( $sidebars_widgets ) {
	if ( ! is_admin() && isset( $sidebars_widgets['sidebar-1'] ) ) {
		$sidebars_widgets['sidebar-1'] = array();
	}
	return $sidebars_widgets;
}

add_action( 'generate_before_right_sidebar_content', 'gjn_custom_latest_news_sidebar' );

function gjn_custom_latest_news_sidebar() {
	$recent = wp_get_recent_posts( array(
		'numberposts' => 5,
		'post_status' => 'publish'
	), OBJECT );
	
	if ( empty( $recent ) ) {
		return;
	}
	?>
	<aside class="widget gjn-latest-news-widget">
		<h2 class="widget-title"><?php echo esc_html( gjn_l10n( 'නවතම පුවත්', 'Latest news' ) ); ?></h2>
		<div class="gjn-latest-news-list">
			<?php foreach ( $recent as $item ) : ?>
				<a href="<?php echo esc_url( get_permalink( $item->ID ) ); ?>" class="gjn-latest-news-item">
					<div class="gjn-latest-news-img">
						<?php if ( has_post_thumbnail( $item->ID ) ) : ?>
							<?php echo get_the_post_thumbnail( $item->ID, 'thumbnail' ); ?>
						<?php endif; ?>
					</div>
					<div class="gjn-latest-news-text">
						<h3 class="gjn-latest-news-title"><?php echo esc_html( get_the_title( $item->ID ) ); ?></h3>
						<span class="gjn-latest-news-date"><?php echo esc_html( gjn_relative_time( get_post_time( 'U', false, $item->ID ) ) ); ?></span>
					</div>
				</a>
			<?php endforeach; ?>
		</div>
	</aside>
	<?php
}

/* ───────────────────────────────────────────────────────────
   5. FOOTER
   ─────────────────────────────────────────────────────────── */

// Custom footer section above the copyright bar.
add_action( 'generate_before_footer', 'gjn_footer_content' );

function gjn_footer_content() {
	$name    = get_bloginfo( 'name' );
	$tagline = get_bloginfo( 'description' );
	?>
	<div class="gjn-footer">
		<div class="gjn-footer-inner">

			<div class="gjn-footer-brand">
				<h3 class="gjn-footer-title"><?php echo esc_html( $name ); ?></h3>
				<?php if ( $tagline ) : ?>
					<p class="gjn-footer-tagline"><?php echo esc_html( $tagline ); ?></p>
				<?php endif; ?>
			</div>

			<?php if ( has_nav_menu( 'footer' ) ) : ?>
				<nav class="gjn-footer-nav" aria-label="<?php esc_attr_e( 'Footer navigation', 'gampola-janamadyawediya' ); ?>">
					<h4 class="gjn-footer-heading">Quick Links</h4>
					<?php wp_nav_menu( array(
						'theme_location' => 'footer',
						'container'      => false,
						'menu_class'     => 'gjn-footer-menu',
						'depth'          => 1,
					) ); ?>
				</nav>
			<?php endif; ?>

			<?php if ( is_active_sidebar( 'gjn-footer-1' ) || is_active_sidebar( 'gjn-footer-2' ) ) : ?>
				<div class="gjn-footer-widgets">
					<?php if ( is_active_sidebar( 'gjn-footer-1' ) ) : ?>
						<div class="gjn-footer-col"><?php dynamic_sidebar( 'gjn-footer-1' ); ?></div>
					<?php endif; ?>
					<?php if ( is_active_sidebar( 'gjn-footer-2' ) ) : ?>
						<div class="gjn-footer-col"><?php dynamic_sidebar( 'gjn-footer-2' ); ?></div>
					<?php endif; ?>
				</div>
			<?php endif; ?>

		</div>
	</div>
	<?php
}

// Replace default GeneratePress copyright text.
add_filter( 'generate_copyright', 'gjn_copyright' );

function gjn_copyright() {
	return sprintf(
		'&copy; %d %s. All rights reserved.',
		(int) gmdate( 'Y' ),
		esc_html( get_bloginfo( 'name' ) )
	);
}

/* ───────────────────────────────────────────────────────────
   6. POST ENHANCEMENTS
   ─────────────────────────────────────────────────────────── */

// Estimated reading time on single posts.
add_action( 'generate_after_entry_title', 'gjn_reading_time' );

function gjn_reading_time() {
	if ( ! is_single() ) {
		return;
	}
	$chars   = mb_strlen( wp_strip_all_tags( get_post_field( 'post_content', get_the_ID() ) ) );
	$minutes = max( 1, (int) ceil( $chars / 1100 ) );

	printf(
		'<span class="gjn-reading-time">' .
		'<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" ' .
		'stroke-width="2" stroke-linecap="round" stroke-linejoin="round" ' .
		'style="vertical-align:-2px;margin-right:4px">' .
		'<circle cx="12" cy="12" r="10"/>' .
		'<polyline points="12 6 12 12 16 14"/>' .
		'</svg>' .
		esc_html( gjn_l10n( 'විනාඩි %dක කියවීමක්', '%d min read' ) ) .
		'</span>',
		$minutes
	);
}

// Category pill badge before the title on archive pages.
add_action( 'generate_before_entry_title', 'gjn_category_pill' );

function gjn_category_pill() {
	if ( is_single() || is_page() ) {
		return;
	}
	$cats = get_the_category();
	if ( empty( $cats ) ) {
		return;
	}
	printf(
		'<a href="%s" class="gjn-category-pill">%s</a>',
		esc_url( get_category_link( $cats[0]->term_id ) ),
		esc_html( $cats[0]->name )
	);
}

// Share row: WhatsApp + Facebook buttons on single posts.
add_action( 'generate_before_entry_content', 'gjn_share_row_top' );
function gjn_share_row_top() {
	if ( ! is_single() ) {
		return;
	}
	get_template_part( 'template-parts/share-row' );
}

add_action( 'generate_after_entry_content', 'gjn_share_row_bottom', 5 );
function gjn_share_row_bottom() {
	if ( ! is_single() ) {
		return;
	}
	get_template_part( 'template-parts/share-row' );
}

// End-of-article recirculation: related stories grid on single posts.
add_action( 'generate_after_entry_content', 'gjn_related_posts', 10 );
function gjn_related_posts() {
	if ( ! is_single() ) {
		return;
	}
	get_template_part( 'template-parts/related-posts' );
}

/* ───────────────────────────────────────────────────────────
   7. EXCERPT
   ─────────────────────────────────────────────────────────── */

// Shorter excerpts for archive cards (≈2 lines).
add_filter( 'excerpt_length', function () {
	return 25;
}, 999 );

// Ellipsis instead of "[…]".
add_filter( 'excerpt_more', function () {
	return '&hellip;';
} );

/* ───────────────────────────────────────────────────────────
   8. GENERATEPRESS LAYOUT OVERRIDES
   ─────────────────────────────────────────────────────────── */

// Remove sidebar on single posts for a clean reading experience.
add_filter( 'generate_sidebar_layout', 'gjn_single_layout' );

function gjn_single_layout( $layout ) {
	if ( is_single() ) {
		return 'no-sidebar';
	}
	return $layout;
}

/* ───────────────────────────────────────────────────────────
   9. SEO & GEO ENHANCEMENTS
   ─────────────────────────────────────────────────────────── */

/**
 * 9.1 Favicon / Site Icon
 */
add_action( 'wp_head', 'gjn_inject_favicon', 5 );
function gjn_inject_favicon() {
	// Only output fallback if no WP site icon is set
	if ( ! has_site_icon() ) {
		$icon_url = esc_url( get_stylesheet_directory_uri() . '/assets/img/favicon.png' );
		echo '<link rel="icon" href="' . $icon_url . '" sizes="512x512" />' . "\n";
		echo '<link rel="apple-touch-icon" href="' . $icon_url . '" />' . "\n";
	}
	// Browser-chrome colour per theme; the toggle script in header-ui.php
	// updates these when the visitor switches theme manually.
	echo '<meta name="theme-color" media="(prefers-color-scheme: light)" content="#F5F5F5" />' . "\n";
	echo '<meta name="theme-color" media="(prefers-color-scheme: dark)" content="#1B1B1D" />' . "\n";
}

/**
 * 9.2 Fallback SEO Meta Tags
 */
add_action( 'wp_head', 'gjn_inject_fallback_seo', 1 );
function gjn_inject_fallback_seo() {
	// Do nothing if Rank Math or Yoast is active
	if ( defined( 'RANK_MATH_VERSION' ) || defined( 'WPSEO_VERSION' ) ) {
		return;
	}

	$site_name = get_bloginfo( 'name' );

	if ( is_front_page() || is_home() ) {
		$desc = gjn_is_english() ? 'Responsible, trustworthy, people-centred journalism.' : 'අපගේ මෙහෙවර වන්නේ වගකීම් සහිත, විශ්වසනීය, ජනතා කේන්ද්රීය මාධ්යකරණයයි.';
		echo '<meta name="description" content="' . esc_attr( $desc ) . '" />' . "\n";
		echo '<meta property="og:type" content="website" />' . "\n";
		echo '<meta property="og:title" content="' . esc_attr( $site_name ) . '" />' . "\n";
		echo '<meta property="og:url" content="' . esc_url( home_url( '/' ) ) . '" />' . "\n";
		echo '<meta property="og:site_name" content="' . esc_attr( $site_name ) . '" />' . "\n";
		echo '<meta property="og:description" content="' . esc_attr( $desc ) . '" />' . "\n";
	} elseif ( is_single() ) {
		// Output a basic excerpt-based description for posts.
		$post = get_post();
		if ( ! $post ) {
			return;
		}

		$excerpt = gjn_post_excerpt( $post );
		if ( '' !== $excerpt ) {
			echo '<meta name="description" content="' . esc_attr( $excerpt ) . '" />' . "\n";
			echo '<meta property="og:description" content="' . esc_attr( $excerpt ) . '" />' . "\n";
		}

		echo '<meta property="og:type" content="article" />' . "\n";
		echo '<meta property="og:title" content="' . esc_attr( get_the_title() ) . '" />' . "\n";
		echo '<meta property="og:url" content="' . esc_url( get_permalink() ) . '" />' . "\n";
		echo '<meta property="og:site_name" content="' . esc_attr( $site_name ) . '" />' . "\n";
		echo '<meta name="twitter:card" content="summary_large_image" />' . "\n";

		// wp_get_attachment_image_src() returns false when the size is missing.
		$image = has_post_thumbnail()
			? wp_get_attachment_image_src( get_post_thumbnail_id(), 'gjn-discover' )
			: false;
		if ( $image ) {
			// Use gjn-discover (1200x675) for Facebook/Twitter previews
			echo '<meta property="og:image" content="' . esc_url( $image[0] ) . '" />' . "\n";
			echo '<meta property="og:image:width" content="' . esc_attr( (string) $image[1] ) . '" />' . "\n";
			echo '<meta property="og:image:height" content="' . esc_attr( (string) $image[2] ) . '" />' . "\n";
		}
	}
}

/**
 * 9.3 GEO / AEO JSON-LD Schema
 */
add_action( 'wp_head', 'gjn_inject_json_ld', 10 );
function gjn_inject_json_ld() {
	// Do nothing if Rank Math or Yoast is active to prevent duplicate JSON-LD
	if ( defined( 'RANK_MATH_VERSION' ) || defined( 'WPSEO_VERSION' ) ) {
		return;
	}

	if ( is_front_page() || is_home() ) {
		gjn_print_json_ld( gjn_json_ld_site() );
	}

	if ( is_single() ) {
		$article = gjn_json_ld_article();
		if ( ! empty( $article ) ) {
			gjn_print_json_ld( $article );
		}
	}
}

/**
 * Wrap a schema array in its script tag.
 *
 * Centralised so there is exactly one place that decides how schema reaches
 * the page — wp_json_encode(), never raw interpolation.
 */
function gjn_print_json_ld( array $schema ) {
	echo '<script type="application/ld+json">' . wp_json_encode( $schema ) . '</script>' . "\n";
}

/**
 * Plain-text excerpt for meta descriptions and schema, or '' when the post
 * body has no usable words.
 */
function gjn_post_excerpt( $post ) {
	if ( ! $post instanceof WP_Post ) {
		return '';
	}
	return wp_strip_all_tags( wp_trim_words( $post->post_content, 25, '' ) );
}

/**
 * NewsMediaOrganization + WebSite graph for the front page.
 *
 * @return array Schema ready for gjn_print_json_ld().
 */
function gjn_json_ld_site() {
	$site_name = get_bloginfo( 'name' );
	$site_url  = home_url( '/' );
	$logo_url  = get_stylesheet_directory_uri() . '/assets/img/logo.jpeg';

	return array(
		'@context' => 'https://schema.org',
		'@graph'   => array(
			array(
				'@type'             => 'NewsMediaOrganization',
				'@id'               => $site_url . '#organization',
				'name'              => $site_name,
				'url'               => $site_url,
				'logo'              => array(
					'@type' => 'ImageObject',
					'@id'   => $site_url . '#logo',
					'url'   => $logo_url,
				),
				'sameAs'            => array(
					'https://www.facebook.com/share/1CUjL7b6Fu/', // Main social link
				),
				'ethicsPolicy'      => $site_url . 'about/',
				'correctionsPolicy' => $site_url . 'contact/',
			),
			array(
				'@type'           => 'WebSite',
				'@id'             => $site_url . '#website',
				'url'             => $site_url,
				'name'            => $site_name,
				'publisher'       => array(
					'@id' => $site_url . '#organization',
				),
				'potentialAction' => array(
					array(
						'@type'       => 'SearchAction',
						'target'      => array(
							'@type'       => 'EntryPoint',
							'urlTemplate' => $site_url . '?s={search_term_string}',
						),
						'query-input' => 'required name=search_term_string',
					),
				),
			),
		),
	);
}

/**
 * NewsArticle schema for the current single post.
 *
 * @return array Schema, or an empty array when there is no post to describe.
 */
function gjn_json_ld_article() {
	// get_post() falls back to the post in the main loop when called without
	// an argument — same object the old `global $post` import reached, without
	// leaking the global into this scope.
	$post = get_post();
	if ( ! $post instanceof WP_Post ) {
		return array();
	}

	$site_name = get_bloginfo( 'name' );
	$logo_url  = get_stylesheet_directory_uri() . '/assets/img/logo.jpeg';

	$schema = array(
		'@context'            => 'https://schema.org',
		'@type'               => 'NewsArticle',
		'mainEntityOfPage'    => array(
			'@type' => 'WebPage',
			'@id'   => get_permalink(),
		),
		'headline'            => get_the_title(),
		'url'                 => get_permalink(),
		'isAccessibleForFree' => true,
		'datePublished'       => get_the_date( 'c' ),
	);

	// dateModified only when actually modified
	if ( get_the_modified_date( 'c' ) !== get_the_date( 'c' ) ) {
		$schema['dateModified'] = get_the_modified_date( 'c' );
	}

	$excerpt = gjn_post_excerpt( $post );
	if ( '' !== $excerpt ) {
		$schema['description'] = $excerpt;
	}

	$categories = get_the_category();
	if ( ! empty( $categories ) ) {
		$schema['articleSection'] = $categories[0]->name;
	}

	$schema['author'] = array(
		array(
			'@type' => 'Person',
			'name'  => get_the_author(),
			'url'   => get_author_posts_url( (int) get_the_author_meta( 'ID' ) ),
		),
	);
	$author_url = get_the_author_meta( 'user_url' );
	if ( ! empty( $author_url ) ) {
		$schema['author'][0]['sameAs'] = array( $author_url );
	}

	$schema['publisher'] = array(
		'@type' => 'NewsMediaOrganization',
		'name'  => $site_name,
		'logo'  => array(
			'@type' => 'ImageObject',
			'url'   => $logo_url,
		),
	);

	// wp_get_attachment_image_src() returns false when the size is missing.
	$image = has_post_thumbnail()
		? wp_get_attachment_image_src( get_post_thumbnail_id(), 'gjn-discover' )
		: false;
	if ( $image ) {
		$schema['image'] = array(
			'@type'  => 'ImageObject',
			'url'    => $image[0],
			'width'  => (int) $image[1],
			'height' => (int) $image[2],
		);
	}

	return $schema;
}

// Clear the breaking-news cache when a post is created or updated, so the
// ticker reflects the change immediately. gjn_ticker_items() reads this same
// cache through gjn_get_breaking_posts() — there is no separate ticker cache.
add_action( 'save_post', function() {
	delete_transient( 'gjn_breaking_posts_cache' );
} );

/* ───────────────────────────────────────────────────────────
   9. SEO & AI CRAWLER POLICY
   ─────────────────────────────────────────────────────────── */

/**
 * Customize robots.txt to block AI training bots while allowing search retrieval.
 */
add_filter( 'robots_txt', 'gjn_robots_txt_ai_policy', 10, 2 );
function gjn_robots_txt_ai_policy( $output, $public ) {
	$ai_bots = array(
		'GPTBot',
		'ClaudeBot',
		'CCBot',
		'Bytespider',
		'Meta-ExternalAgent',
		'Google-Extended', // Disallow Gemini training on news content
	);
	
	$rules = "\n# AI Training Bot Policy (Disallow training, allow retrieval)\n";
	foreach ( $ai_bots as $bot ) {
		$rules .= "User-agent: " . $bot . "\n";
		$rules .= "Disallow: /\n\n";
	}
	
	return $output . $rules;
}
