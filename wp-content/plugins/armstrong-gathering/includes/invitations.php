<?php
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

function at_gathering_rotate_invite() {
	if ( ! current_user_can( 'manage_options' ) || ! isset( $_POST['at_invite_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['at_invite_nonce'] ) ), 'at_rotate_invite' ) ) {
		wp_die( 'Sorry, the invite link could not be replaced.' );
	}
	update_option( 'at_gathering_invite_key', wp_generate_password( 32, false, false ) );
	wp_safe_redirect( admin_url( 'admin.php?page=at-gathering' ) );
	exit;
}

function at_gathering_register_invitations() {
	add_shortcode( 'at_signup', 'at_gathering_signup_shortcode' );
	add_action( 'admin_post_at_signup', 'at_gathering_signup' );
	add_action( 'admin_post_nopriv_at_signup', 'at_gathering_signup' );
	add_action( 'admin_post_at_rotate_invite', 'at_gathering_rotate_invite' );
}
add_action( 'at_gathering_register_invitations', 'at_gathering_register_invitations' );
