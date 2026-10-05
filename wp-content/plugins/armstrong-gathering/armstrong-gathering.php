<?php
/**
 * Plugin Name: Armstrong Gathering
 * Description: The small, first-party RSVP and potluck layer for Armstrong Thanksgiving.
 * Version: 0.5.6
 * Requires at least: 6.8
 * Requires PHP: 8.1
 * Author: Armstrong Thanksgiving
 * Text Domain: armstrong-gathering
 */

defined( 'ABSPATH' ) || exit;

define( 'AT_GATHERING_VERSION', '0.5.6' );
define( 'AT_GATHERING_FILE', __FILE__ );
define( 'AT_GATHERING_DIR', plugin_dir_path( __FILE__ ) );
define( 'AT_GATHERING_URL', plugin_dir_url( __FILE__ ) );

function at_gathering_table() {
	global $wpdb;
	return $wpdb->prefix . 'at_rsvps';
}

function at_gathering_default_foods() {
	return array(
		'Stuffing — vegetarian',
		'Stuffing — non-vegetarian',
		'Mashed potato',
		'Gravy — vegetarian',
		'Gravy — non-vegetarian',
		'Cranberry sauce',
		'Green bean casserole',
		'Sweet potato casserole',
		'Rolls',
		'Carrots + beetroot',
		'Pumpkin pie',
		'Pecan pie',
		'Apple pie',
		'Sweet potato pie',
		'Nut roast',
		'Ham hock',
		'Cheese ball + crackers',
		'7-layer jalapeño dip',
	);
}

function at_gathering_foods() {
	$foods = get_option( 'at_gathering_foods', at_gathering_default_foods() );
	return array_values( array_filter( array_map( 'sanitize_text_field', (array) $foods ) ) );
}

function at_gathering_food_goals() {
	$stored = (array) get_option( 'at_gathering_food_goals', array() );
	$goals  = array();
	foreach ( at_gathering_foods() as $food ) {
		$goals[ $food ] = isset( $stored[ $food ] ) ? min( 9999, absint( $stored[ $food ] ) ) : 0;
	}
	return $goals;
}

function at_gathering_rsvp_food_amounts( $rsvp ) {
	$amounts = json_decode( (string) ( $rsvp->food_amounts ?? '' ), true );
	if ( is_array( $amounts ) && $amounts ) {
		return $amounts;
	}

	// Existing RSVPs stored one selection per dish, so carry each forward as one unit.
	$amounts = array();
	foreach ( (array) json_decode( (string) ( $rsvp->foods ?? '' ), true ) as $food ) {
		$amounts[ sanitize_text_field( $food ) ] = 1;
	}
	return $amounts;
}

function at_gathering_food_amounts_text( $foods, $amounts ) {
	$items = array();
	foreach ( (array) $foods as $food ) {
		$items[] = $food . ' × ' . max( 1, absint( $amounts[ $food ] ?? 1 ) );
	}
	return implode( ', ', $items );
}

function at_gathering_event_details() {
	$details = get_option( 'at_gathering_event_details', array() );
	$details = is_array( $details ) ? $details : array();
	return array(
		'date'    => sanitize_text_field( $details['date'] ?? '' ),
		'time'    => sanitize_text_field( $details['time'] ?? '' ),
		'address' => sanitize_text_field( $details['address'] ?? '' ),
	);
}

function at_gathering_event_details_shortcode() {
	$details = at_gathering_event_details();
	if ( ! $details['date'] ) {
		return '';
	}
	$output = '<p class="at-date-card">' . esc_html( $details['date'] );
	if ( is_user_logged_in() ) {
		if ( $details['time'] ) {
			$output .= '<small class="at-event-time">' . esc_html( $details['time'] ) . '</small>';
		}
	}
	$output .= '</p>';
	return $output;
}
add_shortcode( 'at_event_details', 'at_gathering_event_details_shortcode' );

