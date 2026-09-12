<?php
/**
 * Plugin Name: Armstrong Gathering
 * Description: The small, first-party RSVP and potluck layer for Armstrong Thanksgiving.
 * Version: 0.3.5
 * Requires at least: 6.8
 * Requires PHP: 8.1
 * Author: Armstrong Thanksgiving
 * Text Domain: armstrong-gathering
 */

defined( 'ABSPATH' ) || exit;

define( 'AT_GATHERING_VERSION', '0.3.5' );
define( 'AT_GATHERING_FILE', __FILE__ );
define( 'AT_GATHERING_DIR', plugin_dir_path( __FILE__ ) );
define( 'AT_GATHERING_URL', plugin_dir_url( __FILE__ ) );

function at_gathering_table() {
	global $wpdb;
	return $wpdb->prefix . 'at_rsvps';
}

function at_gathering_default_foods() {
	return array(
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
	if ( false === get_option( 'at_gathering_invite_key', false ) ) {
		update_option( 'at_gathering_invite_key', wp_generate_password( 32, false, false ) );
	}
	at_gathering_migrate_content();
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
		at_gathering_migrate_content();
		update_option( 'at_gathering_db_version', AT_GATHERING_VERSION );
	}
}
add_action( 'init', 'at_gathering_maybe_upgrade', 20 );

function at_gathering_migrate_content() {
	$foods = (array) get_option( 'at_gathering_foods', array() );
	$foods = array_values( array_diff( $foods, array( 'Turkey or vegetarian centrepiece' ) ) );
	if ( ! $foods ) {
		$foods = at_gathering_default_foods();
	}
	update_option( 'at_gathering_foods', $foods );
	if ( false === get_option( 'at_gathering_invite_key', false ) ) {
		update_option( 'at_gathering_invite_key', wp_generate_password( 32, false, false ) );
	}
	foreach ( get_users( array( 'fields' => 'ID' ) ) as $user_id ) {
		at_gathering_assign_avatar( $user_id );
	}
	$page = get_page_by_path( 'rsvp' );
	if ( $page && '[at_rsvp]' !== trim( $page->post_content ) ) {
		wp_update_post( array( 'ID' => $page->ID, 'post_content' => '[at_rsvp]' ) );
	}
}

function at_gathering_invite_is_valid() {
	$key      = (string) get_option( 'at_gathering_invite_key', '' );
	$supplied = sanitize_text_field( wp_unslash( $_GET['invite'] ?? $_COOKIE['at_gathering_invite'] ?? '' ) );
	return $key && $supplied && hash_equals( $key, $supplied );
}

function at_gathering_remember_invite() {
	if ( isset( $_GET['invite'] ) && at_gathering_invite_is_valid() && ! headers_sent() ) {
		setcookie( 'at_gathering_invite', sanitize_text_field( wp_unslash( $_GET['invite'] ) ), array(
			'expires'  => time() + WEEK_IN_SECONDS,
			'path'     => COOKIEPATH ?: '/',
			'secure'   => is_ssl(),
			'httponly' => true,
			'samesite' => 'Lax',
		) );
	}
}
add_action( 'init', 'at_gathering_remember_invite' );

function at_gathering_allow_invited_rsvp() {
	// The home page and RSVP are intentionally public. Friends create an
	// account as the final step of the RSVP instead of using an invite URL.
}
add_action( 'wp', 'at_gathering_allow_invited_rsvp', 1 );

function at_gathering_require_member_pages() {
	if ( is_user_logged_in() || is_admin() || wp_doing_ajax() ) {
		return;
	}
	if ( is_page( array( 'albums', 'forum' ) ) || is_singular( array( 'forum', 'topic', 'reply' ) ) ) {
		wp_safe_redirect( wp_login_url( home_url( add_query_arg( array(), $GLOBALS['wp']->request ?? '' ) ) ) );
		exit;
	}
}
add_action( 'template_redirect', 'at_gathering_require_member_pages', 2 );

function at_gathering_require_rest_member( $result ) {
	if ( null !== $result || is_user_logged_in() ) {
		return $result;
	}
	return new WP_Error( 'at_private_rest', 'You must be signed in to access this site data.', array( 'status' => 401 ) );
}
add_filter( 'rest_authentication_errors', 'at_gathering_require_rest_member', 20 );

