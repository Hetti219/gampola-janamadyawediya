<?php
/**
 * Front page — three descending tiers.
 *
 * Tier 1 is the lead story on its own, full width. Tier 2 splits 2:1 — a
 * wide second lead over two halves, beside the quick-feed column. Tier 3 is
 * that quick feed. Each tier is visibly smaller than the one above it; that
 * descent is the whole point of the page, so keep the sizes unequal.
 *
 * If no posts exist at all, a static fallback hero keeps the page looking
 * like a news site.
 *
 * @package Gampola_Janamadyawediya
 * @since   3.0.0
 */

defined( 'ABSPATH' ) || exit;

get_header();

/* ───────────────────────────────────────────────────────────
   Data
   ─────────────────────────────────────────────────────────── */

/**
 * Up to 10 posts for the front page, newest first.
 *
 * The sticky post leads when there is one; otherwise the recent list is
 * returned as-is. The template below slices this: index 0 is the featured
 * card, 1 the wide second lead, 2-3 the two halves, 4-9 the quick feed, so
 * the 10 here and the offsets there have to move together.
 */
function gjn_hero_posts() {
	$featured = null;

	$sticky = get_option( 'sticky_posts' );
	if ( ! empty( $sticky ) ) {
		$candidate = get_post( $sticky[0] );
		if ( $candidate && 'publish' === $candidate->post_status ) {
			$featured = $candidate;
		}
	}

	$recent = wp_get_recent_posts(
		array(
			'numberposts' => 10,
			'post_status' => 'publish',
		),
		OBJECT
	);

	$posts = array();
	if ( $featured ) {
		$posts[] = $featured;
		foreach ( $recent as $p ) {
			if ( $p->ID !== $featured->ID ) {
				$posts[] = $p;
			}
		}
	} else {
		$posts = $recent;
	}

	return array_slice( $posts, 0, 10 );
}

/**
 * Accent classes for a category badge. Nothing OS 5.0 keeps badges
 * monochrome — every category gets the same white-on-dark badge used
 * on the featured card.
 */
function gjn_badge_classes( $slug ) {
	return 'bg-wada-seashell/15 text-wada-seashell hover:text-wada-seashell backdrop-blur font-dot';
}

/**
 * First category of a post (object), or null.
 */
function gjn_first_category( $post ) {
	$cats = get_the_category( $post );
	return empty( $cats ) ? null : $cats[0];
}

/**
 * Watermark logo SVG — subtle "ග" monogram reused on every hero card.
 */
function gjn_watermark( $classes ) {
	?>
	<svg class="pointer-events-none absolute <?php echo esc_attr( $classes ); ?> text-white" viewBox="0 0 100 100" fill="none" aria-hidden="true">
		<circle cx="50" cy="50" r="47" stroke="currentColor" stroke-width="5" />
		<text x="50" y="60" text-anchor="middle" font-size="42" font-weight="900" fill="currentColor" font-family="'Noto Sans Sinhala', sans-serif">ග</text>
	</svg>
	<?php
}

/**
 * Clock icon + relative timestamp chip content.
 */
function gjn_time_chip( $post ) {
	$time = gjn_relative_time( get_post_time( 'U', false, $post ) );
	?>
	<span class="flex items-center gap-1.5 rounded-full bg-black/25 px-3.5 py-1.5 text-xs font-semibold backdrop-blur">
		<svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
			<path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z" />
		</svg>
		<?php echo esc_html( $time ); ?>
	</span>
	<?php
}

/* ───────────────────────────────────────────────────────────
   Hero
   ─────────────────────────────────────────────────────────── */