function at_gathering_event_address_shortcode() {
	$details = at_gathering_event_details();
	if ( ! is_user_logged_in() || ! $details['address'] ) {
		return '';
	}
	return '<p class="at-event-address"><strong>Location</strong><br>' . esc_html( $details['address'] ) . '</p>';
}
add_shortcode( 'at_event_address', 'at_gathering_event_address_shortcode' );

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
		food_amounts longtext NOT NULL,
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
	// Seed the confirmed menu once; later upgrades preserve host-added dishes.
	if ( false === get_option( 'at_gathering_foods', false ) ) {
		update_option( 'at_gathering_foods', at_gathering_default_foods() );
	}
	if ( false === get_option( 'at_gathering_event_details', false ) ) {
		update_option( 'at_gathering_event_details', array() );
	}
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
	$signup_page = get_page_by_path( 'signup' );
	if ( ! $signup_page ) {
		wp_insert_post(
			array(
				'post_title'   => 'Sign up',
				'post_name'    => 'signup',
				'post_content' => '[at_signup]',
				'post_status'  => 'publish',
				'post_type'    => 'page',
			)
		);
	} elseif ( false === strpos( (string) $signup_page->post_content, '[at_signup]' ) ) {
		wp_update_post( array( 'ID' => $signup_page->ID, 'post_content' => '[at_signup]' ) );
	}
	$confirmation_page = get_page_by_path( 'rsvp-confirmation' );
	if ( ! $confirmation_page ) {
		wp_insert_post(
			array(
				'post_title'   => 'RSVP confirmation',
				'post_name'    => 'rsvp-confirmation',
				'post_content' => '[at_rsvp_confirmation]',
				'post_status'  => 'publish',
				'post_type'    => 'page',
			)
		);
	} elseif ( false === strpos( (string) $confirmation_page->post_content, '[at_rsvp_confirmation]' ) ) {
		wp_update_post( array( 'ID' => $confirmation_page->ID, 'post_content' => '[at_rsvp_confirmation]' ) );
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
	// The home page, RSVP, and standalone signup are intentionally public.
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
	if ( $token ) {
		$values = get_transient( 'at_gathering_form_' . $token );
		delete_transient( 'at_gathering_form_' . $token );
		return is_array( $values ) ? $values : array();
	}

	$draft_token = sanitize_key( wp_unslash( $_GET['at_rsvp_draft'] ?? '' ) );
	if ( ! $draft_token || ! is_user_logged_in() ) {
		return array();
	}
	$values = get_transient( 'at_gathering_rsvp_draft_' . $draft_token );
	delete_transient( 'at_gathering_rsvp_draft_' . $draft_token );
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

function at_gathering_rsvp_signin() {
	$return = home_url( '/rsvp/' );
	$logged_in = is_user_logged_in();
	if ( ! $logged_in && ( ! isset( $_POST['at_rsvp_signin_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['at_rsvp_signin_nonce'] ) ), 'at_rsvp_signin' ) ) ) {
		wp_die( 'Sorry, we could not save your RSVP draft.' );
	}

	$status = sanitize_key( wp_unslash( $_POST['at_status'] ?? 'yes' ) );
	if ( ! in_array( $status, array( 'yes', 'maybe', 'no' ), true ) ) {
		$status = 'yes';
	}
	$guest_count = max( 0, min( 12, absint( $_POST['at_guest_count'] ?? 1 ) ) );
	if ( 'yes' === $status && 0 === $guest_count ) {
		$guest_count = 1;
	}
	$submitted_amounts = (array) wp_unslash( $_POST['at_food_amounts'] ?? array() );
	$submitted_offers  = (array) wp_unslash( $_POST['at_food_offers'] ?? array() );
	$food_amounts = array();
	foreach ( at_gathering_foods() as $food ) {
		$amount = min( 9999, absint( $submitted_amounts[ md5( $food ) ] ?? 0 ) );
		if ( ! empty( $submitted_offers[ md5( $food ) ] ) ) {
			$amount = max( 1, $amount );
		}
		if ( $amount > 0 ) {
			$food_amounts[ $food ] = $amount;
		}
	}
	$guest_names = sanitize_text_field( wp_unslash( $_POST['at_guest_names'] ?? '' ) );
	$dietary = sanitize_textarea_field( wp_unslash( $_POST['at_dietary'] ?? '' ) );
	$custom_food = sanitize_text_field( wp_unslash( $_POST['at_custom_food'] ?? '' ) );
	$custom_food_amount = min( 9999, absint( $_POST['at_custom_food_amount'] ?? 0 ) );
	$notes = sanitize_textarea_field( wp_unslash( $_POST['at_notes'] ?? '' ) );
	$touched = ! empty( $_POST['_at_rsvp_touched'] ) || 'yes' !== $status || 1 !== $guest_count || '' !== $guest_names || '' !== $dietary || ! empty( $food_amounts ) || '' !== $custom_food || 0 < $custom_food_amount || '' !== $notes;
	$values = array(
		'status'            => $status,
		'guest_count'       => $guest_count,
		'guest_names'       => $guest_names,
		'dietary'           => $dietary,
		'food_amounts'      => $food_amounts,
		'custom_food'       => $custom_food,
		'custom_food_amount' => $custom_food_amount,
		'notes'             => $notes,
		'_at_rsvp_handoff' => 1,
		'_at_rsvp_touched' => $touched ? 1 : 0,
	);

	if ( $touched ) {
		$token = strtolower( wp_generate_password( 32, false, false ) );
		set_transient( 'at_gathering_rsvp_draft_' . $token, $values, 15 * MINUTE_IN_SECONDS );
		$return = add_query_arg( 'at_rsvp_draft', $token, $return );
	}
	wp_safe_redirect( $logged_in ? $return : wp_login_url( $return ) );
	exit;
}
add_action( 'admin_post_at_rsvp_signin', 'at_gathering_rsvp_signin' );
add_action( 'admin_post_nopriv_at_rsvp_signin', 'at_gathering_rsvp_signin' );

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
	if ( ! is_page( array( 'rsvp', 'rsvp-confirmation', 'signup', 'food' ) ) && ! is_singular( array( 'forum', 'topic', 'reply' ) ) ) {
		return;
	}
	$style_version = file_exists( AT_GATHERING_DIR . 'assets/gathering.css' ) ? filemtime( AT_GATHERING_DIR . 'assets/gathering.css' ) : AT_GATHERING_VERSION;
	wp_enqueue_style( 'armstrong-gathering', AT_GATHERING_URL . 'assets/gathering.css', array(), $style_version );
	if ( is_page( array( 'rsvp', 'rsvp-confirmation' ) ) ) {
		$script_version = file_exists( AT_GATHERING_DIR . 'assets/rsvp.js' ) ? filemtime( AT_GATHERING_DIR . 'assets/rsvp.js' ) : AT_GATHERING_VERSION;
		wp_enqueue_script( 'armstrong-gathering-rsvp', AT_GATHERING_URL . 'assets/rsvp.js', array(), $script_version, true );
	}
}
add_action( 'wp_enqueue_scripts', 'at_gathering_enqueue_assets' );

function at_gathering_admin_enqueue_assets( $hook_suffix ) {
	if ( 'toplevel_page_at-gathering' !== $hook_suffix ) {
		return;
	}
	$style_version = file_exists( AT_GATHERING_DIR . 'assets/gathering.css' ) ? filemtime( AT_GATHERING_DIR . 'assets/gathering.css' ) : AT_GATHERING_VERSION;
	wp_enqueue_style( 'armstrong-gathering-admin', AT_GATHERING_URL . 'assets/gathering.css', array(), $style_version );
}
add_action( 'admin_enqueue_scripts', 'at_gathering_admin_enqueue_assets' );

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
	$album_handles = array( 'wppa-decls', 'wppa-utils', 'wppa-main', 'wppa-slideshow', 'wppa-ajax-front', 'wppa-lightbox', 'wppa-popup', 'wppa-touch', 'wppa-zoom', 'wppa-spheric', 'wppa-flatpan', 'wppa' );
	$wp_scripts = wp_scripts();
	foreach ( $wp_scripts->queue as $handle ) {
		$registered = $wp_scripts->registered[ $handle ] ?? null;
		if ( $registered && false !== strpos( (string) $registered->src, '/wp-photo-album-plus' ) ) {
			$album_handles[] = $handle;
		}
	}
	$album_handles      = array_unique( $album_handles );
	foreach ( $album_handles as $handle ) {
		wp_dequeue_script( $handle );
	}

	wp_dequeue_style( 'wppa_style' );
}
add_action( 'wp_enqueue_scripts', 'at_gathering_trim_album_assets', 100 );

function at_gathering_enqueue_album_upload_assets() {
	if ( ! is_page( 'albums' ) ) {
		return;
	}

	$script_path = AT_GATHERING_DIR . 'assets/album-upload.js';
	$script_version = file_exists( $script_path ) ? filemtime( $script_path ) : AT_GATHERING_VERSION;
	wp_enqueue_script( 'armstrong-gathering-album-upload', AT_GATHERING_URL . 'assets/album-upload.js', array(), $script_version, true );
}
add_action( 'wp_enqueue_scripts', 'at_gathering_enqueue_album_upload_assets', 20 );

function at_gathering_body_class( $classes ) {
	if ( is_page( 'rsvp' ) ) {
		$classes[] = 'at-rsvp-page';
	}
	if ( is_page( 'rsvp-confirmation' ) ) {
		$classes[] = 'at-rsvp-confirmation-page';
	}
	return $classes;
}
add_filter( 'body_class', 'at_gathering_body_class' );

function at_gathering_get_rsvp( $user_id ) {
	global $wpdb;
	return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . at_gathering_table() . ' WHERE user_id = %d', absint( $user_id ) ) );
}

