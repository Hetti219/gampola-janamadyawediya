<?php
/**
 * Plugin Name: Graphic Content Auto-Blur
 * Description: Automatically generates a heavily blurred copy of the featured
 *              image and sets it as the Open Graph share image for posts in
 *              the "graphic-content" category — but only when the author has
 *              NOT already set a custom social-share image via Rank Math or
 *              Yoast SEO. This is the safety net for the "censored on
 *              Facebook, full content on the site" workflow.
 *
 *              When Rank Math or Yoast SEO is active, their og:image /
 *              twitter:image output is forced to the blurred copy through
 *              their documented filters, so the real featured image is never
 *              emitted to crawlers. The blurred tag is also emitted directly
 *              as a fallback, in case a future plugin version changes those
 *              filters — duplicates are always the same blurred URL, so a
 *              crawler picking any tag still gets the censored image.
 *
 * Requires: PHP GD extension (included in the wordpress:php8.5-fpm-alpine
 * Docker image by default).
 */

/**
 * On post save, generate the blurred thumbnail if needed.
 *
 * Hooked to `save_post` (fires on create and update) so the blurred
 * image is ready before the post is shared anywhere.
 */
// Admin-only hooks: blur generation only happens on post save.
if ( is_admin() ) {
	add_action( 'save_post', 'ncg_autoblur_on_save', 20, 2 );
	add_action( 'set_object_terms', 'ncg_autoblur_on_terms_set', 20, 4 );
}

function ncg_autoblur_on_save( $post_id, $post ) {
	// Bail on autosaves, revisions, and non-posts.
	if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
		return;
	}
	if ( wp_is_post_revision( $post_id ) ) {
		return;
	}
	if ( 'post' !== $post->post_type ) {
		return;
	}

	// If the post left the category, or the author set a manual social
	// image, the blurred copy is no longer used — clean it up.
	$in_category = has_term( 'graphic-content', 'content_flag', $post_id );
	$has_manual  = get_post_meta( $post_id, 'rank_math_facebook_image', true )
		|| get_post_meta( $post_id, '_yoast_wpseo_opengraph-image', true );
	if ( ! $in_category || $has_manual ) {
		ncg_delete_blurred_file( $post_id );
		return;
	}

	// Need a featured image to blur; if it was removed, clean up the stale copy.
	$thumb_id = get_post_thumbnail_id( $post_id );
	if ( ! $thumb_id ) {
		ncg_delete_blurred_file( $post_id );
		return;
	}

	// Skip regeneration when this featured image was already blurred and the
	// file still exists (blurring is expensive: ~40 Gaussian passes).
	$blurred_url  = get_post_meta( $post_id, '_ncg_blurred_og_image', true );
	$source_thumb = get_post_meta( $post_id, '_ncg_blur_source_thumb', true );
	if ( $blurred_url && (string) $source_thumb === (string) $thumb_id ) {
		$upload_dir = wp_upload_dir();
		$existing   = trailingslashit( $upload_dir['basedir'] ) . basename( $blurred_url );
		if ( file_exists( $existing ) ) {
			return;
		}
	}

	$source_path = get_attached_file( $thumb_id );
	if ( ! $source_path || ! file_exists( $source_path ) ) {
		return;
	}

	$blurred_url = ncg_generate_blurred_image( $source_path, $post_id );
	if ( $blurred_url ) {
		update_post_meta( $post_id, '_ncg_blurred_og_image', $blurred_url );
		update_post_meta( $post_id, '_ncg_blur_source_thumb', $thumb_id );
	}
}

/**
 * Category terms are saved AFTER `save_post` fires (in both the block
 * editor and the classic editor), so a post published with the
 * "graphic-content" category in the same request would otherwise miss
 * its blurred image on first publish. Re-run the same logic whenever
 * category terms change; the skip-regeneration check keeps repeat runs
 * cheap.
 */
// (hook registered in the is_admin() block above)

function ncg_autoblur_on_terms_set( $object_id, $terms, $tt_ids, $taxonomy ) {
	if ( 'content_flag' !== $taxonomy || 'post' !== get_post_type( $object_id ) ) {
		return;
	}

	// get_post_type() reads the same cache get_post() does, so this normally
	// cannot be null — but the whole point of this plugin is that the gated
	// image never escapes, and a null dereference here would abort the save
	// request before the blur is written.
	$post = get_post( $object_id );
	if ( ! $post ) {
		return;
	}

	ncg_autoblur_on_save( $object_id, $post );
}

/**
 * Delete the generated blurred file and its meta for a post.
 */
function ncg_delete_blurred_file( $post_id ) {
	$url = get_post_meta( $post_id, '_ncg_blurred_og_image', true );
	if ( $url ) {
		$upload_dir = wp_upload_dir();
		$file       = trailingslashit( $upload_dir['basedir'] ) . basename( $url );
		if ( file_exists( $file ) ) {
			@unlink( $file );
		}
	}
	delete_post_meta( $post_id, '_ncg_blurred_og_image' );
	delete_post_meta( $post_id, '_ncg_blur_source_thumb' );
}

/**
 * Remove the blurred file when the post itself is deleted.
 */
