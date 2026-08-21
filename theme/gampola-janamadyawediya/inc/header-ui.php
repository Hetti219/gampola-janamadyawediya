<?php
/**
 * Site-wide header suite: top bar, site header, navigation, news ticker.
 *
 * Replaces GeneratePress's default site header + navigation. The markup
 * mirrors the finalized Tailwind UI prototype (ui/index.html) 1:1; styles
 * come from the compiled stylesheet assets/dist/ui.css.
 *
 * @package Gampola_Janamadyawediya
 * @since   3.0.0
 */

defined('ABSPATH') || exit;

/* ───────────────────────────────────────────────────────────
   Replace GeneratePress's default header + navigation
   ─────────────────────────────────────────────────────────── */

add_action('after_setup_theme', function () {
	// GP renders logo + primary nav through this function; dropping it
	// removes both. The page/container wrappers (#page, .site-content)
	// live in GP's header.php template and stay untouched.
	remove_action('generate_header', 'generate_construct_header');
}, 20);

add_action('generate_header', 'gjn_render_header_ui');

/**
 * Render the whole header suite in GP's header slot.
 */
function gjn_render_header_ui()
{
	gjn_top_bar();
	gjn_site_header();
	gjn_navigation();
	gjn_news_ticker();
}

/* ───────────────────────────────────────────────────────────
   Shared helpers
   ─────────────────────────────────────────────────────────── */

/**
 * URL of the contact page, falling back to '#' if it doesn't exist yet.
 */
function gjn_contact_url()
{
	$page = get_page_by_path('contact');
	if (!$page) {
		$page = get_page_by_path('contact-us');
	}
	return $page ? get_permalink($page) : '#';
}

/**
 * URL of the site logo image shown in the header. Filterable so the file
 * can be swapped without touching markup.
 */
function gjn_logo_url()
{
	return apply_filters('gjn_logo_url', get_stylesheet_directory_uri() . '/assets/img/logo.jpeg');
}

/**
 * Social profile links shown in the top bar.
 *
 * Only Facebook exists today; extra networks appear automatically when
 * added via the `gjn_social_links` filter. Return '' or remove a key to
 * hide that icon.
 *
 * Example — add YouTube later from a plugin or child theme:
 *   add_filter( 'gjn_social_links', function ( $links ) {
 *       $links['youtube'] = 'https://www.youtube.com/@your-channel';
 *       return $links;
 *   });
 */
function gjn_social_links()
{
	$links = array(
		'facebook' => 'https://www.facebook.com/share/1CUjL7b6Fu/',
		'whatsapp' => 'https://wa.me/94713861588?text=Hello%20There',
	);

	/**
	 * Filter the social links. Keys must match an entry in the icon map
	 * inside gjn_top_bar() (facebook, x, youtube, instagram, whatsapp).
	 */
	$links = apply_filters('gjn_social_links', $links);

	// Show only networks that have a real, non-empty URL.
	return array_filter(
		$links,
		static function ($url) {
			if (!is_string($url)) {
				return false;
			}
			$trimmed = trim($url);
			return '' !== $trimmed && '#' !== $trimmed;
		}
	);
}

/**
 * Headlines for the news ticker: posts marked with the breaking news flag.
 */
function gjn_ticker_items()
{
	$items = array();

	if ( function_exists( 'gjn_get_breaking_posts' ) ) {
		$posts = gjn_get_breaking_posts( 5 );
		foreach ($posts as $post) {
			$items[] = array(
				'text' => get_the_title($post->ID),
				'url'  => get_permalink($post->ID),
			);
		}
	}

	return $items;
}

/* ───────────────────────────────────────────────────────────
   1. Top bar
   ─────────────────────────────────────────────────────────── */

