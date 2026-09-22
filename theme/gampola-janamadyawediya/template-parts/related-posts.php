<?php
/**
 * Related Posts — End-of-article recirculation block.
 *
 * Displays a 3-card grid of related stories below each single post.
 * Sourced by: shared tags (primary) → same category (fallback).
 * Excludes the current post. A post with no thumbnail gets no image block —
 * the grid stretches every card to the tallest, so the text just starts
 * higher. Same rule the front-page feed uses.
 *
 * @package Gampola_Janamadyawediya
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$current_id = get_the_ID();
$related    = array();

// Strategy 1: Posts sharing the same tags (strongest relevance signal).
$tags = wp_get_post_tags( $current_id, array( 'fields' => 'ids' ) );
if ( ! empty( $tags ) ) {
	$related = get_posts( array(
		'tag__in'        => $tags,
		'post__not_in'   => array( $current_id ),
		'posts_per_page' => 3,
		'post_status'    => 'publish',
		'orderby'        => 'date',
		'order'          => 'DESC',
	) );
}

// Strategy 2 (fallback): Same category if tags didn't yield enough.
if ( count( $related ) < 3 ) {
	$cats = wp_get_post_categories( $current_id, array( 'fields' => 'ids' ) );
	if ( ! empty( $cats ) ) {
		$already = array_merge( array( $current_id ), wp_list_pluck( $related, 'ID' ) );
		$more    = get_posts( array(
			'category__in'   => $cats,
			'post__not_in'   => $already,
			'posts_per_page' => 3 - count( $related ),
			'post_status'    => 'publish',
			'orderby'        => 'date',
			'order'          => 'DESC',
		) );
		$related = array_merge( $related, $more );
	}
}

// Nothing to show? Bail silently.
if ( empty( $related ) ) {
	return;
}
?>

<section class="gjn-related-posts" aria-label="<?php echo esc_attr( gjn_l10n( 'තවත් පුවත්', 'More news' ) ); ?>">
	<div class="gjn-related-header">
		<h2 class="gjn-related-title"><?php echo esc_html( gjn_l10n( 'තවත් පුවත් — ඔබට වැදගත් විය හැකි', 'More news — you might find these interesting' ) ); ?></h2>
	</div>

	<div class="gjn-related-grid">
		<?php foreach ( $related as $rp ) :
			$rp_cat = get_the_category( $rp->ID );
			$rp_cat = ! empty( $rp_cat ) ? $rp_cat[0] : null;
		?>
		<article class="gjn-related-card group">
			<a href="<?php echo esc_url( get_permalink( $rp->ID ) ); ?>" class="gjn-related-card-link">
				<?php if ( has_post_thumbnail( $rp->ID ) ) : ?>
					<div class="gjn-related-card-img">
						<?php echo get_the_post_thumbnail( $rp->ID, 'gjn-card', array(
							'class'   => 'gjn-related-card-thumb',
							'loading' => 'lazy',
						) ); ?>
					</div>
				<?php endif; ?>

				<div class="gjn-related-card-body">
					<?php if ( $rp_cat ) : ?>
						<span class="gjn-related-card-cat"><?php echo esc_html( $rp_cat->name ); ?></span>
					<?php endif; ?>
					<h3 class="gjn-related-card-title"><?php echo esc_html( get_the_title( $rp->ID ) ); ?></h3>
					<span class="gjn-related-card-time">
						<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
							<circle cx="12" cy="12" r="10"/>
							<polyline points="12 6 12 12 16 14"/>
						</svg>
						<?php echo esc_html( gjn_relative_time( get_post_time( 'U', false, $rp->ID ) ) ); ?>
					</span>
				</div>
			</a>
		</article>
		<?php endforeach; ?>
	</div>
</section>