add_action( 'before_delete_post', 'ncg_delete_blurred_file' );

/**
 * Gaussian blur passes applied to the copy. The bound is fixed by design:
 * enough passes to make the image unrecognizable, cheap enough to run inside
 * a post save.
 */
const NCG_BLUR_PASSES = 40;

/** Copies are downscaled to at most this many pixels wide before blurring. */
const NCG_BLUR_MAX_WIDTH = 600;

/**
 * Whether the source image can be decoded inside the remaining PHP memory.
 *
 * GD holds ~4 bytes per decoded pixel, so a 48 MP phone photo alone can
 * exceed the 256M PHP limit — and a fatal OOM would take the whole save
 * request down with it. Bail gracefully instead.
 */
function ncg_blur_fits_memory( $source_path, $post_id ) {
	$size = @getimagesize( $source_path );
	if ( ! $size || empty( $size[0] ) || empty( $size[1] ) ) {
		return true; // Unreadable dimensions: let the decode step decide.
	}

	$needed = (int) $size[0] * (int) $size[1] * 4;

	// A memory_limit of -1 means unlimited; wp_convert_hr_to_bytes() returns
	// 0 for "0" and the raw byte count otherwise.
	$limit    = wp_convert_hr_to_bytes( (string) ini_get( 'memory_limit' ) );
	$headroom = ( $limit > 0 ) ? $limit - memory_get_usage( true ) : $needed;

	if ( $needed <= $headroom ) {
		return true;
	}

	error_log( sprintf(
		'[ncg-autoblur] Skipping blur for post %d: %dx%d source needs ~%d MB but only ~%d MB memory is available (memory_limit=%s).',
		$post_id,
		$size[0],
		$size[1],
		(int) ( $needed / 1048576 ),
		(int) ( $headroom / 1048576 ),
		(string) ini_get( 'memory_limit' )
	) );

	return false;
}

/**
 * Decode an image file into a GD handle.
 *
 * @return GdImage|false False for an unsupported type or an unreadable file.
 */
function ncg_load_image( $source_path ) {
	switch ( mime_content_type( $source_path ) ) {
		case 'image/jpeg':
			return @imagecreatefromjpeg( $source_path );
		case 'image/png':
			return @imagecreatefrompng( $source_path );
		case 'image/webp':
			return @imagecreatefromwebp( $source_path );
		default:
			return false;
	}
}

/**
 * Target dimensions for a downscaled copy.
 *
 * Pure arithmetic, kept separate from the GD calls so the degenerate cases
 * are testable without the gd extension (see tests/run.php).
 *
 * Both dimensions are clamped to at least 1: a very wide, short image
 * (banner, panorama, strip) rounds to zero rows, and imagecreatetruecolor()
 * rejects a zero dimension with a ValueError that would take the whole save
 * request down.
 *
 * @return array{0:int,1:int} [width, height], both >= 1.
 */
function ncg_scaled_dimensions( $w, $h ) {
	$new_w = min( $w, NCG_BLUR_MAX_WIDTH );
	$new_h = max( 1, (int) round( $h * $new_w / $w ) );

	return array( max( 1, $new_w ), $new_h );
}

/**
 * Downscale an image to NCG_BLUR_MAX_WIDTH wide, in place.
 *
 * Downscaling first is what makes the blur passes affordable and the result
 * unrecognizable. On failure the original handle is returned unchanged —
 * slower to blur, but still blurred, which is the property that matters.
 *
 * @return GdImage
 */
function ncg_downscale_image( $img ) {
	$w = imagesx( $img );
	$h = imagesy( $img );

	if ( $w <= NCG_BLUR_MAX_WIDTH ) {
		return $img;
	}

	list( $new_w, $new_h ) = ncg_scaled_dimensions( $w, $h );

	$scaled = imagecreatetruecolor( $new_w, $new_h );
	if ( false === $scaled ) {
		return $img;
	}

	if ( ! imagecopyresampled( $scaled, $img, 0, 0, 0, 0, $new_w, $new_h, $w, $h ) ) {
		imagedestroy( $scaled );
		return $img;
	}

	imagedestroy( $img );

	return $scaled;
}

/**
 * Blur an image until it is unrecognizable, then pixelate on top.
 *
 * @return bool False if any pass failed — a partially-blurred image must
 *              never be published as the censored social image, so callers
 *              abort rather than save it.
 */
function ncg_blur_image( $img ) {
	for ( $i = 0; $i < NCG_BLUR_PASSES; $i++ ) {
		if ( ! imagefilter( $img, IMG_FILTER_GAUSSIAN_BLUR ) ) {
			return false;
		}
	}

	return imagefilter( $img, IMG_FILTER_PIXELATE, 8, true );
}

/**
 * Generate a heavily blurred copy of the source image.
 *
 * Returns the URL of the blurred file, or false on failure. Every step is
 * checked: returning false is always safe here (the author keeps the real
 * image on the site, the social image is simply not overridden), whereas
 * publishing a half-blurred copy would defeat the plugin entirely.
 */
