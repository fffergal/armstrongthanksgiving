<?php
/**
 * Armstrong Thanksgiving site behavior.
 *
 * Kept as a must-use plugin so performance/privacy behavior cannot be
 * accidentally disabled while the visual theme is being iterated.
 */

defined( 'ABSPATH' ) || exit;

function armstrong_thanksgiving_login_style_tag( $html, $handle ) {
	global $pagenow;
	return 'wp-login.php' === $pagenow && 'dashicons' === $handle ? '' : $html;
}
add_filter( 'style_loader_tag', 'armstrong_thanksgiving_login_style_tag', 10, 2 );

function armstrong_thanksgiving_login_script_tag( $tag, $handle ) {
	global $pagenow;
	return 'wp-login.php' === $pagenow ? '' : $tag;
}
add_filter( 'script_loader_tag', 'armstrong_thanksgiving_login_script_tag', 10, 2 );

function armstrong_thanksgiving_login_assets() {
	global $wp_scripts, $wp_styles;
	if ( is_object( $wp_scripts ) && isset( $wp_scripts->queue ) ) {
		foreach ( $wp_scripts->queue as $handle ) {
			// The login form needs no enqueued front-end scripts on this page.
			wp_dequeue_script( $handle );
			wp_deregister_script( $handle );
		}
	}
	if ( is_object( $wp_styles ) && isset( $wp_styles->queue ) ) {
		foreach ( $wp_styles->queue as $handle ) {
			$style = $wp_styles->registered[ $handle ] ?? null;
			$source = is_object( $style ) ? (string) $style->src : '';
			if ( 'dashicons' === $handle || false !== stripos( $handle, 'wppa' ) || false !== stripos( $source, 'wp-photo-album-plus' ) ) {
				wp_dequeue_style( $handle );
				wp_deregister_style( $handle );
			}
		}
	}
}
add_action( 'login_enqueue_scripts', 'armstrong_thanksgiving_login_assets', 9999 );
// WP Photo Album Plus registers its global scripts during init; this second
// pass runs immediately before core prints login scripts and styles.
add_action( 'login_head', 'armstrong_thanksgiving_login_assets', 8 );

function armstrong_thanksgiving_login_branding() {
	?>
	<style>
		:root { --at-cream:#f6ede1; --at-orange:#f36f32; --at-coral:#e86149; --at-brown:#4c2518; }
		body.login { background:var(--at-cream); }
		.login h1 a { width:100%; height:auto; margin-bottom:1.5rem; background:none; color:var(--at-brown); font:700 2rem/1 ui-rounded,"Trebuchet MS",system-ui,sans-serif; text-indent:0; text-decoration:none; }
		.login form { border:0; border-radius:1.25rem; box-shadow:0 18px 45px rgba(76,37,24,.12); background:#fffaf4; }
		.login #wp-submit { border:0; border-radius:999px; background:var(--at-coral); text-shadow:none; box-shadow:none; }
		.login #wp-submit:hover, .login #wp-submit:focus { background:var(--at-brown); }
		.login a { color:var(--at-brown); }
	</style>
	<?php
}
add_action( 'login_head', 'armstrong_thanksgiving_login_branding' );

function armstrong_thanksgiving_login_url() {
	return home_url( '/' );
}
add_filter( 'login_headerurl', 'armstrong_thanksgiving_login_url' );

function armstrong_thanksgiving_login_text() {
	return 'armstrongthanksgiving.com';
}
add_filter( 'login_headertext', 'armstrong_thanksgiving_login_text' );
