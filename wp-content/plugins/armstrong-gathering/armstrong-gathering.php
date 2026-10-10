<?php
/**
 * Plugin Name: Armstrong Gathering
 * Description: The small, first-party RSVP and potluck layer for Armstrong Thanksgiving.
 * Version: 0.6.0
 * Requires at least: 6.8
 * Requires PHP: 8.1
 * Author: Armstrong Thanksgiving
 * Text Domain: armstrong-gathering
 */

defined( 'ABSPATH' ) || exit;

define( 'AT_GATHERING_VERSION', '0.6.0' );
define( 'AT_GATHERING_FILE', __FILE__ );
define( 'AT_GATHERING_DIR', plugin_dir_path( __FILE__ ) );
define( 'AT_GATHERING_URL', plugin_dir_url( __FILE__ ) );


// Shared schema ownership stays in schema.php; feature modules register their own hooks.
require_once AT_GATHERING_DIR . 'includes/schema.php';
require_once AT_GATHERING_DIR . 'includes/shared.php';
require_once AT_GATHERING_DIR . 'includes/invitations.php';
require_once AT_GATHERING_DIR . 'includes/party-rsvps.php';

add_action(
	'init',
	static function () {
		do_action( 'at_gathering_register_invitations' );
		do_action( 'at_gathering_register_party_rsvps' );
	},
	1
);