function gjn_top_bar()
{
	$social_icons = array(
		'facebook' => '<path d="M24 12.073c0-6.627-5.373-12-12-12s-12 5.373-12 12c0 5.99 4.388 10.954 10.125 11.854v-8.385H7.078v-3.47h3.047V9.43c0-3.007 1.792-4.669 4.533-4.669 1.312 0 2.686.235 2.686.235v2.953H15.83c-1.491 0-1.956.925-1.956 1.874v2.25h3.328l-.532 3.47h-2.796v8.385C19.612 23.027 24 18.062 24 12.073z"/>',
		'x' => '<path d="M18.244 2.25h3.308l-7.227 8.26 8.502 11.24H16.17l-5.214-6.817L4.99 21.75H1.68l7.73-8.835L1.254 2.25H8.08l4.713 6.231zm-1.161 17.52h1.833L7.084 4.126H5.117z"/>',
		'youtube' => '<path d="M23.498 6.186a3.016 3.016 0 0 0-2.122-2.136C19.505 3.545 12 3.545 12 3.545s-7.505 0-9.377.505A3.017 3.017 0 0 0 .502 6.186C0 8.07 0 12 0 12s0 3.93.502 5.814a3.016 3.016 0 0 0 2.122 2.136c1.871.505 9.376.505 9.376.505s7.505 0 9.377-.505a3.015 3.015 0 0 0 2.122-2.136C24 15.93 24 12 24 12s0-3.93-.502-5.814zM9.545 15.568V8.432L15.818 12l-6.273 3.568z"/>',
		'instagram' => '<path d="M12 2.163c3.204 0 3.584.012 4.85.07 3.252.148 4.771 1.691 4.919 4.919.058 1.265.069 1.645.069 4.849 0 3.205-.012 3.584-.069 4.849-.149 3.225-1.664 4.771-4.919 4.919-1.266.058-1.644.07-4.85.07-3.204 0-3.584-.012-4.849-.07-3.26-.149-4.771-1.699-4.919-4.92-.058-1.265-.07-1.644-.07-4.849 0-3.204.013-3.583.07-4.849.149-3.227 1.664-4.771 4.919-4.919 1.266-.057 1.645-.069 4.849-.069zm0-2.163c-3.259 0-3.667.014-4.947.072-4.358.2-6.78 2.618-6.98 6.98-.059 1.281-.073 1.689-.073 4.948 0 3.259.014 3.668.072 4.948.2 4.358 2.618 6.78 6.98 6.98 1.281.058 1.689.072 4.948.072 3.259 0 3.668-.014 4.948-.072 4.354-.2 6.782-2.618 6.979-6.98.059-1.28.073-1.689.073-4.948 0-3.259-.014-3.667-.072-4.947-.196-4.354-2.617-6.78-6.979-6.98-1.281-.059-1.69-.073-4.949-.073zm0 5.838a6.162 6.162 0 1 0 0 12.324 6.162 6.162 0 0 0 0-12.324zm0 10.162a4 4 0 1 1 0-8 4 4 0 0 1 0 8zm6.406-11.845a1.44 1.44 0 1 0 0 2.881 1.44 1.44 0 0 0 0-2.881z"/>',
		'whatsapp' => '<path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413z"/>',
	);
	?>
	<div class="bg-neutral-950 text-neutral-300">
		<div class="mx-auto flex max-w-7xl items-center justify-between gap-4 px-4 py-2.5 text-xs sm:px-6 sm:text-sm">
			<div class="flex shrink-0 items-center gap-2">
				<svg class="hidden h-4 w-4 shrink-0 text-red-600 sm:block" fill="none" viewBox="0 0 24 24"
					stroke-width="1.8" stroke="currentColor">
					<path stroke-linecap="round" stroke-linejoin="round"
						d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 012.25-2.25h13.5A2.25 2.25 0 0121 7.5v11.25m-18 0A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75m-18 0v-7.5A2.25 2.25 0 015.25 9h13.5A2.25 2.25 0 0121 11.25v7.5" />
				</svg>
				<span
					class="whitespace-nowrap font-medium text-neutral-200"><?php echo esc_html(gjn_sinhala_date(current_time('timestamp'))); ?></span>
			</div>

			<?php $gjn_description = get_bloginfo('description'); ?>
			<?php if ($gjn_description) : ?>
			<p class="hidden items-center gap-2.5 text-center font-medium text-neutral-400 md:flex">
				<span class="h-1 w-1 shrink-0 rounded-full bg-red-600"></span>
				<?php echo esc_html($gjn_description); ?>
				<span class="h-1 w-1 shrink-0 rounded-full bg-red-600"></span>
			</p>
			<?php endif; ?>

			<?php $gjn_links = gjn_social_links(); ?>
			<?php if (!empty($gjn_links)): ?>
				<div class="flex shrink-0 items-center gap-2.5 sm:gap-3.5">
					<?php foreach ($gjn_links as $network => $url): ?>
						<?php if (!isset($social_icons[$network])) {
							continue;
						} ?>
						<a href="<?php echo esc_url($url); ?>" target="_blank" rel="noopener"
							aria-label="<?php echo esc_attr(ucfirst($network)); ?>"
							class="text-neutral-400 transition hover:text-white">
							<svg class="h-4 w-4" fill="currentColor"
								viewBox="0 0 24 24"><?php echo $social_icons[$network]; // phpcs:ignore WordPress.Security.EscapeOutput -- static SVG markup. ?></svg>
						</a>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>
		</div>
	</div>
	<?php
}