function at_gathering_rsvp_has_other_people( $guest_count, $guest_names ) {
	return (int) $guest_count > 1 || (bool) preg_match( '/\band\b/i', (string) $guest_names );
}

function at_gathering_food_counts() {
	global $wpdb;
	$counts = array();
	$rows   = $wpdb->get_results( 'SELECT foods, food_amounts FROM ' . at_gathering_table() . " WHERE status IN ('yes','maybe')" );
	foreach ( $rows as $row ) {
		$foods   = array_unique( array_map( 'sanitize_text_field', (array) json_decode( $row->foods, true ) ) );
		$amounts = at_gathering_rsvp_food_amounts( $row );
		foreach ( $foods as $food ) {
			if ( $food ) {
				$counts[ $food ] = ( $counts[ $food ] ?? 0 ) + absint( $amounts[ $food ] ?? 1 );
			}
		}
	}
	return $counts;
}

function at_gathering_food_contributors() {
	global $wpdb;

	$contributors = array();
	$rows         = $wpdb->get_results(
		"SELECT r.foods, r.food_amounts, r.guest_names, u.display_name
		FROM " . at_gathering_table() . ' AS r
		LEFT JOIN ' . $wpdb->users . " AS u ON u.ID = r.user_id
		WHERE r.status IN ('yes','maybe')"
	);

	foreach ( $rows as $row ) {
		$name  = trim( (string) $row->guest_names );
		$name  = $name ? $name : ( trim( (string) $row->display_name ) ?: 'Unknown friend' );
		$foods = array_unique( array_map( 'sanitize_text_field', (array) json_decode( $row->foods, true ) ) );
		$amounts = at_gathering_rsvp_food_amounts( $row );

		foreach ( $foods as $food ) {
			if ( $food ) {
				$contributors[ $food ][] = array(
					'name'   => $name,
					'amount' => max( 1, absint( $amounts[ $food ] ?? 1 ) ),
				);
			}
		}
	}

	foreach ( $contributors as &$food_contributors ) {
		if ( 1 === count( $food_contributors ) ) {
			$food_contributors[0] = $food_contributors[0]['name'];
			continue;
		}

		$food_contributors = array_map(
			static function ( $contributor ) {
				return $contributor['name'] . ' × ' . $contributor['amount'];
			},
			$food_contributors
		);
	}
	unset( $food_contributors );

	return $contributors;
}

function at_gathering_custom_food_counts( $listed_foods = null ) {
	global $wpdb;

	$listed_foods = null === $listed_foods ? at_gathering_foods() : (array) $listed_foods;
	$counts       = array();
	$rows         = $wpdb->get_results(
		"SELECT r.custom_food, r.foods, r.food_amounts, r.guest_names, u.display_name
		FROM " . at_gathering_table() . ' AS r
		LEFT JOIN ' . $wpdb->users . " AS u ON u.ID = r.user_id
		WHERE r.status IN ('yes','maybe')"
	);

	foreach ( $rows as $row ) {
		$custom_food = trim( sanitize_text_field( $row->custom_food ) );
		if ( ! $custom_food || in_array( $custom_food, $listed_foods, true ) ) {
			continue;
		}
		$amounts = at_gathering_rsvp_food_amounts( $row );
		if ( empty( $amounts[ $custom_food ] ) ) {
			continue;
		}
		$amount = absint( $amounts[ $custom_food ] );

		$key = strtolower( $custom_food );
		if ( ! isset( $counts[ $key ] ) ) {
			$counts[ $key ] = array(
				'label' => $custom_food,
				'count' => 0,
				'names' => array(),
			);
		}
		$counts[ $key ]['count'] += $amount;
		$name = trim( (string) $row->guest_names );
		$counts[ $key ]['names'][] = array(
			'name'   => $name ? $name : ( trim( (string) $row->display_name ) ?: 'Unknown friend' ),
			'amount' => $amount,
		);
	}

	foreach ( $counts as &$item ) {
		if ( 1 === count( $item['names'] ) ) {
			$item['names'][0] = $item['names'][0]['name'];
			continue;
		}

		$item['names'] = array_map(
			static function ( $contributor ) {
				return $contributor['name'] . ' × ' . $contributor['amount'];
			},
			$item['names']
		);
	}
	unset( $item );

	uasort(
		$counts,
		static function ( $a, $b ) {
			return strcasecmp( $a['label'], $b['label'] );
		}
	);

	return $counts;
}

function at_gathering_status_label( $status ) {
	return array(
		'yes'   => 'Coming',
		'maybe' => 'Maybe',
		'no'    => 'Can’t make it',
	)[ $status ] ?? 'Coming';
}

function at_gathering_home_intro_shortcode() {
	$user = wp_get_current_user();
	$copy = $user->exists()
		? 'RSVP, choose something for the table, and join the conversation.'
		: 'RSVP, choose something for the table, and join the conversation — or sign up without an RSVP to use the forum and shared photos.';
	return '<p class="is-style-lead">' . esc_html( $copy ) . '</p>';
}
add_shortcode( 'at_home_intro', 'at_gathering_home_intro_shortcode' );

function at_gathering_home_rsvp_action_shortcode() {
	$user = wp_get_current_user();
	$label = $user->exists() && at_gathering_get_rsvp( $user->ID ) ? 'Update RSVP' : 'RSVP';
	return '<div class="wp-block-button at-button"><a class="wp-block-button__link wp-element-button" href="' . esc_url( home_url( '/rsvp/' ) ) . '">' . esc_html( $label ) . '</a></div>';
}
add_shortcode( 'at_home_rsvp_action', 'at_gathering_home_rsvp_action_shortcode' );

