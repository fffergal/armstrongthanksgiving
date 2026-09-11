<?php
/**
 * Plugin Name: Armstrong Gathering
 * Description: The small, first-party RSVP and potluck layer for Armstrong Thanksgiving.
 * Version: 0.1.0
 * Requires at least: 6.8
 * Requires PHP: 8.1
 * Author: Armstrong Thanksgiving
 * Text Domain: armstrong-gathering
 */

defined( 'ABSPATH' ) || exit;

define( 'AT_GATHERING_VERSION', '0.1.0' );
define( 'AT_GATHERING_FILE', __FILE__ );
define( 'AT_GATHERING_DIR', plugin_dir_path( __FILE__ ) );
define( 'AT_GATHERING_URL', plugin_dir_url( __FILE__ ) );

function at_gathering_table() {
	global $wpdb;
	return $wpdb->prefix . 'at_rsvps';
}

function at_gathering_default_foods() {
	return array(
		'Turkey or vegetarian centrepiece',
		'Stuffing',
		'Mashed potatoes',
		'Gravy',
		'Cranberry sauce',
		'Green bean casserole',
		'Sweet potatoes',
		'Dinner rolls',
		'Pumpkin pie',
		'Drinks',
	);
}

function at_gathering_foods() {
	$foods = get_option( 'at_gathering_foods', at_gathering_default_foods() );
	return array_values( array_filter( array_map( 'sanitize_text_field', (array) $foods ) ) );
}

function at_gathering_event_details() {
	return array(
		'date'    => 'Friday 21 November',
		'time'    => '16:00',
		'address' => '52 Priestfield Crescent, Edinburgh EH16 5JG',
	);
}

function at_gathering_activate() {
	global $wpdb;

	at_gathering_ensure_schema();

	if ( false === get_option( 'at_gathering_foods', false ) ) {
		update_option( 'at_gathering_foods', at_gathering_default_foods() );
	}
	if ( false === get_option( 'at_gathering_next_avatar', false ) ) {
		update_option( 'at_gathering_next_avatar', 1 );
	}
	update_option( 'at_gathering_db_version', AT_GATHERING_VERSION );

	// Give existing members a turkey as soon as the plugin is first enabled.
	foreach ( get_users( array( 'fields' => 'ID' ) ) as $user_id ) {
		at_gathering_assign_avatar( $user_id );
	}

	// Turn the old demo topic into a general hello thread while retaining the
	// old slug redirect WordPress records for existing bookmarks.
	$old_topic = get_page_by_path( 'rsvp-and-food', OBJECT, 'topic' );
	if ( $old_topic ) {
		wp_update_post(
			array(
				'ID'           => $old_topic->ID,
				'post_title'   => 'Say hello',
				'post_name'    => 'say-hello',
				'post_content' => 'Share a hello, a photo, or a small plan for the day. RSVP and food choices live on the RSVP page.',
			)
		);
	}
}

function at_gathering_ensure_schema() {
	global $wpdb;

	$table  = at_gathering_table();
	$collate = $wpdb->get_charset_collate();
	$sql    = "CREATE TABLE {$table} (
		id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
		user_id bigint(20) unsigned NOT NULL,
		status varchar(16) NOT NULL DEFAULT 'yes',
		guest_count smallint(5) unsigned NOT NULL DEFAULT 1,
		guest_names text NOT NULL,
		dietary text NOT NULL,
		foods longtext NOT NULL,
		custom_food varchar(191) NOT NULL DEFAULT '',
		notes text NOT NULL,
		created_at datetime NOT NULL,
		updated_at datetime NOT NULL,
		PRIMARY KEY  (id),
		UNIQUE KEY user_id (user_id),
		KEY status (status)
	) {$collate};";

	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	dbDelta( $sql );
}
register_activation_hook( AT_GATHERING_FILE, 'at_gathering_activate' );

function at_gathering_maybe_upgrade() {
	if ( get_option( 'at_gathering_db_version' ) !== AT_GATHERING_VERSION ) {
		at_gathering_ensure_schema();
		update_option( 'at_gathering_db_version', AT_GATHERING_VERSION );
	}
}
add_action( 'plugins_loaded', 'at_gathering_maybe_upgrade', 5 );

