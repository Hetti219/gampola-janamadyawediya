<?php
/**
 * Plugin Name: Graphic Content Gate
 * Description: Shows a click-through sensitive-content warning on any post
 *              filed under the "graphic-content" category, and blurs the
 *              page behind it until the reader confirms. Remembers the
 *              reader's choice per post for the browser session so they
 *              are not re-prompted on reload or back-navigation.
 *
 * The gate text follows the site language: Sinhala when WordPress is set
 * to සිංහල (locale si_LK), English otherwise. Override via the
 * `ncg_gate_texts` filter.
 *
 * To use: when publishing a post with graphic images/video, tick the
 * "Graphic Content" flag in the Content Flags panel (the content_flag
 * custom taxonomy, registered by content-flag-taxonomy.php). Posts
 * without this flag are unaffected.
 */

function ncg_is_graphic_post() {
	return is_single() && has_term( 'graphic-content', 'content_flag' );
}

/**
 * Inject gate styles into <head>.
 */
add_action(
	'wp_head',
	function () {
		if ( ! ncg_is_graphic_post() ) {
			return;
		}
		?>
		<style>
			#ncg-gate {
				position: fixed;
				inset: 0;
				background: rgba(17, 17, 17, 0.97);
				color: #fff;
				display: flex;
				flex-direction: column;
				align-items: center;
				justify-content: center;
				z-index: 99999;
				text-align: center;
				padding: 24px;
				font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Noto Sans Sinhala", sans-serif;
			}
			#ncg-gate h2 {
				font-size: 1.6rem;
				margin-bottom: 12px;
			}
			#ncg-gate p {
				max-width: 440px;
				line-height: 1.5;
				opacity: 0.85;
			}
			#ncg-gate button {
				margin-top: 20px;
				padding: 14px 32px;
				font-size: 16px;
				cursor: pointer;
				border: 1px solid rgba(255, 255, 255, 0.3);
				background: transparent;
				color: #fff;
				border-radius: 6px;
				transition: background 0.2s, border-color 0.2s;
			}
			#ncg-gate button:hover {
				background: rgba(224, 42, 42, 0.22);
				border-color: #E02A2A;
			}
			/* Lock the page: blur everything behind the gate and prevent scroll. */
			body.ncg-locked {
				overflow: hidden;
			}
			body.ncg-locked > *:not(#ncg-gate) {
				filter: blur(20px);
				pointer-events: none;
				user-select: none;
			}
		</style>
		<?php
	}
);

/**
 * Gate texts, localized by site language.
 *
 * WordPress returns locale `si_LK` when the site language is Sinhala.
 * Anything else falls back to English. The `ncg_gate_texts` filter lets
 * an operator or developer override individual strings without editing
 * this file. The `body` string may contain simple HTML such as `<br>`.
 */
function ncg_gate_texts() {
	$texts = array(
		'heading' => 'Sensitive content warning',
		'body'    => 'This article contains graphic images or video related to a news event.<br>Viewer discretion is advised.',
		'button'  => 'I understand, show me the content',
	);

	if ( in_array( get_locale(), array( 'si', 'si_LK' ), true ) ) {
		$texts = array(
			'heading' => 'සංවේදී අන්තර්ගත අනතුරු ඇඟවීම',
			'body'    => 'මෙම ලිපියෙහි පුවත් සිදුවීමකට අදාළ රූප හෝ වීඩියෝ අඩංගු වේ.<br>බැලීමේදී සැලකිලිමත් වන්න.',
			'button'  => 'මට වැටහුණා — අන්තර්ගතය පෙන්වන්න',
		);
	}

	return apply_filters( 'ncg_gate_texts', $texts );
}

/**
 * Inject gate overlay immediately after <body>.
 *
 * Uses sessionStorage keyed by post ID so that once the reader dismisses
 * the warning, it stays dismissed for the remainder of their browser
 * session (tab close resets it). This avoids re-prompting on reload or
 * back-navigation while still gating on a fresh visit.
 */
add_action(
	'wp_body_open',
	function () {
		if ( ! ncg_is_graphic_post() ) {
			return;
		}

		$post_id = get_the_ID();
		$texts   = ncg_gate_texts();
		?>
		<script>
		(function() {
			var texts = <?php echo wp_json_encode( $texts ); ?>;
			var key = 'ncg_accepted_<?php echo (int) $post_id; ?>';
			if (sessionStorage.getItem(key)) {
				// Reader already accepted in this session — skip the gate.
				return;
			}
			// Lock the page immediately.
			document.body.classList.add('ncg-locked');

			var gate = document.createElement('div');
			gate.id = 'ncg-gate';
			gate.innerHTML =
				'<h2>' + texts.heading + '</h2>' +
				'<p>' + texts.body + '</p>' +
				'<button id="ncg-accept-btn">' + texts.button + '</button>';
			document.body.prepend(gate);

			document.getElementById('ncg-accept-btn').addEventListener('click', function() {
				gate.remove();
				document.body.classList.remove('ncg-locked');
				sessionStorage.setItem(key, '1');
			});
		})();
		</script>
		<?php
	}
);