$hero_posts = gjn_hero_posts();
?>
<main class="mx-auto max-w-7xl px-4 py-6 sm:px-6 sm:py-8">

	<?php if ( empty( $hero_posts ) ) : ?>
		<?php gjn_render_hero_fallback(); ?>
	<?php else : ?>

		<!-- 1. LEAD STORY — the one story the page leads with, nothing beside it -->
		<?php
		$featured     = $hero_posts[0];
		$featured_cat = gjn_first_category( $featured );
		?>
		<article class="relative mb-6 flex min-h-[26rem] flex-col overflow-hidden rounded-2xl bg-gradient-to-br from-nothing-charcoal via-neutral-800 to-neutral-900 p-6 text-white ring-1 ring-white/10 transition-shadow duration-300 hover:shadow-2xl sm:p-8 lg:min-h-[34rem]">
			<?php if ( has_post_thumbnail( $featured ) ) : ?>
				<div class="absolute inset-0">
					<?php echo get_the_post_thumbnail( $featured, 'gjn-featured', array(
						'class' => 'h-full w-full object-cover opacity-40 mix-blend-overlay transition-transform duration-700 hover:scale-105',
						'fetchpriority' => 'high'
					) ); ?>
				</div>
				<!-- Scrim to ensure text contrast over bright images -->
				<div class="absolute inset-0 bg-gradient-to-t from-nothing-charcoal via-neutral-900/80 to-transparent"></div>
			<?php else : ?>
			<?php gjn_watermark( '-bottom-14 -right-14 h-72 w-72 opacity-[0.08] sm:h-80 sm:w-80' ); ?>
			<?php endif; ?>

			<div class="relative flex flex-wrap items-center gap-2.5 z-10">
				<?php if ( $featured_cat ) : ?>
					<a href="<?php echo esc_url( get_category_link( $featured_cat ) ); ?>" class="rounded-full bg-white/15 px-3.5 py-1.5 text-[10px] font-bold uppercase tracking-wider text-white hover:text-white backdrop-blur transition hover:bg-white/25 font-dot">
						<?php echo esc_html( $featured_cat->name ); ?>
					</a>
				<?php endif; ?>
				<?php gjn_time_chip( $featured ); ?>
			</div>

			<div class="relative mt-auto pt-8 z-10">
				<h1 class="text-balance text-2xl font-black leading-[1.35] sm:text-3xl lg:text-5xl xl:text-[3rem] xl:leading-[1.2]">
					<a href="<?php echo esc_url( get_permalink( $featured ) ); ?>" class="text-white transition hover:text-white hover:underline"><?php echo esc_html( get_the_title( $featured ) ); ?></a>
				</h1>
				<p class="mt-4 max-w-3xl text-sm leading-relaxed text-white/80 sm:text-lg">
					<?php echo esc_html( wp_trim_words( wp_strip_all_tags( get_the_excerpt( $featured ) ), 40 ) ); ?>
				</p>
				<a href="<?php echo esc_url( get_permalink( $featured ) ); ?>" class="group mt-6 inline-flex items-center gap-2 text-sm font-bold text-white hover:text-white">
					<span class="border-b-2 border-white/30 pb-0.5 transition group-hover:border-white"><?php echo esc_html( 'සම්පූර්ණ පුවත කියවන්න' ); ?></span>
					<svg class="h-4 w-4 transition-transform group-hover:translate-x-1" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor">
						<path stroke-linecap="round" stroke-linejoin="round" d="M13.5 4.5L21 12m0 0l-7.5 7.5M21 12H3" />
					</svg>
				</a>
			</div>
		</article>

		<!-- 2. SECOND TIER — 2:1. One wide lead, then two halves. -->
		<div class="grid grid-cols-1 gap-6 lg:grid-cols-3">

			<!-- Left column. The wide lead outranks the halves below it, and both
			     outrank everything in tier 3 — the size descent is the point. -->
			<div class="flex flex-col gap-5 lg:col-span-2">
				<?php
				$second_lead = isset( $hero_posts[1] ) ? $hero_posts[1] : null;
				if ( $second_lead ) :
					$cat = gjn_first_category( $second_lead );
					?>
					<article class="group relative flex aspect-[4/3] flex-col overflow-hidden rounded-2xl bg-neutral-900 text-white ring-1 ring-white/10 sm:aspect-[21/9]">
						<?php if ( has_post_thumbnail( $second_lead ) ) : ?>
							<div class="absolute inset-0">
								<?php echo get_the_post_thumbnail( $second_lead, 'gjn-featured', array( 'class' => 'h-full w-full object-cover transition-transform duration-700 group-hover:scale-105' ) ); ?>
							</div>
						<?php else : ?>
							<div class="absolute inset-0 bg-gradient-to-br from-nothing-charcoal to-neutral-900"></div>
							<?php gjn_watermark( '-bottom-4 -right-4 h-40 w-40 opacity-10' ); ?>
						<?php endif; ?>

						<!-- Gradient overlay for text readability -->
						<div class="absolute inset-0 bg-gradient-to-t from-black/90 via-black/30 to-transparent"></div>

						<div class="relative z-10 flex flex-1 flex-col justify-between p-5">
							<?php if ( $cat ) : ?>
								<div>
									<a href="<?php echo esc_url( get_category_link( $cat ) ); ?>" class="rounded-full px-2.5 py-1 text-[10px] font-bold uppercase tracking-wider transition hover:brightness-125 font-dot <?php echo esc_attr( gjn_badge_classes( $cat->slug ) ); ?>">
										<?php echo esc_html( $cat->name ); ?>
									</a>
								</div>
							<?php endif; ?>

							<div class="mt-auto rounded-xl bg-white/10 p-4 gjn-liquid-glass ring-1 ring-white/20 transition-colors duration-300 group-hover:bg-white/20">
								<h3 class="text-balance text-lg font-extrabold leading-snug sm:text-xl">
									<a href="<?php echo esc_url( get_permalink( $second_lead ) ); ?>" class="text-white hover:text-white before:absolute before:inset-0"><?php echo esc_html( get_the_title( $second_lead ) ); ?></a>
								</h3>
								<span class="mt-2 flex items-center gap-1.5 text-[11px] font-medium text-white/70">
									<svg class="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
										<path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z" />
									</svg>
									<?php echo esc_html( gjn_relative_time( get_post_time( 'U', false, $second_lead ) ) ); ?>
								</span>
							</div>
						</div>
					</article>
				<?php endif; ?>

				<!-- The halves. Same shape as each other, both clearly below the lead. -->
				<div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
					<?php
					foreach ( array_slice( $hero_posts, 2, 2 ) as $p ) :
						$cat = gjn_first_category( $p );
						?>
						<article class="group relative flex aspect-[4/3] flex-col overflow-hidden rounded-2xl bg-neutral-900 text-white ring-1 ring-white/10 transition-shadow duration-300 hover:shadow-xl hover:shadow-black/20">
							<?php if ( has_post_thumbnail( $p ) ) : ?>
								<div class="absolute inset-0">
									<?php echo get_the_post_thumbnail( $p, 'gjn-card', array( 'class' => 'h-full w-full object-cover transition-transform duration-700 group-hover:scale-105' ) ); ?>
								</div>
							<?php else : ?>
								<div class="absolute inset-0 bg-gradient-to-br from-nothing-charcoal to-neutral-900"></div>
								<?php gjn_watermark( '-bottom-4 -right-4 h-32 w-32 opacity-10' ); ?>
							<?php endif; ?>

							<!-- Gradient overlay for text readability -->
							<div class="absolute inset-0 bg-gradient-to-t from-black/90 via-black/30 to-transparent"></div>

							<div class="relative z-10 flex flex-1 flex-col justify-between p-5">
								<?php if ( $cat ) : ?>
									<div>
										<a href="<?php echo esc_url( get_category_link( $cat ) ); ?>" class="rounded-full px-2.5 py-1 text-[10px] font-bold uppercase tracking-wider transition hover:brightness-125 font-dot <?php echo esc_attr( gjn_badge_classes( $cat->slug ) ); ?>">
											<?php echo esc_html( $cat->name ); ?>
										</a>
									</div>
								<?php endif; ?>

								<div class="mt-auto rounded-xl bg-white/10 p-4 gjn-liquid-glass ring-1 ring-white/20 transition-colors duration-300 group-hover:bg-white/20">
									<h3 class="text-balance text-sm font-extrabold leading-snug sm:text-base">
										<a href="<?php echo esc_url( get_permalink( $p ) ); ?>" class="text-white hover:text-white before:absolute before:inset-0"><?php echo esc_html( get_the_title( $p ) ); ?></a>
									</h3>
									<span class="mt-2 flex items-center gap-1.5 text-[11px] font-medium text-white/70">
										<svg class="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
											<path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z" />
										</svg>
										<?php echo esc_html( gjn_relative_time( get_post_time( 'U', false, $p ) ) ); ?>
									</span>
								</div>
							</div>
						</article>
					<?php endforeach; ?>
				</div>
			</div>

			<!-- Right Column: Quick Feed -->
			<div class="lg:col-span-1 rounded-2xl bg-white dark:bg-nothing-surface p-6 shadow-sm ring-1 ring-neutral-200 dark:ring-nothing-elevated lg:p-8 transition-colors duration-300">
				<div class="mb-6 border-b border-neutral-100 dark:border-nothing-elevated pb-4">
					<h2 class="text-lg font-black text-nothing-charcoal dark:text-neutral-100"><?php echo esc_html( 'තවත් පුවත්' ); ?></h2>
				</div>

				<div class="flex flex-col gap-5">
					<?php
					$feed_posts = array_slice( $hero_posts, 4, 6 );
					foreach ( $feed_posts as $p ) :
						$cat = gjn_first_category( $p );
						?>
						<article class="group relative flex gap-4">
							<div class="flex-1 flex flex-col justify-center">
								<?php if ( $cat ) : ?>
									<span class="mb-1.5 text-[10px] font-bold uppercase tracking-wider text-neutral-500 dark:text-neutral-400 font-dot"><?php echo esc_html( $cat->name ); ?></span>
								<?php endif; ?>
								<h3 class="text-sm font-bold leading-snug text-nothing-charcoal dark:text-neutral-100 transition-colors">
									<a href="<?php echo esc_url( get_permalink( $p ) ); ?>" class="transition-colors hover:text-neutral-600 dark:hover:text-white before:absolute before:inset-0"><?php echo esc_html( get_the_title( $p ) ); ?></a>
								</h3>
								<span class="mt-1.5 text-xs text-neutral-500 dark:text-neutral-400"><?php echo esc_html( gjn_relative_time( get_post_time( 'U', false, $p ) ) ); ?></span>
							</div>
							<?php if ( has_post_thumbnail( $p ) ) : ?>
								<div class="h-16 w-16 shrink-0 overflow-hidden rounded-lg bg-neutral-100 dark:bg-nothing-elevated ring-1 ring-black/5 dark:ring-white/5">
									<?php echo get_the_post_thumbnail( $p, 'thumbnail', array( 'class' => 'h-full w-full object-cover transition-transform duration-500 group-hover:scale-110' ) ); ?>
								</div>
							<?php endif; ?>
						</article>
					<?php endforeach; ?>
				</div>
			</div>

		</div>

	<?php endif; ?>