function at_gathering_home_signup_action_shortcode() {
	$user = wp_get_current_user();
	if ( $user->exists() ) {
		return '';
	}

	return '<div class="wp-block-button at-button is-style-secondary"><a class="wp-block-button__link wp-element-button" href="' . esc_url( home_url( '/signup/' ) ) . '">Sign up without RSVP</a></div>';
}
add_shortcode( 'at_home_signup_action', 'at_gathering_home_signup_action_shortcode' );

function at_gathering_rsvp_shortcode() {
	$user    = wp_get_current_user();
	$rsvp    = $user->exists() ? at_gathering_get_rsvp( $user->ID ) : null;
	$foods             = at_gathering_foods();
	$counts            = at_gathering_food_counts();
	$goals             = at_gathering_food_goals();
	$food_contributors = at_gathering_food_contributors();
	$custom_food_counts = at_gathering_custom_food_counts( $foods );
	$food_amounts      = $rsvp ? at_gathering_rsvp_food_amounts( $rsvp ) : array();
	$values            = at_gathering_form_values();
	// A saved RSVP is authoritative; only restore a handoff for accounts without one.
	if ( $rsvp && ! empty( $values['_at_rsvp_handoff'] ) ) {
		$values = array();
	}
	if ( $values ) {
		$food_amounts = (array) ( $values['food_amounts'] ?? array() );
		if ( empty( $values['food_amounts'] ) && ! empty( $values['foods'] ) ) {
			$food_amounts = array_fill_keys( (array) $values['foods'], 1 );
		}
	}
	$form_status = $values['status'] ?? ( $rsvp ? $rsvp->status : 'yes' );
	$form_count  = isset( $values['guest_count'] ) ? (int) $values['guest_count'] : ( $rsvp ? (int) $rsvp->guest_count : 1 );
	$can_view_contributors = $user->exists();

	ob_start();
	?>
	<div class="at-rsvp-app">
		<?php if ( isset( $_GET['at_rsvp'] ) && 'error' === sanitize_key( $_GET['at_rsvp'] ) ) : ?>
			<div class="at-success at-error" role="alert"><strong>We could not save that RSVP.</strong><br><?php echo esc_html( sanitize_text_field( wp_unslash( $_GET['at_message'] ?? 'Please check the form and try again.' ) ) ); ?></div>
		<?php endif; ?>
		<form id="at-rsvp-form" class="at-rsvp-form" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post">
			<div class="at-rsvp-intro">
				<h2>Will you join us?</h2>
				<p>Let us know if you can make it, who’s joining you, and what you might bring.</p>
			</div>
			<input type="hidden" name="at_return_url" value="<?php echo esc_url( get_permalink() ); ?>">
			<?php wp_nonce_field( 'at_save_rsvp', 'at_rsvp_nonce' ); ?>
			<?php wp_nonce_field( 'at_rsvp_signin', 'at_rsvp_signin_nonce' ); ?>
			<input type="hidden" name="_at_rsvp_touched" value="0">
			<button class="at-default-submit" type="submit" name="action" value="at_save_rsvp" tabindex="-1" aria-hidden="true">Save RSVP</button>
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
			<p class="at-field-help at-rsvp-group-signup-hint" data-at-rsvp-group-hint hidden>If this RSVP is for more than one person, the others can still <a href="<?php echo esc_url( home_url( '/signup/' ) ); ?>">sign up</a> separately for the forum and shared photos. If they RSVP too, make sure nobody double-counts what they’re bringing.</p>
			<label>Dietary notes (optional)
				<textarea name="at_dietary" rows="3" placeholder="Any allergies or dietary needs?"><?php echo esc_textarea( $values['dietary'] ?? ( $rsvp ? $rsvp->dietary : '' ) ); ?></textarea>
			</label>
			<fieldset>
				<legend>What could you bring?</legend>
				<p class="at-field-help"><?php echo $can_view_contributors ? 'Enter how many of each dish you could bring. Current amounts and contributors are shown below.' : 'Enter how many of each dish you could bring. Sign in to see who else is bringing each item.'; ?></p>
				<div class="at-food-list">
					<?php foreach ( $foods as $food ) : ?>
						<?php $food_key = md5( $food ); $amount = min( 9999, absint( $food_amounts[ $food ] ?? 0 ) ); $goal = (int) ( $goals[ $food ] ?? 0 ); ?>
						<div class="at-food-choice">
							<div class="at-food-choice-controls">
								<div class="at-food-heading">
									<strong id="at-food-<?php echo esc_attr( $food_key ); ?>"><?php echo esc_html( $food ); ?></strong>
										<label class="at-food-bringing"><input type="checkbox" name="at_food_offers[<?php echo esc_attr( $food_key ); ?>]" value="1" aria-label="Bringing <?php echo esc_attr( $food ); ?>" <?php checked( $amount > 0 ); ?>><span>Bringing</span></label>
								</div>
								<label class="at-food-amount" for="at-food-amount-<?php echo esc_attr( $food_key ); ?>">How many?</label>
								<input id="at-food-amount-<?php echo esc_attr( $food_key ); ?>" type="number" name="at_food_amounts[<?php echo esc_attr( $food_key ); ?>]" aria-label="<?php echo esc_attr( $food ); ?>" min="0" max="9999" step="1" value="<?php echo esc_attr( $amount ); ?>">
							</div>
							<small><span>Claimed: <?php echo esc_html( (int) ( $counts[ $food ] ?? 0 ) ); ?></span><span class="at-food-summary-divider" aria-hidden="true">·</span><span>Goal: <?php echo $goal ? esc_html( $goal ) : 'not set'; ?></span><?php if ( ! empty( $food_contributors[ $food ] ) ) : ?><span class="at-food-summary-divider" aria-hidden="true">·</span><?php if ( $can_view_contributors ) : ?><span>Bringing: <?php echo esc_html( implode( ', ', $food_contributors[ $food ] ) ); ?></span><?php else : ?><span><button class="at-link-button" type="submit" name="action" value="at_rsvp_signin" formnovalidate>Sign in to see who is bringing it</button></span><?php endif; ?><?php endif; ?></small>
						</div>
					<?php endforeach; ?>
				</div>
				<?php if ( $custom_food_counts ) : ?>
					<div class="at-custom-food-list" aria-label="Other things people are bringing">
						<p class="at-field-help">Other things people are bringing</p>
						<ul>
							<?php foreach ( $custom_food_counts as $item ) : ?>
							<li><span><?php echo esc_html( $item['label'] ); ?></span><small><?php echo esc_html( (int) $item['count'] ); ?><?php if ( $can_view_contributors ) : ?> from <?php echo esc_html( implode( ', ', $item['names'] ) ); ?><?php else : ?> · <button class="at-link-button" type="submit" name="action" value="at_rsvp_signin" formnovalidate>Sign in to see who</button><?php endif; ?></small></li>
							<?php endforeach; ?>
						</ul>
					</div>
				<?php endif; ?>
				<div class="at-custom-food-fields">
					<label>Something else?
						<input type="text" name="at_custom_food" value="<?php echo esc_attr( $values['custom_food'] ?? ( $rsvp ? $rsvp->custom_food : '' ) ); ?>" placeholder="Add a dish or drink">
					</label>
					<label>Amount
						<input type="number" name="at_custom_food_amount" aria-label="Amount of something else" min="0" max="9999" step="1" value="<?php echo esc_attr( min( 9999, absint( $values['custom_food_amount'] ?? ( $food_amounts[ $rsvp->custom_food ?? '' ] ?? 0 ) ) ) ); ?>">
					</label>
				</div>
			</fieldset>
			<label>Anything else for the hosts? (optional)
				<textarea name="at_notes" rows="3" placeholder="Add a note for the hosts"><?php echo esc_textarea( $values['notes'] ?? ( $rsvp ? $rsvp->notes : '' ) ); ?></textarea>
			</label>
			<?php if ( ! $user->exists() ) : ?>
				<p class="at-form-login-note at-form-login-note-top">Already have an account? <button class="at-link-button" type="submit" name="action" value="at_rsvp_signin" formnovalidate>Sign in first</button>. We’ll keep what you’ve entered here while you sign in.</p>
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
			<p class="at-form-actions at-rsvp-submit-actions"><button class="at-button" type="submit" name="action" value="at_save_rsvp"><?php echo $rsvp ? 'Update my RSVP' : 'Save my RSVP'; ?></button></p>
		</form>
	</div>
	<?php
	return ob_get_clean();
}
add_shortcode( 'at_rsvp', 'at_gathering_rsvp_shortcode' );

