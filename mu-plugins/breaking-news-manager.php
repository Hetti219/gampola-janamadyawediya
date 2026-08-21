<?php
/**
 * Plugin Name: Breaking News Manager
 * Description: Adds a meta box to mark posts as "Breaking News" with a scheduled auto-expiry (using WP-Cron).
 *
 * @package Gampola_Janamadyawediya
 */

defined( 'ABSPATH' ) || exit;

// 1. Register the meta box
add_action( 'add_meta_boxes', 'gjn_add_breaking_news_meta_box' );
function gjn_add_breaking_news_meta_box() {
	add_meta_box(
		'gjn_breaking_news',
		'Breaking News',
		'gjn_render_breaking_news_meta_box',
		'post',
		'side',
		'high'
	);
}

function gjn_render_breaking_news_meta_box( $post ) {
	wp_nonce_field( 'gjn_breaking_news_save', 'gjn_breaking_news_nonce' );

	$is_breaking = get_post_meta( $post->ID, '_gjn_is_breaking', true );
	$expiry_time = get_post_meta( $post->ID, '_gjn_breaking_expiry_time', true ); // UNIX timestamp
	
	// Default to 24 hours if not set
	$duration_hours = get_post_meta( $post->ID, '_gjn_breaking_duration', true );
	if ( ! $duration_hours ) {
		$duration_hours = 24;
	}

	?>
	<div style="padding: 10px 0;">
		<p>
			<label>
				<input type="checkbox" name="gjn_is_breaking" value="1" <?php checked( $is_breaking, '1' ); ?> />
				<strong>Mark as Breaking News</strong>
			</label>
		</p>
		
		<p style="margin-top: 10px;">
			<label for="gjn_breaking_duration">Duration:</label>
			<select name="gjn_breaking_duration" id="gjn_breaking_duration" style="width: 100%;">
				<?php
				$intervals = array( 3, 6, 9, 12, 15, 18, 21, 24 );
				foreach ( $intervals as $hours ) {
					printf(
						'<option value="%1$d" %2$s>%1$d Hours</option>',
						$hours,
						selected( $duration_hours, $hours, false )
					);
				}
				?>
			</select>
			<br />
			<span class="description">Automatically removes the breaking flag after this time.</span>
		</p>

		<?php if ( $is_breaking && $expiry_time ) : ?>
			<p style="margin-top: 10px; color: #d63638;">
				<strong>Expires:</strong> <br/>
				<?php echo esc_html( wp_date( 'M j, Y g:i a', $expiry_time ) ); ?>
			</p>
		<?php endif; ?>
	</div>
	<?php
}

// 2. Save the meta box data and schedule/clear cron
add_action( 'save_post', 'gjn_save_breaking_news_meta_data' );
function gjn_save_breaking_news_meta_data( $post_id ) {
	// Check if our nonce is set and verify it.
	if ( ! isset( $_POST['gjn_breaking_news_nonce'] ) || ! wp_verify_nonce( $_POST['gjn_breaking_news_nonce'], 'gjn_breaking_news_save' ) ) {
		return;
	}

	// If this is an autosave, our form has not been submitted, so we don't want to do anything.
	if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
		return;
	}

	// Check the user's permissions.
	if ( ! current_user_can( 'edit_post', $post_id ) ) {
		return;
	}

	// Clear any existing scheduled expiry event for this post
	wp_clear_scheduled_hook( 'gjn_remove_breaking_news_event', array( $post_id ) );

	$is_breaking = isset( $_POST['gjn_is_breaking'] ) ? '1' : '0';
	$duration = isset( $_POST['gjn_breaking_duration'] ) ? intval( $_POST['gjn_breaking_duration'] ) : 24;

	if ( $is_breaking === '1' ) {
		update_post_meta( $post_id, '_gjn_is_breaking', '1' );
		update_post_meta( $post_id, '_gjn_breaking_duration', $duration );
		
		// Calculate expiry and schedule
		$expiry_time = time() + ( $duration * HOUR_IN_SECONDS );
		update_post_meta( $post_id, '_gjn_breaking_expiry_time', $expiry_time );
		
		wp_schedule_single_event( $expiry_time, 'gjn_remove_breaking_news_event', array( $post_id ) );
	} else {
		// If unchecked, clean up meta
		delete_post_meta( $post_id, '_gjn_is_breaking' );
		delete_post_meta( $post_id, '_gjn_breaking_duration' );
		delete_post_meta( $post_id, '_gjn_breaking_expiry_time' );
	}
}

// 3. The cron callback to remove the breaking flag
add_action( 'gjn_remove_breaking_news_event', 'gjn_execute_breaking_news_removal' );
function gjn_execute_breaking_news_removal( $post_id ) {
	delete_post_meta( $post_id, '_gjn_is_breaking' );
	// We can leave the duration meta so if they edit it again, it remembers their preference.
	delete_post_meta( $post_id, '_gjn_breaking_expiry_time' );
}

/**
 * Helper function for the theme to fetch active breaking news posts.
 */
function gjn_get_breaking_posts( $limit = 5 ) {
	$args = array(
		'post_type'      => 'post',
		'post_status'    => 'publish',
		'posts_per_page' => $limit,
		'meta_query'     => array(
			array(
				'key'     => '_gjn_is_breaking',
				'value'   => '1',
				'compare' => '=',
			),
		),
		'orderby'        => 'date',
		'order'          => 'DESC',
	);

	return get_posts( $args );
}