function at_gathering_user_id( $id_or_email ) {
	if ( is_numeric( $id_or_email ) ) {
		return absint( $id_or_email );
	}
	if ( $id_or_email instanceof WP_User ) {
		return (int) $id_or_email->ID;
	}
	if ( is_object( $id_or_email ) && ! empty( $id_or_email->user_id ) ) {
		return absint( $id_or_email->user_id );
	}
	if ( is_string( $id_or_email ) && is_email( $id_or_email ) ) {
		$user = get_user_by( 'email', $id_or_email );
		return $user ? (int) $user->ID : 0;
	}
	return 0;
}

function at_gathering_avatar_file( $user_id ) {
	$file = get_user_meta( $user_id, 'at_gathering_avatar', true );
	return is_string( $file ) && preg_match( '/^turkey-\d{2}\.png$/', $file ) ? $file : '';
}

function at_gathering_avatar_url( $user_id ) {
	$file = at_gathering_avatar_file( $user_id );
	return $file ? AT_GATHERING_URL . 'assets/avatars/' . $file : '';
}

function at_gathering_assign_avatar( $user_id ) {
	$user_id = absint( $user_id );
	if ( ! $user_id || at_gathering_avatar_file( $user_id ) ) {
		return;
	}

	$next = max( 1, absint( get_option( 'at_gathering_next_avatar', 1 ) ) );
	if ( $next > 50 ) {
		return;
	}
	update_user_meta( $user_id, 'at_gathering_avatar', sprintf( 'turkey-%02d.png', $next ) );
	update_option( 'at_gathering_next_avatar', $next + 1 );
}
add_action( 'user_register', 'at_gathering_assign_avatar' );

function at_gathering_custom_avatar_data( $args, $id_or_email ) {
	$user_id = at_gathering_user_id( $id_or_email );
	$url     = at_gathering_avatar_url( $user_id );
	if ( $url ) {
		$args['url']    = $url;
		$args['found']  = true;
		$args['height'] = $args['size'] ?? 48;
		$args['width']  = $args['size'] ?? 48;
	}
	return $args;
}
add_filter( 'pre_get_avatar_data', 'at_gathering_custom_avatar_data', 10, 2 );

function at_gathering_custom_avatar_url( $url, $id_or_email ) {
	$custom = at_gathering_avatar_url( at_gathering_user_id( $id_or_email ) );
	return $custom ?: $url;
}
add_filter( 'get_avatar_url', 'at_gathering_custom_avatar_url', 10, 2 );

function at_gathering_enqueue_assets() {
	if ( ! is_page( 'rsvp' ) && ! is_page( 'food' ) && ! is_singular( array( 'forum', 'topic', 'reply' ) ) ) {
		return;
	}
	wp_enqueue_style( 'armstrong-gathering', AT_GATHERING_URL . 'assets/gathering.css', array(), AT_GATHERING_VERSION );
}
add_action( 'wp_enqueue_scripts', 'at_gathering_enqueue_assets' );

function at_gathering_get_rsvp( $user_id ) {
	global $wpdb;
	return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . at_gathering_table() . ' WHERE user_id = %d', absint( $user_id ) ) );
}

function at_gathering_food_counts() {
	global $wpdb;
	$counts = array();
	$rows   = $wpdb->get_col( 'SELECT foods FROM ' . at_gathering_table() . " WHERE status IN ('yes','maybe')" );
	foreach ( $rows as $json ) {
		$foods = json_decode( $json, true );
		foreach ( (array) $foods as $food ) {
			$food = sanitize_text_field( $food );
			if ( $food ) {
				$counts[ $food ] = ( $counts[ $food ] ?? 0 ) + 1;
			}
		}
	}
	return $counts;
}

function at_gathering_status_label( $status ) {
	return array(
		'yes'   => 'Coming',
		'maybe' => 'Maybe',
		'no'    => 'Can’t make it',
	)[ $status ] ?? 'Coming';
}