function ncg_generate_blurred_image( $source_path, $post_id ) {
	if ( ! ncg_blur_fits_memory( $source_path, $post_id ) ) {
		return false;
	}

	$img = ncg_load_image( $source_path );
	if ( ! $img ) {
		return false;
	}

	$img = ncg_downscale_image( $img );

	if ( ! ncg_blur_image( $img ) ) {
		error_log( sprintf(
			'[ncg-autoblur] GD blur failed for post %d — refusing to publish a partially-censored image.',
			$post_id
		) );
		imagedestroy( $img );
		return false;
	}

	$upload_dir = wp_upload_dir();
	if ( ! empty( $upload_dir['error'] ) ) {
		error_log( sprintf(
			'[ncg-autoblur] Uploads directory unavailable for post %d: %s',
			$post_id,
			(string) $upload_dir['error']
		) );
		imagedestroy( $img );
		return false;
	}

	// Save into the uploads directory alongside the original.
	$filename  = sprintf( 'ncg-blurred-%d.jpg', $post_id );
	$dest_path = trailingslashit( $upload_dir['basedir'] ) . $filename;

	$saved = imagejpeg( $img, $dest_path, 70 );
	imagedestroy( $img );

	if ( ! $saved ) {
		return false;
	}

	return trailingslashit( $upload_dir['baseurl'] ) . $filename;
}

/**
 * Whether the blurred image should be used as the social image for the
 * current page. Returns the blurred URL, or false.
 */
function ncg_should_use_blurred_og() {
	// Static cache: this function is called 5+ times per page (once per
	// Yoast/RankMath filter + direct OG tag). Cache to avoid redundant
	// get_post_meta() lookups.
	static $cache = null;
	if ( null !== $cache ) {
		return $cache;
	}

	$cache = false;

	if ( ! is_single() ) {
		return $cache;
	}

	$post_id = get_the_ID();
	if ( ! has_term( 'graphic-content', 'content_flag', $post_id ) ) {
		return $cache;
	}

	// If author set an explicit social image, respect it.
	$has_rankmath = get_post_meta( $post_id, 'rank_math_facebook_image', true );
	$has_yoast    = get_post_meta( $post_id, '_yoast_wpseo_opengraph-image', true );
	if ( $has_rankmath || $has_yoast ) {
		return $cache;
	}

	$blurred_url = get_post_meta( $post_id, '_ncg_blurred_og_image', true );
	if ( $blurred_url ) {
		$cache = $blurred_url;
	}

	// This value is memoized for the rest of the request and read by five
	// callbacks; a non-string leaking out would be printed straight into an
	// og:image tag.
	assert( false === $cache || is_string( $cache ) );

	return $cache;
}

/**
 * Force the blurred image through Yoast SEO's og:image / twitter:image
 * filters. Without this, Yoast would output the real featured image as
 * the social image and leak it into Facebook/Twitter link previews.
 */
add_filter( 'wpseo_opengraph_image', 'ncg_filter_plugin_og_image', 20 );
add_filter( 'wpseo_twitter_image', 'ncg_filter_plugin_og_image', 20 );

/**
 * Force the blurred image through Rank Math's og:image / twitter:image
 * filters. Rank Math's final string-URL hook is
 * `rank_math/opengraph/{network}/image`.
 */
add_filter( 'rank_math/opengraph/facebook/image', 'ncg_filter_plugin_og_image', 20 );
add_filter( 'rank_math/opengraph/twitter/image', 'ncg_filter_plugin_og_image', 20 );

function ncg_filter_plugin_og_image( $image ) {
	$blurred = ncg_should_use_blurred_og();
	return $blurred ? $blurred : $image;
}

/**
 * Rank Math also passes the image as an array (url/width/height) through
 * `rank_math/opengraph/{network}/image_array`; overwrite the url there
 * too, so both the `og:image` tag and any `og:image:url` Rank Math emits
 * carry the blurred copy.
 */
add_filter( 'rank_math/opengraph/facebook/image_array', 'ncg_filter_rankmath_image_array', 20 );
add_filter( 'rank_math/opengraph/twitter/image_array', 'ncg_filter_rankmath_image_array', 20 );

function ncg_filter_rankmath_image_array( $attachment ) {
	$blurred = ncg_should_use_blurred_og();
	if ( $blurred && is_array( $attachment ) ) {
		$attachment['url'] = $blurred;
	}
	return $attachment;
}

/**
 * Output the blurred image as the og:image tag.
 *
 * Emitted even when an SEO plugin is active: the plugin filters above
 * normally force their own output to the same blurred URL, and if a
 * future plugin version drops those filters, this tag still guarantees
 * the raw image never appears in a link preview (a crawler receiving
 * duplicate identical tags simply picks one).
 *
 * Priority 1 (very early) so SEO plugins that run later can override if
 * the author adds a manual social image after the fact.
 */
add_action( 'wp_head', 'ncg_autoblur_og_tag', 1 );

function ncg_autoblur_og_tag() {
	$blurred_url = ncg_should_use_blurred_og();
	if ( $blurred_url ) {
		printf(
			'<meta property="og:image" content="%s" />' . "\n",
			esc_url( $blurred_url )
		);
	}
}
