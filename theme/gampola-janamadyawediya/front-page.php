<?php
/**
 * Front page — hero news grid.
 *
 * Left: featured card (sticky post if one exists, otherwise the latest
 * post). Right: the two next-most-recent posts. If no posts exist at all,
 * a static fallback hero keeps the page looking like a news site.
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
 * Up to 3 hero posts: featured first, then the next two.
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
			'numberposts' => 3,
			'post_status' => 'publish',
		),
		OBJECT
	);

	$posts = array();
	if ( $featured ) {
		$posts[] = $featured;
		foreach ( $recent as $post ) {
			if ( $post->ID !== $featured->ID ) {
				$posts[] = $post;
			}
		}
	} else {
		$posts = $recent;
	}

	return array_slice( $posts, 0, 3 );
}

/**
 * Accent classes for a category badge, by slug. Unknown categories get
 * the neutral white-on-dark badge used on the featured card.
 */
function gjn_badge_classes( $slug ) {
	$map = array(
		'sports'   => 'bg-amber-400/15 text-amber-300 ring-1 ring-amber-400/25',
		'business' => 'bg-sky-400/15 text-sky-300 ring-1 ring-sky-400/25',
	);
	return isset( $map[ $slug ] ) ? $map[ $slug ] : 'bg-white/15 text-white backdrop-blur';
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

		<div class="grid grid-cols-1 gap-5 lg:grid-cols-3">

			<?php
			$featured     = $hero_posts[0];
			$featured_cat = gjn_first_category( $featured );
			?>
			<article class="relative flex min-h-[26rem] flex-col overflow-hidden rounded-2xl bg-gradient-to-br from-red-950 via-red-900 to-red-700 p-6 text-white ring-1 ring-white/10 transition-shadow duration-300 hover:shadow-2xl hover:shadow-red-950/40 sm:p-8 lg:col-span-2 lg:min-h-[30rem]">
				<?php gjn_watermark( '-bottom-14 -right-14 h-72 w-72 opacity-[0.08] sm:h-80 sm:w-80' ); ?>

				<div class="relative flex flex-wrap items-center gap-2.5">
					<?php if ( $featured_cat ) : ?>
						<a href="<?php echo esc_url( get_category_link( $featured_cat ) ); ?>" class="rounded-full bg-white/15 px-3.5 py-1.5 text-xs font-bold backdrop-blur transition hover:bg-white/25">
							<?php echo esc_html( $featured_cat->name ); ?>
						</a>
					<?php endif; ?>
					<?php gjn_time_chip( $featured ); ?>
				</div>

				<div class="relative mt-auto pt-8">
					<h2 class="text-balance text-2xl font-black leading-[1.35] sm:text-3xl lg:text-4xl xl:text-[2.6rem] xl:leading-[1.3]">
						<a href="<?php echo esc_url( get_permalink( $featured ) ); ?>" class="transition hover:underline"><?php echo esc_html( get_the_title( $featured ) ); ?></a>
					</h2>
					<p class="mt-4 max-w-2xl text-sm leading-relaxed text-white/80 sm:text-base">
						<?php echo esc_html( wp_trim_words( wp_strip_all_tags( get_the_excerpt( $featured ) ), 34 ) ); ?>
					</p>
					<a href="<?php echo esc_url( get_permalink( $featured ) ); ?>" class="group mt-6 inline-flex items-center gap-2 text-sm font-bold">
						<span class="border-b-2 border-red-300 pb-0.5 transition group-hover:border-white">සම්පූර්ණ පුවත කියවන්න</span>
						<svg class="h-4 w-4 transition-transform group-hover:translate-x-1" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor">
							<path stroke-linecap="round" stroke-linejoin="round" d="M13.5 4.5L21 12m0 0l-7.5 7.5M21 12H3" />
						</svg>
					</a>
				</div>
			</article>

			<div class="grid gap-5 sm:grid-cols-2 lg:grid-cols-1 lg:grid-rows-2">
				<?php
				$right_tone = array(
					'from-slate-800 via-slate-900 to-slate-950',
					'from-slate-900 via-slate-900 to-slate-950',
				);
				foreach ( array_slice( $hero_posts, 1 ) as $index => $post ) :
					$cat = gjn_first_category( $post );
					?>
					<article class="relative flex min-h-[13rem] flex-col overflow-hidden rounded-2xl bg-gradient-to-br <?php echo esc_attr( $right_tone[ $index % 2 ] ); ?> p-6 text-white ring-1 ring-white/10 transition-shadow duration-300 hover:shadow-xl hover:shadow-slate-950/40 lg:min-h-0">
						<?php gjn_watermark( '-bottom-10 -right-10 h-44 w-44 opacity-[0.06]' ); ?>

						<div class="relative flex flex-wrap items-center justify-between gap-3">
							<?php if ( $cat ) : ?>
								<a href="<?php echo esc_url( get_category_link( $cat ) ); ?>" class="rounded-full px-3 py-1 text-xs font-bold transition hover:brightness-125 <?php echo esc_attr( gjn_badge_classes( $cat->slug ) ); ?>">
									<?php echo esc_html( $cat->name ); ?>
								</a>
							<?php endif; ?>
							<span class="flex items-center gap-1.5 text-xs font-medium text-white/60">
								<svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
									<path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z" />
								</svg>
								<?php echo esc_html( gjn_relative_time( get_post_time( 'U', false, $post ) ) ); ?>
							</span>
						</div>
						<h3 class="relative mt-auto pt-6 text-balance text-base font-extrabold leading-snug sm:text-lg">
							<a href="<?php echo esc_url( get_permalink( $post ) ); ?>" class="transition hover:underline"><?php echo esc_html( get_the_title( $post ) ); ?></a>
						</h3>
					</article>
				<?php endforeach; ?>
			</div>

		</div>

	<?php endif; ?>

</main>

<?php
/**
 * Static fallback hero when the site has no published posts yet —
 * same markup as the finalized design, placeholder content.
 */
function gjn_render_hero_fallback() {
	?>
	<div class="grid grid-cols-1 gap-5 lg:grid-cols-3">

		<article class="relative flex min-h-[26rem] flex-col overflow-hidden rounded-2xl bg-gradient-to-br from-red-950 via-red-900 to-red-700 p-6 text-white ring-1 ring-white/10 sm:p-8 lg:col-span-2 lg:min-h-[30rem]">
			<?php gjn_watermark( '-bottom-14 -right-14 h-72 w-72 opacity-[0.08] sm:h-80 sm:w-80' ); ?>
			<div class="relative flex flex-wrap items-center gap-2.5">
				<span class="rounded-full bg-white/15 px-3.5 py-1.5 text-xs font-bold backdrop-blur">දේශපාලනය</span>
				<span class="flex items-center gap-1.5 rounded-full bg-black/25 px-3.5 py-1.5 text-xs font-semibold backdrop-blur">
					<svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
						<path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z" />
					</svg>
					මිනිත්තු 15කට පෙර
				</span>
			</div>
			<div class="relative mt-auto pt-8">
				<h2 class="text-balance text-2xl font-black leading-[1.35] sm:text-3xl lg:text-4xl xl:text-[2.6rem] xl:leading-[1.3]">ගම්පොළ නගර සභාව නව සංවර්ධන වැඩසටහනක් දියත් කරයි</h2>
				<p class="mt-4 max-w-2xl text-sm leading-relaxed text-white/80 sm:text-base">ගම්පොළ නගර සභාවේ පාලනාධිකාරිය විසින් මෙම වසරේ දී රුපියල් මිලියන 500ක වියදමින් නව යටිතල පහසුකම් සංවර්ධන වැඩසටහනක් ආරම්භ කිරීමට නියමිතය. ඒ අනුව නගරයේ ප්‍රධාන මාර්ග, පාලම් සහ ජලාපවහන පද්ධතීන් ප්‍රතිසංස්කරණය කිරීමට කටයුතු යොදා ඇත.</p>
				<a href="#" class="group mt-6 inline-flex items-center gap-2 text-sm font-bold">
					<span class="border-b-2 border-red-300 pb-0.5 transition group-hover:border-white">සම්පූර්ණ පුවත කියවන්න</span>
					<svg class="h-4 w-4 transition-transform group-hover:translate-x-1" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor">
						<path stroke-linecap="round" stroke-linejoin="round" d="M13.5 4.5L21 12m0 0l-7.5 7.5M21 12H3" />
					</svg>
				</a>
			</div>
		</article>

		<div class="grid gap-5 sm:grid-cols-2 lg:grid-cols-1 lg:grid-rows-2">
			<article class="relative flex min-h-[13rem] flex-col overflow-hidden rounded-2xl bg-gradient-to-br from-slate-800 via-slate-900 to-slate-950 p-6 text-white ring-1 ring-white/10 lg:min-h-0">
				<?php gjn_watermark( '-bottom-10 -right-10 h-44 w-44 opacity-[0.06]' ); ?>
				<div class="relative flex flex-wrap items-center justify-between gap-3">
					<span class="rounded-full bg-amber-400/15 px-3 py-1 text-xs font-bold text-amber-300 ring-1 ring-amber-400/25">ක්‍රීඩා</span>
					<span class="flex items-center gap-1.5 text-xs font-medium text-white/60">
						<svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
							<path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z" />
						</svg>
						පැය 2කට පෙර
					</span>
				</div>
				<h3 class="relative mt-auto pt-6 text-balance text-base font-extrabold leading-snug sm:text-lg">ශ්‍රී ලංකා ක්‍රිකට් කණ්ඩායම ඉන්දීය සංචාරය සඳහා පිටත් වෙයි</h3>
			</article>

			<article class="relative flex min-h-[13rem] flex-col overflow-hidden rounded-2xl bg-gradient-to-br from-slate-900 via-slate-900 to-slate-950 p-6 text-white ring-1 ring-white/10 lg:min-h-0">
				<?php gjn_watermark( '-bottom-10 -right-10 h-44 w-44 opacity-[0.06]' ); ?>
				<div class="relative flex flex-wrap items-center justify-between gap-3">
					<span class="rounded-full bg-sky-400/15 px-3 py-1 text-xs font-bold text-sky-300 ring-1 ring-sky-400/25">ව්‍යාපාර</span>
					<span class="flex items-center gap-1.5 text-xs font-medium text-white/60">
						<svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
							<path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z" />
						</svg>
						පැය 4කට පෙර
					</span>
				</div>
				<h3 class="relative mt-auto pt-6 text-balance text-base font-extrabold leading-snug sm:text-lg">මහනුවර තේ වෙන්දේසියේ මිල ගණන් ඉහළට</h3>
			</article>
		</div>

	</div>
	<?php
}

get_footer();
