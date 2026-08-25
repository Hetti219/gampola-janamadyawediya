<?php
/**
 * Gampola Janamadyawediya — GeneratePress Child Theme
 *
 * Clean, minimal news theme for a Sinhala-language local journalism site.
 * Inspired by The Guardian and Medium — strong typography, generous
 * whitespace, professional blue accent.
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
	/* Ensure parent theme stylesheet is loaded. GeneratePress uses the
	   handle "generate-style" — if it's already queued (it usually is),
	   this is a no-op. */
	$parent = 'generate-style';
	if ( ! wp_style_is( $parent, 'enqueued' ) ) {
		wp_enqueue_style(
			$parent,
			get_template_directory_uri() . '/style.css',
			array(),
			wp_get_theme( 'generatepress' )->get( 'Version' )
		);
	}

	// Google Fonts: Plus Jakarta Sans (Latin UI) + Noto Sans Sinhala.
	// Noto loads 400-900 so the UI's font-black headlines render properly.
	wp_enqueue_style(
		'gjn-google-fonts',
		'https://fonts.googleapis.com/css2?' .
			'family=Plus+Jakarta+Sans:wght@400;500;600;700' .
			'&family=Noto+Sans+Sinhala:wght@400;500;700;800;900' .
			'&display=swap',
		array(),
		null  // null prevents ?ver= being appended, which breaks Google Fonts.
	);

	// Child theme stylesheet — loaded after parent + fonts.
	wp_enqueue_style(
		'gampola-janamadyawediya',
		get_stylesheet_uri(),
		array( $parent, 'gjn-google-fonts' ),
		wp_get_theme()->get( 'Version' )
	);

	// Compiled Tailwind stylesheet for the header suite + front-page hero.
	// Rebuild with scripts/build-ui-css.sh after changing any UI classes.
	wp_enqueue_style(
		'gjn-ui',
		get_stylesheet_directory_uri() . '/assets/dist/ui.css',
		array( 'gampola-janamadyawediya' ),
		(string) filemtime( get_stylesheet_directory() . '/assets/dist/ui.css' )
	);
}

// Preconnect to Google Fonts servers for faster font load.
add_action( 'wp_head', 'gjn_preconnect_fonts', 1 );

function gjn_preconnect_fonts() {
	echo '<link rel="preconnect" href="https://fonts.googleapis.com">' . "\n";
	echo '<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>' . "\n";
}

// Favicon — use the same logo image as the browser tab icon.
add_action( 'wp_head', 'gjn_site_icon', 5 );

function gjn_site_icon() {
	if ( has_site_icon() ) {
		return;
	}
	$url = gjn_logo_url();
	echo '<link rel="icon" href="' . esc_url( $url ) . '">' . "\n";
	echo '<link rel="apple-touch-icon" href="' . esc_url( $url ) . '">' . "\n";
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
// The old accent stripe (gjn_accent_bar) was superseded by the new top bar.

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
		<h2 class="widget-title">නවතම පුවත්</h2>
		<div class="gjn-latest-news-list">
			<?php foreach ( $recent as $post ) : ?>
				<a href="<?php echo esc_url( get_permalink( $post->ID ) ); ?>" class="gjn-latest-news-item">
					<div class="gjn-latest-news-img">
						<?php if ( has_post_thumbnail( $post->ID ) ) : ?>
							<?php echo get_the_post_thumbnail( $post->ID, 'thumbnail' ); ?>
						<?php endif; ?>
					</div>
					<div class="gjn-latest-news-text">
						<h3 class="gjn-latest-news-title"><?php echo esc_html( get_the_title( $post->ID ) ); ?></h3>
						<span class="gjn-latest-news-date"><?php echo esc_html( gjn_relative_time( get_post_time( 'U', false, $post->ID ) ) ); ?></span>
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
	$words   = str_word_count( wp_strip_all_tags( get_post_field( 'post_content', get_the_ID() ) ) );
	$minutes = max( 1, (int) ceil( $words / 200 ) );

	printf(
		'<span class="gjn-reading-time">' .
		'<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" ' .
		'stroke-width="2" stroke-linecap="round" stroke-linejoin="round" ' .
		'style="vertical-align:-2px;margin-right:4px">' .
		'<circle cx="12" cy="12" r="10"/>' .
		'<polyline points="12 6 12 12 16 14"/>' .
		'</svg>%d min read</span>',
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
		$logo_url = esc_url( get_stylesheet_directory_uri() . '/assets/img/logo.jpeg' );
		echo '<link rel="icon" href="' . $logo_url . '" sizes="192x192" />' . "\n";
		echo '<link rel="apple-touch-icon" href="' . $logo_url . '" />' . "\n";
	}
	echo '<meta name="theme-color" content="#b91c1c" />' . "\n";
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

	if ( is_front_page() || is_home() ) {
		echo '<meta name="description" content="' . esc_attr( 'අපගේ මෙහෙවර වන්නේ වගකීම් සහිත, විශ්වසනීය, ජනතා කේන්ද්රීය මාධ්යකරණයයි.' ) . '" />' . "\n";
	} elseif ( is_single() ) {
		// Output a basic excerpt-based description for posts
		global $post;
		if ( $post ) {
			$excerpt = wp_trim_words( $post->post_content, 25, '' );
			$excerpt = wp_strip_all_tags( $excerpt );
			if ( ! empty( $excerpt ) ) {
				echo '<meta name="description" content="' . esc_attr( $excerpt ) . '" />' . "\n";
			}
		}
	}
}