function at_gathering_rsvp_confirmation_shortcode() {
	$user = wp_get_current_user();
	$saved = isset( $_GET['at_rsvp'] ) && 'saved' === sanitize_key( $_GET['at_rsvp'] );
	$mail_failed = 'failed' === ( $_GET['at_mail'] ?? '' );

	ob_start();
	?>
	<div class="at-confirmation-app">
		<?php if ( $saved ) : ?>
			<div class="at-success at-confirmation-box" role="status">
				<strong>Thanks — you’re on the list.</strong>
				<?php if ( $mail_failed ) : ?>
					<p>Your RSVP was saved, but the confirmation email could not be sent. Please tell the hosts.</p>
				<?php else : ?>
					<p>We sent a copy of your RSVP to <?php echo esc_html( $user->user_email ); ?>.</p>
				<?php endif; ?>
			</div>
			<div class="at-confirmation-next-steps">
				<p>You can update your RSVP any time, or visit the gathering spaces to say hello and share photos.</p>
				<p class="at-actions">
					<a class="at-button" href="<?php echo esc_url( home_url( '/rsvp/' ) ); ?>">Review my RSVP</a>
					<a class="at-button is-secondary" href="<?php echo esc_url( home_url( '/forum/' ) ); ?>">Visit the forum</a>
				</p>
			</div>
		<?php else : ?>
			<div class="at-access-note">
				<p>This page confirms an RSVP after it has been saved.</p>
				<p><a href="<?php echo esc_url( home_url( '/rsvp/' ) ); ?>">Go to the RSVP form</a>.</p>
			</div>
		<?php endif; ?>
	</div>
	<?php
	return ob_get_clean();
}
add_shortcode( 'at_rsvp_confirmation', 'at_gathering_rsvp_confirmation_shortcode' );

function at_gathering_signup_form_values() {
	$token = sanitize_key( wp_unslash( $_GET['at_signup_form'] ?? '' ) );
	if ( ! $token ) {
		return array();
	}
	$values = get_transient( 'at_gathering_signup_' . $token );
	delete_transient( 'at_gathering_signup_' . $token );
	return is_array( $values ) ? $values : array();
}

function at_gathering_signup_redirect_error( $return, $message, $values = array() ) {
	$args = array( 'at_signup' => 'error', 'at_message' => $message );
	if ( $values ) {
		$token = strtolower( wp_generate_password( 20, false, false ) );
		set_transient( 'at_gathering_signup_' . $token, $values, 10 * MINUTE_IN_SECONDS );
		$args['at_signup_form'] = $token;
	}
	wp_safe_redirect( add_query_arg( $args, $return ) );
	exit;
}

function at_gathering_signup_shortcode() {
	$user   = wp_get_current_user();
	$values = at_gathering_signup_form_values();
	$saved  = isset( $_GET['at_signup'] ) && 'saved' === sanitize_key( $_GET['at_signup'] );

	ob_start();
	?>
	<div class="at-signup-app">
		<?php if ( $saved ) : ?>
			<div class="at-success" role="status"><strong>You’re signed up.</strong><br>You can now visit the <a href="<?php echo esc_url( home_url( '/forum/' ) ); ?>">gathering forum</a> and <a href="<?php echo esc_url( home_url( '/albums/' ) ); ?>">shared photos</a>. If you’re coming to dinner, you can <a href="<?php echo esc_url( home_url( '/rsvp/' ) ); ?>">RSVP separately</a>.</div>
		<?php elseif ( $user->exists() ) : ?>
			<div class="at-success" role="status"><strong>You’re already signed up.</strong><br>Visit the <a href="<?php echo esc_url( home_url( '/forum/' ) ); ?>">gathering forum</a> or <a href="<?php echo esc_url( home_url( '/albums/' ) ); ?>">shared photos</a>, or <a href="<?php echo esc_url( home_url( '/rsvp/' ) ); ?>">add an RSVP</a> if you’re coming.</div>
		<?php elseif ( isset( $_GET['at_signup'] ) && 'error' === sanitize_key( $_GET['at_signup'] ) ) : ?>
			<div class="at-success at-error" role="alert"><strong>We could not create that account.</strong><br><?php echo esc_html( sanitize_text_field( wp_unslash( $_GET['at_message'] ?? 'Please check the form and try again.' ) ) ); ?></div>
		<?php endif; ?>
		<?php if ( ! $user->exists() && ! $saved ) : ?>
			<form class="at-signup-form at-rsvp-form" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post">
				<div class="at-rsvp-intro">
					<h2>Create your account</h2>
					<p>Sign up to use the gathering forum and shared photos before, during, or after Thanksgiving.</p>
				</div>
				<input type="hidden" name="action" value="at_signup">
				<input type="hidden" name="at_return_url" value="<?php echo esc_url( get_permalink() ); ?>">
				<?php wp_nonce_field( 'at_signup', 'at_signup_nonce' ); ?>
				<p class="at-form-login-note">Already have an account? <a href="<?php echo esc_url( wp_login_url( get_permalink() ) ); ?>">Sign in</a>.</p>
				<fieldset class="at-account-fields">
					<legend>Your details</legend>
					<div class="at-rsvp-grid">
						<label>Display name<input type="text" name="at_display_name" value="<?php echo esc_attr( $values['display_name'] ?? '' ); ?>" autocomplete="name" required></label>
						<label>Email<input type="email" name="at_email" value="<?php echo esc_attr( $values['email'] ?? '' ); ?>" autocomplete="email" required></label>
						<label>Password<input type="password" name="at_password" autocomplete="new-password" minlength="10" required></label>
						<label>Confirm password<input type="password" name="at_password_confirm" autocomplete="new-password" minlength="10" required></label>
					</div>
				</fieldset>
				<p class="at-form-actions"><button class="at-button" type="submit">Sign up</button></p>
			</form>
		<?php endif; ?>
	</div>
	<?php
	return ob_get_clean();
}
add_shortcode( 'at_signup', 'at_gathering_signup_shortcode' );