/* ───────────────────────────────────────────────────────────
   2. Site header
   ─────────────────────────────────────────────────────────── */

function gjn_site_header()
{
	?>
	<header class="border-b border-neutral-200 bg-white">
		<div class="mx-auto flex max-w-7xl items-center gap-6 px-4 py-4 sm:px-6">
			<a href="<?php echo esc_url(home_url('/')); ?>" class="flex min-w-0 items-center gap-3">
				<span class="h-12 w-12 shrink-0 overflow-hidden rounded-xl shadow-lg shadow-black/20 ring-1 ring-black/10">
					<img src="<?php echo esc_url(gjn_logo_url()); ?>" alt="<?php echo esc_attr(get_bloginfo('name')); ?>"
						class="h-full w-full object-cover">
				</span>
				<span class="min-w-0">
					<span class="block truncate text-xl font-black leading-tight text-neutral-900 sm:text-2xl"><?php echo esc_html(get_bloginfo('name')); ?></span>
					<span class="block text-[10px] font-bold uppercase tracking-[0.3em] text-neutral-400">Gampola Janamadyawediya</span>
				</span>
			</a>
		</div>
	</header>
	<?php
}

/* ───────────────────────────────────────────────────────────
   3. Navigation
   ─────────────────────────────────────────────────────────── */

/**
 * Tailwind link classes for the primary menu only.
 */
add_filter(
	'nav_menu_link_attributes',
	function ($atts, $item, $args) {
		if (!empty($args->theme_location) && 'primary' === $args->theme_location) {
			$atts['class'] = trim(
				($atts['class'] ?? '') .
				' whitespace-nowrap rounded-full px-4 py-2 transition hover:bg-red-50 hover:text-red-700'
			);
		}
		return $atts;
	},
	10,
	3
);

/**
 * Fallback when no "primary" menu is assigned yet.
 */
function gjn_nav_fallback()
{
	$links = array(
		'මුල් පිටුව'       => home_url('/'),
		'දේශීය පුවත්'      => '#',   // Local News — most-read section, listed first.
		'දේශපාලනය'         => '#',   // Politics.
		'අධිකරණ/පොලිස්'    => '#',   // Crime & Courts.
		'ක්‍රීඩා'            => '#',   // Sports.
		'වෙළඳ/ව්‍යාපාරික'   => '#',   // Business.
		'විදේශ'             => '#',   // World.
		'ප්‍රාදේශීය'         => '#',   // Regional.
		'විශේෂාංග'          => '#',   // Features.
		'කතුවැකිය'          => '#',   // Editorial.
		'වීඩියෝ'            => '#',   // Video.
	);

	echo '<ul class="nav-primary no-scrollbar flex items-center gap-1 overflow-x-auto py-3 text-sm font-semibold text-neutral-700">';
	$first = true;
	foreach ($links as $label => $url) {
		$active = $first ? ' current-menu-item' : '';
		echo '<li class="menu-item' . esc_attr($active) . '">';
		echo '<a href="' . esc_url($url) . '" class="whitespace-nowrap rounded-full px-4 py-2 transition hover:bg-red-50 hover:text-red-700">' . esc_html($label) . '</a>';
		echo '</li>';
		$first = false;
	}
	echo '</ul>';
}

