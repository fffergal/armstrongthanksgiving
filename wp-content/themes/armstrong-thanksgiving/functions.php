<?php
/**
 * Front-end assets for the Armstrong Thanksgiving block theme.
 */

defined( 'ABSPATH' ) || exit;

function armstrong_thanksgiving_enqueue_styles() {
	$theme = wp_get_theme();
	wp_enqueue_style(
		'armstrong-thanksgiving',
		get_stylesheet_uri(),
		array(),
		$theme->get( 'Version' )
	);
}
add_action( 'wp_enqueue_scripts', 'armstrong_thanksgiving_enqueue_styles' );