</main>

<?php
/**
 * Static fallback hero when the site has no published posts yet.
 *
 * The ග watermark carries the visual here rather than a stock empty-state
 * glyph — it is the same mark the populated cards fall back to, so an empty
 * site still looks like this site.
 */
function gjn_render_hero_fallback() {
	?>
	<div class="mb-8">
		<article class="relative flex min-h-[26rem] flex-col items-center justify-center overflow-hidden rounded-2xl bg-gradient-to-br from-neutral-900 via-neutral-800 to-neutral-900 p-6 text-center text-white ring-1 ring-white/10 sm:p-8 lg:min-h-[34rem]">
			<?php gjn_watermark( 'h-48 w-48 opacity-[0.05] sm:h-64 sm:w-64 absolute' ); ?>
			<div class="relative z-10 flex flex-col items-center gap-4">
				<h2 class="text-2xl font-black text-white sm:text-3xl"><?php echo esc_html( 'තවමත් පුවත් පළ වී නැත' ); ?></h2>
				<p class="max-w-md text-sm text-neutral-400 sm:text-base"><?php echo esc_html( 'පුවත් ලැබෙන විට මෙහි පළ වේ. කරුණාකර පසුව නැවත පැමිණෙන්න.' ); ?></p>
			</div>
		</article>
	</div>
	<?php
}

get_footer();
