<?php
/**
 * Plugin Name: Sensitive Topic Notice
 * Description: Shows a softer, dismissible notice banner at the top of posts
 *              flagged with the "sensitive-topic" content flag. Unlike the
 *              graphic-content gate (which is a full-screen overlay with blur),
 *              this is a gentle, non-blocking warning that the reader can
 *              dismiss — the article content remains visible and scrollable
 *              behind it.
 *
 *              Intended for articles covering suicide, abuse, mental health,
 *              or other sensitive subjects that don't contain graphic imagery
 *              but still warrant a content notice.
 *
 *              The notice text is Sinhala. Override via the
 *              `ncg_sensitive_notice_texts` filter.
 *
 * @package Gampola_Janamadyawediya
 * @since   1.0.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Whether the current page is a single post with the sensitive-topic flag
 * (but NOT the graphic-content flag — that one uses its own full gate).
 */
function ncg_is_sensitive_post() {
	if ( ! is_single() ) {
		return false;
	}
	// If the post is already graphic-flagged, the full gate handles it.
	if ( has_term( 'graphic-content', 'content_flag' ) ) {
		return false;
	}
	return has_term( 'sensitive-topic', 'content_flag' );
}

/**
 * Notice texts. The site is Sinhala-only, so there is no locale switch.
 * The `ncg_sensitive_notice_texts` filter remains as an override hook.
 */
function ncg_sensitive_notice_texts() {
	$texts = array(
		'heading' => 'අන්තර්ගත දැනුම්දීම',
		'body'    => 'මෙම ලිපියේ සමහර පාඨකයින්ට අපහසුතාවයක් ඇති කළ හැකි සංවේදී මාතෘකා සාකච්ඡා කෙරේ.',
		'dismiss' => 'වසන්න',
	);

	return apply_filters( 'ncg_sensitive_notice_texts', $texts );
}

/**
 * Inject notice styles into <head>.
 */
add_action(
	'wp_head',
	function () {
		if ( ! ncg_is_sensitive_post() ) {
			return;
		}
		?>
		<style>
			#ncg-sensitive-notice {
				position: relative;
				max-width: 720px;
				margin: 16px auto;
				padding: 16px 48px 16px 20px;
				background: var(--gjn-bg-surface, #FFFFFF);
				border: 1px solid var(--gjn-border, rgba(0, 0, 0, 0.08));
				border-left: 4px solid var(--gjn-text-main, #1B1B1D);
				border-radius: 8px;
				font-family: var(--gjn-font-content, "Geist Sans", "Noto Sans Sinhala", system-ui, sans-serif);
				font-size: 14px;
				line-height: 1.5;
				color: var(--gjn-text-muted, #404040);
				animation: ncg-notice-in 0.35s ease-out;
			}
			#ncg-sensitive-notice h3 {
				margin: 0 0 4px;
				font-size: 15px;
				font-weight: 700;
				color: var(--gjn-text-main, #1B1B1D);
			}
			#ncg-sensitive-notice p {
				margin: 0;
				opacity: 0.9;
			}
			#ncg-sensitive-notice button {
				position: absolute;
				top: 12px;
				right: 12px;
				background: none;
				border: none;
				cursor: pointer;
				color: var(--gjn-text-muted, #404040);
				font-size: 18px;
				line-height: 1;
				padding: 4px;
				opacity: 0.6;
				transition: opacity 0.2s;
			}
			#ncg-sensitive-notice button:hover {
				opacity: 1;
			}
			@keyframes ncg-notice-in {
				from { opacity: 0; transform: translateY(-8px); }
				to   { opacity: 1; transform: translateY(0); }
			}
		</style>
		<?php
	}
);

/**
 * Inject the notice banner into the post content area.
 *
 * Hooks into `the_content` to prepend the notice above the article body.
 * Uses sessionStorage so the notice stays dismissed for the session.
 */
add_filter(
	'the_content',
	function ( $content ) {
		if ( ! ncg_is_sensitive_post() ) {
			return $content;
		}

		$post_id = get_the_ID();
		$texts   = ncg_sensitive_notice_texts();

		ob_start();
		?>
		<div id="ncg-sensitive-notice" role="alert">
			<h3><?php echo esc_html( $texts['heading'] ); ?></h3>
			<p><?php echo esc_html( $texts['body'] ); ?></p>
			<button id="ncg-sensitive-dismiss" aria-label="<?php echo esc_attr( $texts['dismiss'] ); ?>" title="<?php echo esc_attr( $texts['dismiss'] ); ?>">&times;</button>
		</div>
		<script>
		(function() {
			var key = 'ncg_sensitive_dismissed_<?php echo (int) $post_id; ?>';
			var notice = document.getElementById('ncg-sensitive-notice');
			if (!notice) return;
			if (sessionStorage.getItem(key)) {
				notice.style.display = 'none';
				return;
			}
			document.getElementById('ncg-sensitive-dismiss').addEventListener('click', function() {
				notice.style.display = 'none';
				sessionStorage.setItem(key, '1');
			});
		})();
		</script>
		<?php
		$notice = ob_get_clean();

		return $notice . $content;
	},
	5
);