function at_gathering_rsvp_shortcode() {
	if ( ! is_user_logged_in() ) {
		return '<div class="at-access-note"><strong>Friends’ RSVP</strong><p>Sign in to RSVP, choose something for the table, and see the shared forum and photos.</p><p><a class="at-button" href="' . esc_url( wp_login_url( get_permalink() ) ) . '">Member sign in</a></p></div>';
	}

	$user    = wp_get_current_user();
	$rsvp    = at_gathering_get_rsvp( $user->ID );
	$foods   = at_gathering_foods();
	$counts  = at_gathering_food_counts();
	$details = at_gathering_event_details();
	$chosen  = $rsvp ? (array) json_decode( $rsvp->foods, true ) : array();
	$saved   = isset( $_GET['at_rsvp'] ) && 'saved' === sanitize_key( $_GET['at_rsvp'] );

	ob_start();
	?>
	<div class="at-rsvp-app">
		<div class="at-rsvp-intro">
			<p class="at-kicker">The table is taking shape</p>
			<h2>Will you join us?</h2>
			<p><?php echo esc_html( $details['date'] . ' · ' . $details['time'] . ' · ' . $details['address'] ); ?></p>
			<p>Your account is also your key to the friends-only forum and shared photos.</p>
		</div>
		<?php if ( $saved ) : ?>
			<div class="at-success" role="status"><strong>Thanks — you’re on the list.</strong><br><?php echo 'failed' === ( $_GET['at_mail'] ?? '' ) ? 'Your RSVP was saved, but the confirmation email could not be sent. Please tell Anna.' : 'We sent a copy of your RSVP to ' . esc_html( $user->user_email ) . '.'; ?></div>
		<?php elseif ( isset( $_GET['at_rsvp'] ) && 'error' === sanitize_key( $_GET['at_rsvp'] ) ) : ?>
			<div class="at-success at-error" role="alert"><strong>We could not save that RSVP.</strong><br>Please try again or tell Anna.</div>
		<?php endif; ?>
		<form class="at-rsvp-form" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post">
			<input type="hidden" name="action" value="at_save_rsvp">
			<input type="hidden" name="at_return_url" value="<?php echo esc_url( get_permalink() ); ?>">
			<?php wp_nonce_field( 'at_save_rsvp', 'at_rsvp_nonce' ); ?>
			<fieldset>
				<legend>Attendance</legend>
				<div class="at-choice-row">
					<label><input type="radio" name="at_status" value="yes" <?php checked( $rsvp ? $rsvp->status : 'yes', 'yes' ); ?>> I’m coming</label>
					<label><input type="radio" name="at_status" value="maybe" <?php checked( $rsvp ? $rsvp->status : '', 'maybe' ); ?>> Maybe</label>
					<label><input type="radio" name="at_status" value="no" <?php checked( $rsvp ? $rsvp->status : '', 'no' ); ?>> I can’t make it</label>
				</div>
			</fieldset>
			<div class="at-rsvp-grid">
				<label>How many people are coming?
					<select name="at_guest_count">
						<?php for ( $i = 0; $i <= 12; $i++ ) : ?>
							<option value="<?php echo esc_attr( $i ); ?>" <?php selected( $rsvp ? (int) $rsvp->guest_count : 1, $i ); ?>><?php echo esc_html( $i ); ?></option>
						<?php endfor; ?>
					</select>
				</label>
				<label>Names (optional)
					<input type="text" name="at_guest_names" value="<?php echo esc_attr( $rsvp ? $rsvp->guest_names : '' ); ?>" placeholder="e.g. Anna and Sam">
				</label>
			</div>
			<label>Dietary or access notes (optional)
				<textarea name="at_dietary" rows="3" placeholder="Anything we should know?"><?php echo esc_textarea( $rsvp ? $rsvp->dietary : '' ); ?></textarea>
			</label>
			<fieldset>
				<legend>What could you bring?</legend>
				<p class="at-field-help">Pick as many as make sense. The number shows how many RSVPs have claimed each one.</p>
				<div class="at-food-list">
					<?php foreach ( $foods as $food ) : ?>
						<label><input type="checkbox" name="at_food[]" value="<?php echo esc_attr( $food ); ?>" <?php checked( in_array( $food, $chosen, true ) ); ?>><span><?php echo esc_html( $food ); ?></span><small><?php echo esc_html( (int) ( $counts[ $food ] ?? 0 ) ); ?> bringing</small></label>
					<?php endforeach; ?>
				</div>
				<label>Something else?
					<input type="text" name="at_custom_food" value="<?php echo esc_attr( $rsvp ? $rsvp->custom_food : '' ); ?>" placeholder="Add a dish or drink">
				</label>
			</fieldset>
			<label>Anything else for the host? (optional)
				<textarea name="at_notes" rows="3" placeholder="A note for Anna or the kitchen"><?php echo esc_textarea( $rsvp ? $rsvp->notes : '' ); ?></textarea>
			</label>
			<p class="at-form-actions"><button class="at-button" type="submit"><?php echo $rsvp ? 'Update my RSVP' : 'Save my RSVP'; ?></button><span>We’ll email you a friendly confirmation.</span></p>
		</form>
	</div>
	<?php
	return ob_get_clean();
}
add_shortcode( 'at_rsvp', 'at_gathering_rsvp_shortcode' );