function at_gathering_signup() {
	if ( ! isset( $_POST['at_signup_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['at_signup_nonce'] ) ), 'at_signup' ) ) {
		wp_die( 'Sorry, we could not create that account.' );
	}
	$return = esc_url_raw( wp_unslash( $_POST['at_return_url'] ?? home_url( '/signup/' ) ) );
	if ( is_user_logged_in() ) {
		wp_safe_redirect( $return );
		exit;
	}

	$name             = sanitize_text_field( wp_unslash( $_POST['at_display_name'] ?? '' ) );
	$email            = sanitize_email( wp_unslash( $_POST['at_email'] ?? '' ) );
	$password         = (string) wp_unslash( $_POST['at_password'] ?? '' );
	$password_confirm = (string) wp_unslash( $_POST['at_password_confirm'] ?? '' );
	$form_values      = array( 'display_name' => $name, 'email' => $email );
	if ( ! $name || ! is_email( $email ) || strlen( $password ) < 10 || $password !== $password_confirm ) {
		at_gathering_signup_redirect_error( $return, 'Please complete your account details. Passwords need at least 10 characters and must match.', $form_values );
	}
	if ( email_exists( $email ) ) {
		at_gathering_signup_redirect_error( $return, 'There is already an account for that email. Please sign in instead.', $form_values );
	}

	$username = at_gathering_unique_login( $email );
	$user_id  = wp_insert_user( array( 'user_login' => $username, 'user_pass' => $password, 'user_email' => $email, 'display_name' => $name, 'role' => 'subscriber' ) );
	if ( is_wp_error( $user_id ) ) {
		at_gathering_signup_redirect_error( $return, $user_id->get_error_message(), $form_values );
	}
	if ( function_exists( 'bbp_set_user_role' ) && function_exists( 'bbp_get_participant_role' ) ) {
		bbp_set_user_role( $user_id, bbp_get_participant_role() );
	}
	wp_set_current_user( $user_id );
	wp_set_auth_cookie( $user_id, true );
	wp_safe_redirect( add_query_arg( 'at_signup', 'saved', $return ) );
	exit;
}
add_action( 'admin_post_at_signup', 'at_gathering_signup' );
add_action( 'admin_post_nopriv_at_signup', 'at_gathering_signup' );

function at_gathering_confirmation_message( $user, $status, $guest_count, $guest_names, $foods, $dietary, $notes, $greeting_name = '', $food_amounts = array() ) {
	$details = at_gathering_event_details();
	$event_lines = array_filter(
		array(
			implode( ' · ', array_filter( array( $details['date'], $details['time'] ) ) ),
			$details['address'],
		),
		'strlen'
	);
	$food_text = $foods ? at_gathering_food_amounts_text( $foods, (array) $food_amounts ) : 'Nothing chosen yet';
	$greeting_name = $greeting_name ?: ( $user->display_name ?: $user->user_login );
	$group_signup = at_gathering_rsvp_has_other_people( $guest_count, $guest_names )
		? "\n\nPeople joining you can sign up separately for the forum and shared photos here:\n" . home_url( '/signup/' )
		: '';

	return sprintf(
		"Hi %s,\n\nThanks for letting us know about Thanksgiving.\n\nAttendance: %s\nPeople: %d\nNames: %s\nFood: %s\nDietary notes: %s\nNote for the hosts: %s\n\n%s\n\nYou can update your RSVP any time from the site. Your account also gives you access to the forum and shared photos.%s\n\nSee you there!",
		$greeting_name,
		at_gathering_status_label( $status ),
		$guest_count,
		$guest_names ?: '—',
		$food_text,
		$dietary ?: 'None noted',
		$notes ?: 'None noted',
		implode( "\n", $event_lines ),
		$group_signup
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
	$submitted_amounts = (array) wp_unslash( $_POST['at_food_amounts'] ?? array() );
	$submitted_offers  = (array) wp_unslash( $_POST['at_food_offers'] ?? array() );
	$food_amounts      = array();
	$foods              = array();
	foreach ( at_gathering_foods() as $food ) {
		$amount = min( 9999, absint( $submitted_amounts[ md5( $food ) ] ?? 0 ) );
		if ( ! empty( $submitted_offers[ md5( $food ) ] ) ) {
			$amount = max( 1, $amount );
		}
		if ( $amount > 0 ) {
			$foods[] = $food;
			$food_amounts[ $food ] = $amount;
		}
	}
	$custom_food = sanitize_text_field( wp_unslash( $_POST['at_custom_food'] ?? '' ) );
	$custom_food_amount = min( 9999, absint( $_POST['at_custom_food_amount'] ?? 0 ) );
	$dietary     = sanitize_textarea_field( wp_unslash( $_POST['at_dietary'] ?? '' ) );
	$notes       = sanitize_textarea_field( wp_unslash( $_POST['at_notes'] ?? '' ) );
	if ( $custom_food && $custom_food_amount > 0 && 'no' !== $status ) {
		$foods[] = $custom_food;
		$food_amounts[ $custom_food ] = $custom_food_amount;
	}
	$form_values = compact( 'status', 'guest_count', 'guest_names', 'dietary', 'foods', 'food_amounts', 'custom_food', 'custom_food_amount', 'notes' );
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
		'food_amounts' => wp_json_encode( $food_amounts ),
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
	if ( function_exists( 'wp_cache_clear_cache' ) ) {
		wp_cache_clear_cache();
	}

	$message = at_gathering_confirmation_message( $user, $status, $guest_count, $guest_names, $foods, $data['dietary'], $data['notes'], '', $food_amounts );
	$mail_ok = wp_mail( $user->user_email, 'Your Armstrong Thanksgiving RSVP', $message );
	wp_safe_redirect( add_query_arg( array( 'at_rsvp' => 'saved', 'at_mail' => $mail_ok ? 'sent' : 'failed' ), home_url( '/rsvp-confirmation/' ) ) );
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
	$goals  = at_gathering_food_goals();
	$admins = get_users( array( 'role' => 'administrator', 'orderby' => 'display_name', 'order' => 'ASC' ) );
	$event  = at_gathering_event_details();
	?>
	<div class="wrap at-gathering-admin">
		<h1>Gathering RSVPs</h1>
		<p>Host view: attendance, notes, and what is coming to the table.</p>
		<?php if ( 'removed' === ( $_GET['at_rsvp'] ?? '' ) ) : ?><div class="notice notice-success is-dismissible"><p>RSVP removed.</p></div><?php elseif ( 'not_found' === ( $_GET['at_rsvp'] ?? '' ) ) : ?><div class="notice notice-warning is-dismissible"><p>That RSVP was already removed.</p></div><?php endif; ?>
		<h2>Event details</h2>
		<p>These details appear on the homepage for signed-in members and in RSVP confirmation emails.</p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="at-event-details-form">
			<input type="hidden" name="action" value="at_save_event_details">
			<?php wp_nonce_field( 'at_save_event_details', 'at_event_details_nonce' ); ?>
			<table class="form-table" role="presentation"><tbody>
				<tr><th scope="row"><label for="at-event-date">Date</label></th><td><input class="regular-text" id="at-event-date" name="at_event_date" type="text" value="<?php echo esc_attr( $event['date'] ); ?>" placeholder="Enter the event date"></td></tr>
				<tr><th scope="row"><label for="at-event-time">Time</label></th><td><input class="regular-text" id="at-event-time" name="at_event_time" type="text" value="<?php echo esc_attr( $event['time'] ); ?>" placeholder="e.g. 4:00 pm"></td></tr>
				<tr><th scope="row"><label for="at-event-address">Address</label></th><td><input class="large-text" id="at-event-address" name="at_event_address" type="text" value="<?php echo esc_attr( $event['address'] ); ?>"></td></tr>
			</tbody></table>
			<?php submit_button( 'Save event details' ); ?>
		</form>
		<?php if ( 'saved' === ( $_GET['at_event_details'] ?? '' ) ) : ?><div class="notice notice-success is-dismissible"><p>Event details saved.</p></div><?php endif; ?>
		<?php if ( 'sent' === ( $_GET['at_sample_mail'] ?? '' ) ) : ?><div class="notice notice-success is-dismissible"><p>Sample RSVP confirmation sent through WordPress mail.</p></div><?php elseif ( 'failed' === ( $_GET['at_sample_mail'] ?? '' ) ) : ?><div class="notice notice-error is-dismissible"><p>WordPress could not send the sample RSVP confirmation.</p></div><?php endif; ?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="at-food-goals">
			<input type="hidden" name="action" value="at_save_food_goals">
			<table class="widefat striped at-admin-foods">
				<thead><tr><th scope="col">Food</th><th scope="col">Amount offered</th><th scope="col">Amount goal</th></tr></thead>
				<tbody>
					<?php foreach ( $foods as $food ) : $food_key = md5( $food ); ?><tr><td><?php echo esc_html( $food ); ?></td><td><?php echo esc_html( (int) ( $counts[ $food ] ?? 0 ) ); ?></td><td><label class="screen-reader-text" for="at-food-goal-<?php echo esc_attr( $food_key ); ?>">Goal for <?php echo esc_html( $food ); ?></label><input id="at-food-goal-<?php echo esc_attr( $food_key ); ?>" type="number" min="0" max="9999" step="1" name="at_food_goals[<?php echo esc_attr( $food_key ); ?>]" value="<?php echo esc_attr( $goals[ $food ] ); ?>"></td></tr><?php endforeach; ?>
				</tbody>
			</table>
			<p><button class="button button-primary" type="submit">Save food goals</button> <span class="description">Leave a goal at 0 to show the current amount without a target.</span></p>
			<?php wp_nonce_field( 'at_save_food_goals', 'at_food_goals_nonce' ); ?>
		</form>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="at-add-food">
			<input type="hidden" name="action" value="at_add_food"><input type="text" name="at_food" placeholder="Add another food"><button class="button button-primary">Add food</button><?php wp_nonce_field( 'at_add_food', 'at_food_nonce' ); ?>
		</form>
		<table class="widefat striped"><thead><tr><th>Friend</th><th>Status</th><th>People</th><th>Food</th><th>Dietary</th><th>Note for hosts</th><th>Updated</th><th>Actions</th></tr></thead><tbody>
		<?php if ( ! $rows ) : ?><tr><td colspan="8">No RSVPs yet.</td></tr><?php endif; ?>
		<?php foreach ( $rows as $row ) : $user = get_user_by( 'id', $row->user_id ); $row_foods = (array) json_decode( $row->foods, true ); $row_food_amounts = at_gathering_rsvp_food_amounts( $row ); ?>
			<tr><td><strong><?php echo esc_html( $user ? $user->display_name : 'Unknown friend' ); ?></strong><br><small><?php echo esc_html( $user ? $user->user_email : '' ); ?></small></td><td><?php echo esc_html( at_gathering_status_label( $row->status ) ); ?></td><td><?php echo esc_html( $row->guest_count ); ?><?php echo $row->guest_names ? '<br><small>' . esc_html( $row->guest_names ) . '</small>' : ''; ?></td><td><?php echo esc_html( at_gathering_food_amounts_text( $row_foods, $row_food_amounts ) ?: '—' ); ?></td><td><?php echo esc_html( $row->dietary ?: '—' ); ?></td><td><?php echo esc_html( $row->notes ?: '—' ); ?></td><td><?php echo esc_html( mysql2date( 'j M, H:i', $row->updated_at ) ); ?></td><td><form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="at-remove-rsvp-form"><input type="hidden" name="action" value="at_remove_rsvp"><input type="hidden" name="at_rsvp_user_id" value="<?php echo esc_attr( $row->user_id ); ?>"><?php wp_nonce_field( 'at_remove_rsvp_' . absint( $row->user_id ), 'at_remove_rsvp_nonce' ); ?><button class="button button-secondary" type="submit" onclick="return confirm('Remove this RSVP? The member account will remain.');">Remove RSVP</button></form></td></tr>
		<?php endforeach; ?></tbody></table>
		<?php if ( $admins ) : ?>
			<hr>
			<h2>Send a sample confirmation</h2>
			<p>This sends the normal RSVP confirmation through WordPress mail without saving an RSVP.</p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="at-sample-rsvp-form">
				<input type="hidden" name="action" value="at_send_sample_rsvp_email">
				<div class="at-sample-recipient">
					<label for="at-sample-user">Recipient</label>
					<select id="at-sample-user" name="at_sample_user_id">
						<?php foreach ( $admins as $admin ) : ?><option value="<?php echo esc_attr( $admin->ID ); ?>" <?php selected( $admin->ID, 1 ); ?>><?php echo esc_html( $admin->display_name . ' · ' . $admin->user_email ); ?></option><?php endforeach; ?>
					</select>
				</div>
				<button class="button button-primary" type="submit">Send sample RSVP confirmation</button>
				<?php wp_nonce_field( 'at_send_sample_rsvp_email', 'at_sample_email_nonce' ); ?>
			</form>
		<?php endif; ?>
	</div>
	<?php
}

function at_gathering_remove_rsvp() {
	$user_id = absint( $_POST['at_rsvp_user_id'] ?? 0 );
	$nonce   = sanitize_text_field( wp_unslash( $_POST['at_remove_rsvp_nonce'] ?? '' ) );
	if ( ! current_user_can( 'manage_options' ) || ! $user_id || ! wp_verify_nonce( $nonce, 'at_remove_rsvp_' . $user_id ) ) {
		wp_die( 'Sorry, that RSVP could not be removed.' );
	}

	global $wpdb;
	$deleted = $wpdb->delete( at_gathering_table(), array( 'user_id' => $user_id ), array( '%d' ) );
	if ( false === $deleted ) {
		wp_die( 'Sorry, that RSVP could not be removed.' );
	}
	if ( $deleted && function_exists( 'wp_cache_clear_cache' ) ) {
		wp_cache_clear_cache();
	}

	$result = $deleted ? 'removed' : 'not_found';
	wp_safe_redirect( admin_url( 'admin.php?page=at-gathering&at_rsvp=' . $result ) );
	exit;
}
add_action( 'admin_post_at_remove_rsvp', 'at_gathering_remove_rsvp' );

function at_gathering_save_food_goals() {
	if ( ! current_user_can( 'manage_options' ) || ! isset( $_POST['at_food_goals_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['at_food_goals_nonce'] ) ), 'at_save_food_goals' ) ) {
		wp_die( 'Sorry, the food goals could not be saved.' );
	}
	$submitted = (array) wp_unslash( $_POST['at_food_goals'] ?? array() );
	$goals = array();
	foreach ( at_gathering_foods() as $food ) {
		$key = md5( $food );
		$goals[ $food ] = isset( $submitted[ $key ] ) ? min( 9999, absint( $submitted[ $key ] ) ) : 0;
	}
	update_option( 'at_gathering_food_goals', $goals );
	if ( function_exists( 'wp_cache_clear_cache' ) ) {
		wp_cache_clear_cache();
	}
	wp_safe_redirect( admin_url( 'admin.php?page=at-gathering' ) );
	exit;
}
add_action( 'admin_post_at_save_food_goals', 'at_gathering_save_food_goals' );

function at_gathering_send_sample_rsvp_email() {
	if ( ! current_user_can( 'manage_options' ) || ! isset( $_POST['at_sample_email_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['at_sample_email_nonce'] ) ), 'at_send_sample_rsvp_email' ) ) {
		wp_die( 'Sorry, the sample confirmation could not be sent.' );
	}
	$recipient = get_user_by( 'id', absint( $_POST['at_sample_user_id'] ?? 0 ) );
	if ( ! $recipient || ! in_array( 'administrator', (array) $recipient->roles, true ) ) {
		wp_die( 'Choose an administrator as the sample recipient.' );
	}
	$message = at_gathering_confirmation_message( $recipient, 'yes', 2, 'Fergal and a guest', array( 'Stuffing — non-vegetarian', 'Gravy — vegetarian' ), 'None noted', 'Looking forward to it.', 'Fergal' );
	$sent = wp_mail( $recipient->user_email, 'Your Armstrong Thanksgiving RSVP', $message );
	$url  = add_query_arg( array( 'page' => 'at-gathering', 'at_sample_mail' => $sent ? 'sent' : 'failed' ), admin_url( 'admin.php' ) );
	wp_safe_redirect( $url );
	exit;
}
add_action( 'admin_post_at_send_sample_rsvp_email', 'at_gathering_send_sample_rsvp_email' );

function at_gathering_save_event_details() {
	if ( ! current_user_can( 'manage_options' ) || ! isset( $_POST['at_event_details_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['at_event_details_nonce'] ) ), 'at_save_event_details' ) ) {
		wp_die( 'Sorry, the event details could not be saved.' );
	}
	update_option(
		'at_gathering_event_details',
		array(
			'date'    => sanitize_text_field( wp_unslash( $_POST['at_event_date'] ?? '' ) ),
			'time'    => sanitize_text_field( wp_unslash( $_POST['at_event_time'] ?? '' ) ),
			'address' => sanitize_text_field( wp_unslash( $_POST['at_event_address'] ?? '' ) ),
		)
	);
	if ( function_exists( 'wp_cache_clear_cache' ) ) {
		wp_cache_clear_cache();
	}
	wp_safe_redirect( admin_url( 'admin.php?page=at-gathering&at_event_details=saved' ) );
	exit;
}
add_action( 'admin_post_at_save_event_details', 'at_gathering_save_event_details' );

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