function at_gathering_hide_member_admin_bar( $show ) {
	return current_user_can( 'manage_options' ) ? $show : false;
}
add_filter( 'show_admin_bar', 'at_gathering_hide_member_admin_bar' );

function at_gathering_form_values() {
	$token = sanitize_key( wp_unslash( $_GET['at_form'] ?? '' ) );
	if ( ! $token ) {
		return array();
	}
	$values = get_transient( 'at_gathering_form_' . $token );
	delete_transient( 'at_gathering_form_' . $token );
	return is_array( $values ) ? $values : array();
}

function at_gathering_redirect_error( $return, $message, $values = array() ) {
	$args = array( 'at_rsvp' => 'error', 'at_message' => $message );
	if ( $values ) {
		$token = strtolower( wp_generate_password( 20, false, false ) );
		set_transient( 'at_gathering_form_' . $token, $values, 10 * MINUTE_IN_SECONDS );
		$args['at_form'] = $token;
	}
	wp_safe_redirect( add_query_arg( $args, $return ) );
	exit;
}

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

function at_gathering_unique_login( $email ) {
	$base = sanitize_user( strstr( $email, '@', true ), true );
	$base = $base ?: 'guest';
	$base = substr( $base, 0, 55 );
	$login = $base;
	$suffix = 2;
	while ( username_exists( $login ) ) {
		$login = substr( $base, 0, 60 - strlen( (string) $suffix ) - 1 ) . '-' . $suffix;
		$suffix++;
	}
	return $login;
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
	if ( is_page( 'rsvp' ) ) {
		wp_enqueue_script( 'armstrong-gathering-rsvp', AT_GATHERING_URL . 'assets/rsvp.js', array(), AT_GATHERING_VERSION, true );
	}
}
add_action( 'wp_enqueue_scripts', 'at_gathering_enqueue_assets' );

function at_gathering_redirect_removed_food_page() {
	$request_path = trim( (string) wp_parse_url( $_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH ), '/' );
	if ( 'food' !== $request_path && ! is_page( 'food' ) ) {
		return;
	}

	wp_safe_redirect( home_url( '/rsvp/' ), 301 );
	exit;
}
add_action( 'template_redirect', 'at_gathering_redirect_removed_food_page', 1 );

// WP Photo Album Plus registers its full interaction bundle during `init`,
// even on pages that do not contain an album. Keep that bundle on the album
// page only so the public entry point stays responsive on shared hosting.
function at_gathering_trim_album_assets() {
	if ( is_page( 'albums' ) ) {
		return;
	}
	foreach ( array( 'wppa-decls', 'wppa-utils', 'wppa-main', 'wppa-slideshow', 'wppa-ajax-front', 'wppa-lightbox', 'wppa-popup', 'wppa-touch', 'wppa-zoom', 'wppa-spheric', 'wppa-flatpan', 'wppa' ) as $handle ) {
		wp_dequeue_script( $handle );
	}
	wp_dequeue_style( 'wppa_style' );
}
add_action( 'wp_enqueue_scripts', 'at_gathering_trim_album_assets', 100 );