function gjn_navigation()
{
	?>
	<nav class="gjn-nav sticky top-0 z-40 border-b border-neutral-200 bg-white/95 backdrop-blur">
		<div class="mx-auto flex max-w-7xl items-center justify-between gap-4 px-4 sm:px-6">
			<?php
			wp_nav_menu(
				array(
					'theme_location' => 'primary',
					'container' => false,
					'menu_class' => 'nav-primary no-scrollbar flex items-center gap-1 overflow-x-auto py-3 text-sm font-semibold text-neutral-700',
					'depth' => 1,
					'fallback_cb' => 'gjn_nav_fallback',
				)
			);
			?>
			<div class="relative hidden shrink-0 md:block">
				<svg class="pointer-events-none absolute left-3.5 top-1/2 h-4 w-4 -translate-y-1/2 text-neutral-400"
					fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
					<path stroke-linecap="round" stroke-linejoin="round"
						d="M21 21l-5.197-5.197m0 0A7.5 7.5 0 105.196 5.196a7.5 7.5 0 0010.607 10.607z" />
				</svg>
				<form role="search" method="get" action="<?php echo esc_url(home_url('/')); ?>">
					<input type="text" name="s" placeholder="පුවත් සොයන්න…"
						class="w-44 rounded-full border border-neutral-300 bg-neutral-50 py-2 pl-10 pr-4 text-sm outline-none transition-all focus:w-56 focus:border-red-600 focus:bg-white focus:ring-2 focus:ring-red-600/20">
				</form>
			</div>
		</div>
		<script>
			// Enable drag-to-scroll on desktop for the primary navigation
			document.addEventListener('DOMContentLoaded', () => {
				const slider = document.querySelector('.nav-primary');
				if (!slider) return;

				let isDown = false;
				let startX;
				let scrollLeft;

				slider.addEventListener('mousedown', (e) => {
					isDown = true;
					slider.classList.add('cursor-grabbing');
					startX = e.pageX - slider.offsetLeft;
					scrollLeft = slider.scrollLeft;
				});
				
				slider.addEventListener('mouseleave', () => {
					isDown = false;
					slider.classList.remove('cursor-grabbing');
				});
				
				slider.addEventListener('mouseup', () => {
					isDown = false;
					slider.classList.remove('cursor-grabbing');
				});
				
				slider.addEventListener('mousemove', (e) => {
					if (!isDown) return;
					e.preventDefault();
					const x = e.pageX - slider.offsetLeft;
					const walk = (x - startX) * 2; // Scroll speed multiplier
					slider.scrollLeft = scrollLeft - walk;
				});

				// Prevent link clicks if the user was dragging
				slider.querySelectorAll('a').forEach(link => {
					link.addEventListener('click', (e) => {
						if (slider.classList.contains('cursor-grabbing')) {
							e.preventDefault();
						}
					});
				});
			});
		</script>
	</nav>
	<?php
}

/* ───────────────────────────────────────────────────────────
   4. News ticker
   ─────────────────────────────────────────────────────────── */

/**
 * Renders the marquee track items with dividers.
 */
function gjn_ticker_items_html($items)
{
	ob_start();
	$count = count($items);
	$i = 0;
	?>
	<div class="flex items-center gap-6 px-6">
		<?php foreach ($items as $item): 
			$i++;
			?>
			<a href="<?php echo esc_url($item['url']); ?>"
				class="whitespace-nowrap text-sm font-semibold text-white transition-colors hover:text-yellow-200 hover:underline">
				<?php echo esc_html($item['text']); ?>
			</a>
			
			<?php if ( $i < $count ): ?>
			<!-- Distinct divider -->
			<span class="shrink-0 text-base font-bold italic tracking-widest text-white/30">//</span>
			<?php endif; ?>
			
		<?php endforeach; ?>
	</div>
	<?php
	return ob_get_clean();
}

function gjn_news_ticker()
{
	$items = gjn_ticker_items();
	
	// Hide completely if there are no breaking news items
	if ( empty( $items ) ) {
		return;
	}
	?>
	<div class="ticker bg-red-700 text-white">
		<div class="mx-auto flex max-w-7xl items-stretch">
			<div class="relative z-10 flex shrink-0 items-center gap-2.5 bg-neutral-950 px-3.5 py-1.5 sm:px-4">
				<span class="relative flex h-2.5 w-2.5">
					<span class="absolute inline-flex h-full w-full animate-ping rounded-full bg-red-500 opacity-75"></span>
					<span class="relative inline-flex h-2.5 w-2.5 rounded-full bg-red-500"></span>
				</span>
				<span class="text-sm font-black uppercase tracking-widest">Breaking</span>
				<span class="hidden text-xs font-semibold text-neutral-300 sm:inline">විශේෂ පුවත්</span>
			</div>

			<div class="relative flex flex-1 items-center overflow-hidden">
				<div class="animate-marquee flex w-max items-center">
					<?php echo gjn_ticker_items_html($items); // phpcs:ignore WordPress.Security.EscapeOutput ?>
				</div>
			</div>
		</div>
	</div>
	<?php
}