/**
 * 9.3 GEO / AEO JSON-LD Schema
 */
add_action( 'wp_head', 'gjn_inject_json_ld', 10 );
function gjn_inject_json_ld() {
	$site_name = get_bloginfo( 'name' );
	$site_url  = home_url( '/' );
	$logo_url  = get_stylesheet_directory_uri() . '/assets/img/logo.jpeg';

	// Organization Schema for Front Page
	if ( is_front_page() || is_home() ) {
		$schema = array(
			'@context' => 'https://schema.org',
			'@type'    => 'NewsMediaOrganization',
			'name'     => $site_name,
			'url'      => $site_url,
			'logo'     => array(
				'@type' => 'ImageObject',
				'url'   => $logo_url,
			),
			'sameAs'   => array(
				'https://www.facebook.com/share/1CUjL7b6Fu/', // Main social link
			)
		);
		echo '<script type="application/ld+json">' . wp_json_encode( $schema ) . '</script>' . "\n";
	}

	// Article Schema for Single Posts
	if ( is_single() ) {
		global $post;
		
		$schema = array(
			'@context' => 'https://schema.org',
			'@type'    => 'NewsArticle',
			'headline' => get_the_title(),
			'url'      => get_permalink(),
			'datePublished' => get_the_date( 'c' ),
			'dateModified'  => get_the_modified_date( 'c' ),
			'author'   => array(
				array(
					'@type' => 'Person',
					'name'  => get_the_author(),
					'url'   => get_author_posts_url( get_the_author_meta( 'ID' ) )
				)
			),
			'publisher' => array(
				'@type' => 'NewsMediaOrganization',
				'name'  => $site_name,
				'logo'  => array(
					'@type' => 'ImageObject',
					'url'   => $logo_url,
				)
			)
		);

		if ( has_post_thumbnail() ) {
			$image = wp_get_attachment_image_src( get_post_thumbnail_id(), 'full' );
			if ( $image ) {
				$schema['image'] = array( $image[0] );
			}
		}

		echo '<script type="application/ld+json">' . wp_json_encode( $schema ) . '</script>' . "\n";
	}
}

/* ───────────────────────────────────────────────────────────
   10. GOOGLE TRANSLATE WIDGET
   ─────────────────────────────────────────────────────────── */

add_action( 'wp_footer', 'gjn_google_translate_script' );
function gjn_google_translate_script() {
	?>
	<div id="google_translate_element" style="position:absolute; top:-1000px; width:1px; height:1px; overflow:hidden;"></div>
	<script type="text/javascript">
		function googleTranslateElementInit() {
			new google.translate.TranslateElement({
				pageLanguage: 'si',
				includedLanguages: 'en,ta,si',
				layout: google.translate.TranslateElement.InlineLayout.SIMPLE,
				autoDisplay: false
			}, 'google_translate_element');
		}

		function gjnDoTranslate(lang) {
			var teCombo = document.querySelector('.goog-te-combo');
			if (!teCombo) {
				// If not loaded yet, wait and retry
				setTimeout(function() { gjnDoTranslate(lang); }, 300);
				return;
			}
			
			// To revert to original language, Google expects an empty value or the pageLanguage
			teCombo.value = lang === 'si' ? '' : lang;
			
			// Event must bubble for Google's event listeners to catch it
			var event;
			if (typeof window.Event === 'function') {
				event = new Event('change', { bubbles: true, cancelable: true });
			} else {
				event = document.createEvent('HTMLEvents');
				event.initEvent('change', true, true);
			}
			teCombo.dispatchEvent(event);
		}
	</script>
	<script type="text/javascript" src="//translate.google.com/translate_a/element.js?cb=googleTranslateElementInit"></script>
	<style>
		/* Hide the top Google Translate banner frame */
		.goog-te-banner-frame.skiptranslate {
			display: none !important;
		}
		body {
			top: 0px !important;
		}
	</style>
	<?php
}
