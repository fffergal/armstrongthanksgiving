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

function armstrong_thanksgiving_login_branding() {
	?>
	<style>
		:root { --at-cream:#f6ede1; --at-coral:#e86149; --at-brown:#4c2518; --at-olive:#777844; }
		body.login { background:var(--at-cream); }
		body.login #login { width:min(92%, 420px); padding-top:8vh; }
		.login h1 a { width:auto; max-width:100%; height:auto; margin:0 0 1.5rem; background-image:none !important; color:var(--at-brown); font:700 clamp(1.35rem,7vw,2rem)/1 ui-rounded,"Trebuchet MS",system-ui,sans-serif; text-indent:0; text-decoration:none; white-space:normal; overflow-wrap:anywhere; }
		.login form { border:0; border-radius:1.25rem; box-shadow:0 18px 45px rgba(76,37,24,.12); background:#fffaf4; }
		.login #wp-submit, .login #wp-login-submit, .login #magic-login-button { width:100%; border:0; border-radius:999px; background:var(--at-coral) !important; border-color:var(--at-coral) !important; color:#fff; text-shadow:none; box-shadow:none; }
		.login #wp-submit:hover, .login #wp-submit:focus, .login #wp-login-submit:hover, .login #wp-login-submit:focus, .login #magic-login-button:hover, .login #magic-login-button:focus { background:var(--at-brown) !important; }
		.login a { color:var(--at-brown); }
		.login .message { border-left-color:var(--at-olive); background:#fff8ef; color:var(--at-brown); }
	</style>
	<?php
}
add_action( 'login_head', 'armstrong_thanksgiving_login_branding' );

function armstrong_thanksgiving_login_message() {
	return '<p class="message at-login-message"><strong>Friends’ access</strong><br>Use the email address you were invited with, then choose “Send me the login link.” If you need an invitation, ask the host to add you.</p>';
}
add_filter( 'login_message', 'armstrong_thanksgiving_login_message' );

function armstrong_thanksgiving_login_url() {
	return home_url( '/' );
}
add_filter( 'login_headerurl', 'armstrong_thanksgiving_login_url' );

function armstrong_thanksgiving_login_text() {
	return 'armstrongthanksgiving.com';
}
add_filter( 'login_headertext', 'armstrong_thanksgiving_login_text' );
