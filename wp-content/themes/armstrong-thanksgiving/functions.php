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

function armstrong_thanksgiving_theme_login_branding() {
	?>
	<style>
		:root { --at-cream:#f6ede1; --at-coral:#b34b38; --at-brown:#4c2518; --at-olive:#777844; }
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
add_action( 'login_head', 'armstrong_thanksgiving_theme_login_branding' );

function armstrong_thanksgiving_theme_login_message() {
	return '<p class="message at-login-message"><strong>Friends’ access</strong><br>Use the email address you were invited with, then choose “Send me the login link.” If you need an invitation, ask the host to add you.</p>';
}
add_filter( 'login_message', 'armstrong_thanksgiving_theme_login_message' );

function armstrong_thanksgiving_theme_login_url() {
	return home_url( '/' );
}
add_filter( 'login_headerurl', 'armstrong_thanksgiving_theme_login_url' );

function armstrong_thanksgiving_theme_login_text() {
	return 'armstrongthanksgiving.com';
}
add_filter( 'login_headertext', 'armstrong_thanksgiving_theme_login_text' );

/**
 * Give site emails the same warm, flyer-inspired treatment as the front end.
 *
 * The wrapper uses inline styles so it survives mail clients that discard
 * external stylesheets. Existing HTML from plugins is preserved; plain-text
 * messages are safely escaped and turned into readable paragraphs.
 *
 * @param array $args Arguments passed to wp_mail().
 * @return array
 */
function armstrong_thanksgiving_theme_email( $args ) {
	if ( empty( $args['message'] ) || ! is_string( $args['message'] ) ) {
		return $args;
	}

	$message = trim( $args['message'] );
	if ( false !== stripos( $message, 'data-at-email-theme="armstrong-thanksgiving"' ) ) {
		return $args;
	}

	$headers = $args['headers'] ?? array();
	$header_text = is_array( $headers ) ? implode( "\n", $headers ) : (string) $headers;
	// This wrapper emits one HTML document. Leave calendar, application, and
	// multipart messages to their original handlers instead of corrupting them.
	if ( preg_match( '/content-type\s*:\s*(text\/calendar|application\/|multipart\/)/i', $header_text ) ) {
		return $args;
	}

	$is_html = $message !== wp_strip_all_tags( $message );
	if ( ! $is_html ) {
		$message = wpautop( make_clickable( esc_html( $message ) ) );
	}

	$site_name = esc_html( get_bloginfo( 'name' ) ?: 'Armstrong Thanksgiving' );
	$home_url  = esc_url( home_url( '/' ) );
	$body      = '<!doctype html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head><body style="margin:0;background:#f6ede1;color:#3d2a22;font-family:Arial,Helvetica,sans-serif;line-height:1.6;" data-at-email-theme="armstrong-thanksgiving">'
		. '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="width:100%;background:#f6ede1;margin:0;padding:0;"><tr><td align="center" style="padding:32px 16px;">'
		. '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="width:100%;max-width:640px;"><tr><td style="padding:0 0 18px;text-align:center;">'
		. '<a href="' . $home_url . '" style="color:#4c2518;font-size:20px;font-weight:700;letter-spacing:.08em;text-decoration:none;">' . $site_name . '</a>'
		. '</td></tr><tr><td style="background:#fffaf4;border:1px solid rgba(76,37,24,.14);border-radius:20px;padding:28px 24px;box-shadow:0 12px 30px rgba(76,37,24,.10);">'
		. $message
		. '</td></tr><tr><td style="padding:18px 12px 0;color:#777844;font-size:13px;text-align:center;">'
		. 'Friends-only gathering · <a href="' . $home_url . '" style="color:#4c2518;">Visit the site</a>'
		. '</td></tr></table></td></tr></table></body></html>';

	$args['message'] = $body;
	if ( preg_match( '/^content-type\s*:\s*text\/plain\b[^\r\n]*/im', $header_text ) ) {
		// Some plugins explicitly mark an otherwise ordinary message as plain
		// text. Replace that header because the themed wrapper is HTML now.
		if ( is_array( $headers ) ) {
			$headers = array_map(
				static function ( $header ) {
					return preg_match( '/^content-type\s*:\s*text\/plain\b/i', (string) $header )
						? 'Content-Type: text/html; charset=UTF-8'
						: $header;
				},
				$headers
			);
		} else {
			$headers = preg_replace( '/^content-type\s*:\s*text\/plain[^\r\n]*/im', 'Content-Type: text/html; charset=UTF-8', $header_text );
		}
		$args['headers'] = $headers;
	} elseif ( ! preg_match( '/^content-type\s*:/im', $header_text ) ) {
		if ( is_array( $headers ) ) {
			$headers[] = 'Content-Type: text/html; charset=UTF-8';
		} else {
			$headers = trim( $header_text . "\r\nContent-Type: text/html; charset=UTF-8" );
		}
		$args['headers'] = $headers;
	}

	return $args;
}
add_filter( 'wp_mail', 'armstrong_thanksgiving_theme_email', 20 );
