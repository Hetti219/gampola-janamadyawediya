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
add_action( 'save_post', 'ncg_autoblur_on_save', 20, 2 );

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
	$in_category = has_category( 'graphic-content', $post_id );
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
add_action( 'set_object_terms', 'ncg_autoblur_on_terms_set', 20, 4 );

function ncg_autoblur_on_terms_set( $object_id, $terms, $tt_ids, $taxonomy ) {
	if ( 'category' !== $taxonomy || 'post' !== get_post_type( $object_id ) ) {
		return;
	}
	ncg_autoblur_on_save( $object_id, get_post( $object_id ) );
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
 * Generate a heavily blurred copy of the source image.
 *
 * Applies Gaussian blur many times via GD to ensure the image is
 * unrecognizable. Returns the URL of the blurred file, or false on
 * failure.
 */
function ncg_generate_blurred_image( $source_path, $post_id ) {
	$mime = mime_content_type( $source_path );

	switch ( $mime ) {
		case 'image/jpeg':
			$img = @imagecreatefromjpeg( $source_path );
			break;
		case 'image/png':
			$img = @imagecreatefrompng( $source_path );
			break;
		case 'image/webp':
			$img = @imagecreatefromwebp( $source_path );
			break;
		default:
			return false;
	}

	if ( ! $img ) {
		return false;
	}

	// Scale down first for performance and stronger blur effect, then blur.
	$w = imagesx( $img );
	$h = imagesy( $img );

	// Downscale to max 600px wide to speed up the blur passes.
	$max_w = 600;
	if ( $w > $max_w ) {
		$ratio  = $max_w / $w;
		$new_w  = $max_w;
		$new_h  = (int) round( $h * $ratio );
		$scaled = imagecreatetruecolor( $new_w, $new_h );
		imagecopyresampled( $scaled, $img, 0, 0, 0, 0, $new_w, $new_h, $w, $h );
		imagedestroy( $img );
		$img = $scaled;
	}

	// Apply Gaussian blur aggressively (40 passes makes the image
	// completely unrecognizable while still retaining general color).
	for ( $i = 0; $i < 40; $i++ ) {
		imagefilter( $img, IMG_FILTER_GAUSSIAN_BLUR );
	}

	// Pixelate on top of the blur for extra obscuring.
	imagefilter( $img, IMG_FILTER_PIXELATE, 8, true );

	// Save into the uploads directory alongside the original.
	$upload_dir = wp_upload_dir();
	$filename   = sprintf( 'ncg-blurred-%d.jpg', $post_id );
	$dest_path  = trailingslashit( $upload_dir['basedir'] ) . $filename;
	$dest_url   = trailingslashit( $upload_dir['baseurl'] ) . $filename;

	$saved = imagejpeg( $img, $dest_path, 70 );
	imagedestroy( $img );

	return $saved ? $dest_url : false;
}

/**
 * Whether the blurred image should be used as the social image for the
 * current page. Returns the blurred URL, or false.
 */
function ncg_should_use_blurred_og() {
	if ( ! is_single() ) {
		return false;
	}

	$post_id = get_the_ID();
	if ( ! has_category( 'graphic-content', $post_id ) ) {
		return false;
	}

	// If author set an explicit social image, respect it.
	$has_rankmath = get_post_meta( $post_id, 'rank_math_facebook_image', true );
	$has_yoast    = get_post_meta( $post_id, '_yoast_wpseo_opengraph-image', true );
	if ( $has_rankmath || $has_yoast ) {
		return false;
	}

	$blurred_url = get_post_meta( $post_id, '_ncg_blurred_og_image', true );
	return $blurred_url ? $blurred_url : false;
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