function at_gathering_save_rsvp() {
	if ( ! is_user_logged_in() || ! isset( $_POST['at_rsvp_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['at_rsvp_nonce'] ) ), 'at_save_rsvp' ) ) {
		wp_die( 'Sorry, we could not save that RSVP.' );
	}

	$user       = wp_get_current_user();
	$status     = sanitize_key( wp_unslash( $_POST['at_status'] ?? 'yes' ) );
	$status     = in_array( $status, array( 'yes', 'maybe', 'no' ), true ) ? $status : 'yes';
	$guest_count = max( 0, min( 12, absint( $_POST['at_guest_count'] ?? 0 ) ) );
	if ( 'yes' === $status && 0 === $guest_count ) {
		$guest_count = 1;
	}
	if ( 'no' === $status ) {
		$guest_count = 0;
	}
	$submitted_foods = array_map( 'sanitize_text_field', (array) wp_unslash( $_POST['at_food'] ?? array() ) );
	$foods       = $submitted_foods;
	$foods       = array_values( array_intersect( $foods, at_gathering_foods() ) );
	$custom_food = sanitize_text_field( wp_unslash( $_POST['at_custom_food'] ?? '' ) );
	if ( $custom_food && 'no' !== $status ) {
		$foods[] = $custom_food;
	}

	$data = array(
		'user_id'     => $user->ID,
		'status'      => $status,
		'guest_count' => $guest_count,
		'guest_names' => sanitize_text_field( wp_unslash( $_POST['at_guest_names'] ?? '' ) ),
		'dietary'     => sanitize_textarea_field( wp_unslash( $_POST['at_dietary'] ?? '' ) ),
		'foods'       => wp_json_encode( $foods ),
		'custom_food' => $custom_food,
		'notes'       => sanitize_textarea_field( wp_unslash( $_POST['at_notes'] ?? '' ) ),
		'updated_at'  => current_time( 'mysql' ),
	);

	global $wpdb;
	$existing = at_gathering_get_rsvp( $user->ID );
	$db_ok = false;
	if ( $existing ) {
		$db_ok = false !== $wpdb->update( at_gathering_table(), $data, array( 'user_id' => $user->ID ) );
	} else {
		$data['created_at'] = current_time( 'mysql' );
		$db_ok = false !== $wpdb->insert( at_gathering_table(), $data );
	}

	$return = esc_url_raw( wp_unslash( $_POST['at_return_url'] ?? home_url( '/rsvp/' ) ) );
	if ( ! $db_ok ) {
		wp_safe_redirect( add_query_arg( 'at_rsvp', 'error', $return ) );
		exit;
	}

	$details = at_gathering_event_details();
	$food_text = $foods ? implode( ', ', $foods ) : 'Nothing chosen yet';
	$message = sprintf(
		"Hi %s,\n\nThanks for letting us know about Thanksgiving.\n\nAttendance: %s\nPeople: %d\nFood: %s\nDietary or access notes: %s\nNote for the host: %s\n\n%s · %s\n%s\n\nYou can update your RSVP any time from the site. Your account also gives you access to the friends-only forum and shared photos.\n\nSee you there!\nAnna",
		$user->display_name ?: $user->user_login,
		at_gathering_status_label( $status ),
		$guest_count,
		$food_text,
		$data['dietary'] ?: 'None noted',
		$data['notes'] ?: 'None noted',
		$details['date'],
		$details['time'],
		$details['address']
	);
	$mail_ok = wp_mail( $user->user_email, 'Your Armstrong Thanksgiving RSVP', $message );
	wp_safe_redirect( add_query_arg( array( 'at_rsvp' => 'saved', 'at_mail' => $mail_ok ? 'sent' : 'failed' ), $return ) );
	exit;
}
add_action( 'admin_post_at_save_rsvp', 'at_gathering_save_rsvp' );
add_action( 'admin_post_nopriv_at_save_rsvp', 'at_gathering_save_rsvp' );

function at_gathering_admin_menu() {
	add_menu_page( 'Gathering RSVPs', 'Gathering RSVPs', 'manage_options', 'at-gathering', 'at_gathering_admin_page', 'dashicons-heart', 26 );
}
add_action( 'admin_menu', 'at_gathering_admin_menu' );

function at_gathering_admin_page() {
	global $wpdb;
	$rows   = $wpdb->get_results( 'SELECT * FROM ' . at_gathering_table() . ' ORDER BY updated_at DESC' );
	$foods  = at_gathering_foods();
	$counts = at_gathering_food_counts();
	?>
	<div class="wrap at-gathering-admin">
		<h1>Gathering RSVPs</h1>
		<p>Private host view: attendance, notes, and what is coming to the table.</p>
		<div class="at-admin-foods">
			<?php foreach ( $foods as $food ) : ?><span><strong><?php echo esc_html( (int) ( $counts[ $food ] ?? 0 ) ); ?></strong> <?php echo esc_html( $food ); ?></span><?php endforeach; ?>
		</div>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="at-add-food">
			<input type="hidden" name="action" value="at_add_food"><input type="text" name="at_food" placeholder="Add another food"><button class="button button-primary">Add food</button><?php wp_nonce_field( 'at_add_food', 'at_food_nonce' ); ?>
		</form>
		<table class="widefat striped"><thead><tr><th>Friend</th><th>Status</th><th>People</th><th>Food</th><th>Dietary/access</th><th>Note for host</th><th>Updated</th></tr></thead><tbody>
		<?php if ( ! $rows ) : ?><tr><td colspan="7">No RSVPs yet.</td></tr><?php endif; ?>
		<?php foreach ( $rows as $row ) : $user = get_user_by( 'id', $row->user_id ); $row_foods = json_decode( $row->foods, true ); ?>
			<tr><td><strong><?php echo esc_html( $user ? $user->display_name : 'Unknown friend' ); ?></strong><br><small><?php echo esc_html( $user ? $user->user_email : '' ); ?></small></td><td><?php echo esc_html( at_gathering_status_label( $row->status ) ); ?></td><td><?php echo esc_html( $row->guest_count ); ?><?php echo $row->guest_names ? '<br><small>' . esc_html( $row->guest_names ) . '</small>' : ''; ?></td><td><?php echo esc_html( implode( ', ', (array) $row_foods ) ?: '—' ); ?></td><td><?php echo esc_html( $row->dietary ?: '—' ); ?></td><td><?php echo esc_html( $row->notes ?: '—' ); ?></td><td><?php echo esc_html( mysql2date( 'j M, H:i', $row->updated_at ) ); ?></td></tr>
		<?php endforeach; ?></tbody></table>
	</div>
	<?php
}

function at_gathering_add_food() {
	if ( ! current_user_can( 'manage_options' ) || ! isset( $_POST['at_food_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['at_food_nonce'] ) ), 'at_add_food' ) ) {
		wp_die( 'Sorry, that food could not be added.' );
	}
	$food  = sanitize_text_field( wp_unslash( $_POST['at_food'] ?? '' ) );
	$foods = at_gathering_foods();
	if ( $food && ! in_array( $food, $foods, true ) ) {
		$foods[] = $food;
		update_option( 'at_gathering_foods', $foods );
	}
	wp_safe_redirect( admin_url( 'admin.php?page=at-gathering' ) );
	exit;
}
add_action( 'admin_post_at_add_food', 'at_gathering_add_food' );
