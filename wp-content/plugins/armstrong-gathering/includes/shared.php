<?php
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

function at_gathering_home_intro_shortcode( $atts ) {
	$atts = shortcode_atts(
		array(
			'guest'  => '',
			'member' => '',
		),
		$atts,
		'at_home_intro'
	);
	$user = wp_get_current_user();
	$copy = $user->exists() ? $atts['member'] : $atts['guest'];
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