function at_gathering_body_class( $classes ) {
	if ( is_page( 'rsvp' ) ) {
		$classes[] = 'at-rsvp-page';
	}
	return $classes;
}
add_filter( 'body_class', 'at_gathering_body_class' );

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
	$user    = wp_get_current_user();
	$rsvp    = $user->exists() ? at_gathering_get_rsvp( $user->ID ) : null;
	$foods   = at_gathering_foods();
	$counts  = at_gathering_food_counts();
	$chosen  = $rsvp ? (array) json_decode( $rsvp->foods, true ) : array();
	$values  = at_gathering_form_values();
	if ( $values ) {
		$chosen = (array) ( $values['foods'] ?? array() );
	}
	$form_status = $values['status'] ?? ( $rsvp ? $rsvp->status : 'yes' );
	$form_count  = isset( $values['guest_count'] ) ? (int) $values['guest_count'] : ( $rsvp ? (int) $rsvp->guest_count : 1 );
	$saved   = isset( $_GET['at_rsvp'] ) && 'saved' === sanitize_key( $_GET['at_rsvp'] );

	ob_start();
	?>
	<div class="at-rsvp-app">
		<?php if ( $saved ) : ?>
			<div class="at-success" role="status"><strong>Thanks — you’re on the list.</strong><br><?php echo 'failed' === ( $_GET['at_mail'] ?? '' ) ? 'Your RSVP was saved, but the confirmation email could not be sent. Please tell the hosts.' : 'We sent a copy of your RSVP to ' . esc_html( $user->user_email ) . '.'; ?></div>
		<?php elseif ( isset( $_GET['at_rsvp'] ) && 'error' === sanitize_key( $_GET['at_rsvp'] ) ) : ?>
			<div class="at-success at-error" role="alert"><strong>We could not save that RSVP.</strong><br><?php echo esc_html( sanitize_text_field( wp_unslash( $_GET['at_message'] ?? 'Please check the form and try again.' ) ) ); ?></div>
		<?php endif; ?>
		<form class="at-rsvp-form" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post">
			<div class="at-rsvp-intro">
				<h2>Will you join us?</h2>
				<p>Let us know if you can make it, who’s joining you, and what you might bring.</p>
			</div>
			<input type="hidden" name="action" value="at_save_rsvp">
			<input type="hidden" name="at_return_url" value="<?php echo esc_url( get_permalink() ); ?>">
			<?php wp_nonce_field( 'at_save_rsvp', 'at_rsvp_nonce' ); ?>
			<fieldset>
				<legend>Attendance</legend>
				<div class="at-choice-row">
					<label><input type="radio" name="at_status" value="yes" <?php checked( $form_status, 'yes' ); ?>> I’m coming</label>
					<label><input type="radio" name="at_status" value="maybe" <?php checked( $form_status, 'maybe' ); ?>> Maybe</label>
					<label><input type="radio" name="at_status" value="no" <?php checked( $form_status, 'no' ); ?>> I can’t make it</label>
				</div>
			</fieldset>
			<div class="at-rsvp-grid">
				<label>How many people are coming?
					<select name="at_guest_count">
						<?php for ( $i = 0; $i <= 12; $i++ ) : ?>
							<option value="<?php echo esc_attr( $i ); ?>" <?php selected( $form_count, $i ); ?>><?php echo esc_html( $i ); ?></option>
						<?php endfor; ?>
					</select>
				</label>
				<label>Names
					<input type="text" name="at_guest_names" value="<?php echo esc_attr( $values['guest_names'] ?? ( $rsvp ? $rsvp->guest_names : '' ) ); ?>" placeholder="Everyone in your RSVP">
				</label>
			</div>
			<label>Dietary notes (optional)
				<textarea name="at_dietary" rows="3" placeholder="Any allergies or dietary needs?"><?php echo esc_textarea( $values['dietary'] ?? ( $rsvp ? $rsvp->dietary : '' ) ); ?></textarea>
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
					<input type="text" name="at_custom_food" value="<?php echo esc_attr( $values['custom_food'] ?? ( $rsvp ? $rsvp->custom_food : '' ) ); ?>" placeholder="Add a dish or drink">
				</label>
			</fieldset>
			<label>Anything else for the hosts? (optional)
				<textarea name="at_notes" rows="3" placeholder="Add a note for the hosts"><?php echo esc_textarea( $values['notes'] ?? ( $rsvp ? $rsvp->notes : '' ) ); ?></textarea>
			</label>
			<?php if ( ! $user->exists() ) : ?>
				<p class="at-form-login-note at-form-login-note-top">Already have an account? <a data-at-rsvp-login href="<?php echo esc_url( wp_login_url( get_permalink() ) ); ?>">Sign in first</a>. We’ll keep what you’ve entered here while you sign in.</p>
				<fieldset class="at-account-fields">
					<legend>Create your account</legend>
					<p class="at-field-help">Create an account to RSVP. It will also give you access to the gathering forum and shared photos.</p>
					<div class="at-rsvp-grid">
						<label>Display name<input type="text" name="at_display_name" value="<?php echo esc_attr( $values['display_name'] ?? '' ); ?>" autocomplete="name" required></label>
						<label>Email<input type="email" name="at_email" value="<?php echo esc_attr( $values['email'] ?? '' ); ?>" autocomplete="email" required></label>
						<label>Password<input type="password" name="at_password" autocomplete="new-password" minlength="10" required></label>
						<label>Confirm password<input type="password" name="at_password_confirm" autocomplete="new-password" minlength="10" required></label>
					</div>
				</fieldset>
			<?php endif; ?>
			<p class="at-form-actions"><button class="at-button" type="submit"><?php echo $rsvp ? 'Update my RSVP' : 'Save my RSVP'; ?></button></p>
		</form>
	</div>
	<?php
	return ob_get_clean();
}
add_shortcode( 'at_rsvp', 'at_gathering_rsvp_shortcode' );

function at_gathering_confirmation_message( $user, $status, $guest_count, $guest_names, $foods, $dietary, $notes, $greeting_name = '' ) {
	$details = at_gathering_event_details();
	$food_text = $foods ? implode( ', ', $foods ) : 'Nothing chosen yet';
	$greeting_name = $greeting_name ?: ( $user->display_name ?: $user->user_login );

	return sprintf(
		"Hi %s,\n\nThanks for letting us know about Thanksgiving.\n\nAttendance: %s\nPeople: %d\nNames: %s\nFood: %s\nDietary notes: %s\nNote for the hosts: %s\n\n%s · %s\n%s\n\nYou can update your RSVP any time from the site. Your account also gives you access to the forum and shared photos.\n\nSee you there!",
		$greeting_name,
		at_gathering_status_label( $status ),
		$guest_count,
		$guest_names ?: '—',
		$food_text,
		$dietary ?: 'None noted',
		$notes ?: 'None noted',
		$details['date'],
		$details['time'],
		$details['address']
	);
}

function at_gathering_save_rsvp() {
	if ( ! isset( $_POST['at_rsvp_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['at_rsvp_nonce'] ) ), 'at_save_rsvp' ) ) {
		wp_die( 'Sorry, we could not save that RSVP.' );
	}
	$return      = esc_url_raw( wp_unslash( $_POST['at_return_url'] ?? home_url( '/rsvp/' ) ) );
	$status      = sanitize_key( wp_unslash( $_POST['at_status'] ?? 'yes' ) );
	$status     = in_array( $status, array( 'yes', 'maybe', 'no' ), true ) ? $status : 'yes';
	$guest_count = max( 0, min( 12, absint( $_POST['at_guest_count'] ?? 0 ) ) );
	if ( 'yes' === $status && 0 === $guest_count ) {
		$guest_count = 1;
	}
	if ( 'no' === $status ) {
		$guest_count = 0;
	}
	$guest_names = sanitize_text_field( wp_unslash( $_POST['at_guest_names'] ?? '' ) );
	$submitted_foods = array_map( 'sanitize_text_field', (array) wp_unslash( $_POST['at_food'] ?? array() ) );
	$foods       = array_values( array_intersect( $submitted_foods, at_gathering_foods() ) );
	$custom_food = sanitize_text_field( wp_unslash( $_POST['at_custom_food'] ?? '' ) );
	$dietary     = sanitize_textarea_field( wp_unslash( $_POST['at_dietary'] ?? '' ) );
	$notes       = sanitize_textarea_field( wp_unslash( $_POST['at_notes'] ?? '' ) );
	if ( $custom_food && 'no' !== $status ) {
		$foods[] = $custom_food;
	}
	$form_values = compact( 'status', 'guest_count', 'guest_names', 'dietary', 'foods', 'custom_food', 'notes' );
	if ( 'no' !== $status && ! $guest_names ) {
		at_gathering_redirect_error( $return, 'Please add the names of everyone in your RSVP.', $form_values );
	}

	$created_user_id = 0;
	if ( ! is_user_logged_in() ) {
		$name     = sanitize_text_field( wp_unslash( $_POST['at_display_name'] ?? '' ) );
		$email    = sanitize_email( wp_unslash( $_POST['at_email'] ?? '' ) );
		$password = (string) wp_unslash( $_POST['at_password'] ?? '' );
		$password_confirm = (string) wp_unslash( $_POST['at_password_confirm'] ?? '' );
		$form_values = array_merge( $form_values, array( 'display_name' => $name, 'email' => $email ) );
		if ( ! $name || ! is_email( $email ) || strlen( $password ) < 10 || $password !== $password_confirm ) {
			at_gathering_redirect_error( $return, 'Please complete your account details. Passwords need at least 10 characters and must match.', $form_values );
		}
		if ( email_exists( $email ) ) {
			at_gathering_redirect_error( $return, 'There is already an account for that email. Please sign in instead.', $form_values );
		}
		$username = at_gathering_unique_login( $email );
		$user_id = wp_insert_user( array( 'user_login' => $username, 'user_pass' => $password, 'user_email' => $email, 'display_name' => $name, 'role' => 'subscriber' ) );
		if ( is_wp_error( $user_id ) ) {
			at_gathering_redirect_error( $return, $user_id->get_error_message(), $form_values );
		}
		$created_user_id = (int) $user_id;
		if ( function_exists( 'bbp_set_user_role' ) && function_exists( 'bbp_get_participant_role' ) ) {
			bbp_set_user_role( $user_id, bbp_get_participant_role() );
		}
		wp_set_current_user( $user_id );
		wp_set_auth_cookie( $user_id, true );
	}
	$user = wp_get_current_user();

	$data = array(
		'user_id'     => $user->ID,
		'status'      => $status,
		'guest_count' => $guest_count,
		'guest_names' => $guest_names,
		'dietary'     => $dietary,
		'foods'       => wp_json_encode( $foods ),
		'custom_food' => $custom_food,
		'notes'       => $notes,
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

	if ( ! $db_ok ) {
		if ( $created_user_id ) {
			require_once ABSPATH . 'wp-admin/includes/user.php';
			wp_clear_auth_cookie();
			wp_delete_user( $created_user_id );
		}
		at_gathering_redirect_error( $return, 'Please try again. Your account was not created.', $form_values );
	}

	$message = at_gathering_confirmation_message( $user, $status, $guest_count, $guest_names, $foods, $data['dietary'], $data['notes'] );
	$mail_ok = wp_mail( $user->user_email, 'Your Armstrong Thanksgiving RSVP', $message );
	wp_safe_redirect( add_query_arg( array( 'at_rsvp' => 'saved', 'at_mail' => $mail_ok ? 'sent' : 'failed' ), $return ) );
	exit;
}
add_action( 'admin_post_at_save_rsvp', 'at_gathering_save_rsvp' );
add_action( 'admin_post_nopriv_at_save_rsvp', 'at_gathering_save_rsvp' );

// Let the local acceptance suite inspect the final message after all theme
// filters have run, without handing test mail to a real transport.
if ( 'local' === wp_get_environment_type() ) {
	add_filter(
		'pre_wp_mail',
		static function ( $return, $args ) {
			set_transient( 'at_gathering_last_test_mail', $args, MINUTE_IN_SECONDS );
			return true;
		},
		100,
		2
	);
	add_action(
		'wp_ajax_at_gathering_last_test_mail',
		static function () {
			wp_send_json_success( get_transient( 'at_gathering_last_test_mail' ) );
		}
	);
}

function at_gathering_admin_menu() {
	add_menu_page( 'Gathering RSVPs', 'Gathering RSVPs', 'manage_options', 'at-gathering', 'at_gathering_admin_page', 'dashicons-heart', 26 );
}
add_action( 'admin_menu', 'at_gathering_admin_menu' );

function at_gathering_admin_page() {
	global $wpdb;
	$rows   = $wpdb->get_results( 'SELECT * FROM ' . at_gathering_table() . ' ORDER BY updated_at DESC' );
	$foods  = at_gathering_foods();
	$counts = at_gathering_food_counts();
	$admins = get_users( array( 'role' => 'administrator', 'orderby' => 'display_name', 'order' => 'ASC' ) );
	?>
	<div class="wrap at-gathering-admin">
		<h1>Gathering RSVPs</h1>
		<p>Host view: attendance, notes, and what is coming to the table.</p>
		<?php if ( 'sent' === ( $_GET['at_sample_mail'] ?? '' ) ) : ?><div class="notice notice-success is-dismissible"><p>Sample RSVP confirmation sent through WordPress mail.</p></div><?php elseif ( 'failed' === ( $_GET['at_sample_mail'] ?? '' ) ) : ?><div class="notice notice-error is-dismissible"><p>WordPress could not send the sample RSVP confirmation.</p></div><?php endif; ?>
		<div class="at-admin-foods">
			<?php foreach ( $foods as $food ) : ?><span><strong><?php echo esc_html( (int) ( $counts[ $food ] ?? 0 ) ); ?></strong> <?php echo esc_html( $food ); ?></span><?php endforeach; ?>
		</div>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="at-add-food">
			<input type="hidden" name="action" value="at_add_food"><input type="text" name="at_food" placeholder="Add another food"><button class="button button-primary">Add food</button><?php wp_nonce_field( 'at_add_food', 'at_food_nonce' ); ?>
		</form>
		<table class="widefat striped"><thead><tr><th>Friend</th><th>Status</th><th>People</th><th>Food</th><th>Dietary</th><th>Note for hosts</th><th>Updated</th></tr></thead><tbody>
		<?php if ( ! $rows ) : ?><tr><td colspan="7">No RSVPs yet.</td></tr><?php endif; ?>
		<?php foreach ( $rows as $row ) : $user = get_user_by( 'id', $row->user_id ); $row_foods = json_decode( $row->foods, true ); ?>
			<tr><td><strong><?php echo esc_html( $user ? $user->display_name : 'Unknown friend' ); ?></strong><br><small><?php echo esc_html( $user ? $user->user_email : '' ); ?></small></td><td><?php echo esc_html( at_gathering_status_label( $row->status ) ); ?></td><td><?php echo esc_html( $row->guest_count ); ?><?php echo $row->guest_names ? '<br><small>' . esc_html( $row->guest_names ) . '</small>' : ''; ?></td><td><?php echo esc_html( implode( ', ', (array) $row_foods ) ?: '—' ); ?></td><td><?php echo esc_html( $row->dietary ?: '—' ); ?></td><td><?php echo esc_html( $row->notes ?: '—' ); ?></td><td><?php echo esc_html( mysql2date( 'j M, H:i', $row->updated_at ) ); ?></td></tr>
		<?php endforeach; ?></tbody></table>
		<?php if ( $admins ) : ?>
			<hr>
			<h2>Send a sample confirmation</h2>
			<p>This sends the normal RSVP confirmation through WordPress mail without saving an RSVP.</p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="at_send_sample_rsvp_email">
				<label for="at-sample-user">Recipient</label>
				<select id="at-sample-user" name="at_sample_user_id">
					<?php foreach ( $admins as $admin ) : ?><option value="<?php echo esc_attr( $admin->ID ); ?>" <?php selected( $admin->ID, 1 ); ?>><?php echo esc_html( $admin->display_name . ' · ' . $admin->user_email ); ?></option><?php endforeach; ?>
				</select>
				<button class="button button-primary" type="submit">Send sample RSVP confirmation</button>
				<?php wp_nonce_field( 'at_send_sample_rsvp_email', 'at_sample_email_nonce' ); ?>
			</form>
		<?php endif; ?>
	</div>
	<?php
}

function at_gathering_send_sample_rsvp_email() {
	if ( ! current_user_can( 'manage_options' ) || ! isset( $_POST['at_sample_email_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['at_sample_email_nonce'] ) ), 'at_send_sample_rsvp_email' ) ) {
		wp_die( 'Sorry, the sample confirmation could not be sent.' );
	}
	$recipient = get_user_by( 'id', absint( $_POST['at_sample_user_id'] ?? 0 ) );
	if ( ! $recipient || ! in_array( 'administrator', (array) $recipient->roles, true ) ) {
		wp_die( 'Choose an administrator as the sample recipient.' );
	}
	$message = at_gathering_confirmation_message( $recipient, 'yes', 2, 'Fergal and a guest', array( 'Stuffing', 'Gravy' ), 'None noted', 'Looking forward to it.', 'Fergal' );
	$sent = wp_mail( $recipient->user_email, 'Your Armstrong Thanksgiving RSVP', $message );
	$url  = add_query_arg( array( 'page' => 'at-gathering', 'at_sample_mail' => $sent ? 'sent' : 'failed' ), admin_url( 'admin.php' ) );
	wp_safe_redirect( $url );
	exit;
}
add_action( 'admin_post_at_send_sample_rsvp_email', 'at_gathering_send_sample_rsvp_email' );

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

function at_gathering_rotate_invite() {
	if ( ! current_user_can( 'manage_options' ) || ! isset( $_POST['at_invite_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['at_invite_nonce'] ) ), 'at_rotate_invite' ) ) {
		wp_die( 'Sorry, the invite link could not be replaced.' );
	}
	update_option( 'at_gathering_invite_key', wp_generate_password( 32, false, false ) );
	wp_safe_redirect( admin_url( 'admin.php?page=at-gathering' ) );
	exit;
}
add_action( 'admin_post_at_rotate_invite', 'at_gathering_rotate_invite' );
