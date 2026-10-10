<?php
/** Return a valid RSVP draft token without exposing expired or malformed drafts. */
function at_gathering_rsvp_draft_token( $token ) {
	$token = sanitize_key( (string) $token );
	if ( ! preg_match( '/\A[a-z0-9]{32}\z/', $token ) || ! is_array( get_transient( 'at_gathering_rsvp_draft_' . $token ) ) ) {
		return '';
	}
	return $token;
}

/** Return only the fixed RSVP destination for a live invitation handoff draft. */
function at_gathering_rsvp_draft_return_url( $token ) {
	$token = at_gathering_rsvp_draft_token( $token );
	if ( ! $token ) {
		return '';
	}
	$url = add_query_arg( 'at_rsvp_draft', $token, home_url( '/rsvp/' ) );
	return wp_validate_redirect( $url, home_url( '/rsvp/' ) );
}

/** Return the public invitation form destination, optionally preserving an RSVP draft. */
function at_gathering_invitation_url( $token = '', $draft_token = '' ) {
	$url = home_url( '/signup/' );
	$args = array();
	if ( $token ) {
		$args['at_setup'] = $token;
	}
	$draft_token = at_gathering_rsvp_draft_token( $draft_token );
	if ( $draft_token ) {
		$args['at_rsvp_draft'] = $draft_token;
	}
	return $args ? add_query_arg( $args, $url ) : $url;
}

/** Store only a one-way digest of each setup token. */
function at_gathering_create_setup_token() {
	try {
		return bin2hex( random_bytes( 32 ) );
	} catch ( Throwable $error ) {
		return '';
	}
}

/**
 * Rotate an invited guest's setup token and send the link.
 * This is also the shared entry point for RSVP account-claim handoffs.
 * Public callers must always return the same generic response.
 */
function at_gathering_send_claim_link( $email, $draft_token = '' ) {
	global $wpdb;
	$email = at_gathering_normalize_email( $email );
	if ( ! is_email( $email ) ) {
		return false;
	}
	$guest = at_gathering_roster_guest_by_email( $email );
	if ( ! $guest || ! empty( $guest->user_id ) ) {
		return false;
	}
	$token = at_gathering_create_setup_token();
	if ( ! $token ) {
		return false;
	}
	$now = current_time( 'mysql', true );
	$expires = gmdate( 'Y-m-d H:i:s', time() + 2 * DAY_IN_SECONDS );
	$result = at_gathering_transaction(
		static function () use ( $wpdb, $guest, $token, $now, $expires ) {
			$table = at_gathering_roster_table();
			$updated = $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET token_hash = %s, token_expires = %s, invited_at = %s, updated_at = %s WHERE id = %d AND user_id IS NULL AND claim_state = %s", hash( 'sha256', $token ), $expires, $now, $now, (int) $guest->id, 'invited' ) );
			return 1 === $updated ? true : new WP_Error( 'at_invitation_unavailable', 'This invitation is not available.' );
		}
	);
	if ( is_wp_error( $result ) ) {
		return false;
	}
	$draft_token = at_gathering_rsvp_draft_token( $draft_token );
	$url = at_gathering_invitation_url( $token, $draft_token );
	$message = '<p>You have been invited to join the Armstrong Thanksgiving gathering site.</p><p><a href="' . esc_url( $url ) . '">Set up your account</a>. This link expires in two days and can be used once.</p>';
	return (bool) wp_mail( $email, 'Set up your Armstrong Thanksgiving account', $message, array( 'Content-Type: text/html; charset=UTF-8' ) );
}

/**
 * Claim a setup token. The roster row lock and token consumption are in the
 * same database transaction as creating or linking the WordPress account.
 * Existing users retain their password, display name, and capabilities.
 * Returns a WP_User or WP_Error; it does not sign the account in.
 */
function at_gathering_claim_guest( $token, $display_name, $password = '', $password_confirm = '' ) {
	global $wpdb;
	$token = trim( (string) $token );
	$display_name = sanitize_text_field( (string) $display_name );
	if ( ! preg_match( '/\A[a-f0-9]{64}\z/', $token ) ) {
		return new WP_Error( 'at_claim_invalid', 'This setup link is invalid or expired.' );
	}
	$token_hash = hash( 'sha256', $token );
	$guest = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . at_gathering_roster_table() . ' WHERE token_hash = %s LIMIT 1', $token_hash ) );
	if ( ! $guest || ! $guest->token_expires || strtotime( $guest->token_expires . ' UTC' ) < time() || 'invited' !== $guest->claim_state || ! $display_name ) {
		return new WP_Error( 'at_claim_invalid', 'This setup link is invalid or expired.' );
	}
	$existing = get_user_by( 'email', $guest->email_normalized );
	if ( ! $existing && ( strlen( $password ) < 10 || $password !== $password_confirm ) ) {
		return new WP_Error( 'at_claim_password_invalid', 'Choose a password of at least 10 characters and enter it twice.' );
	}
	if ( $existing && (int) $guest->user_id && (int) $guest->user_id !== (int) $existing->ID ) {
		return new WP_Error( 'at_claim_conflict', 'This invitation needs host review.' );
	}
	$user_id = at_gathering_transaction(
		static function () use ( $wpdb, $guest, $token_hash, $display_name, $password, $password_confirm ) {
			$locked = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . at_gathering_roster_table() . ' WHERE id = %d FOR UPDATE', (int) $guest->id ) );
			if ( ! $locked || ! hash_equals( (string) $locked->token_hash, $token_hash ) || ! $locked->token_expires || strtotime( $locked->token_expires . ' UTC' ) < time() || 'invited' !== $locked->claim_state || ! empty( $locked->user_id ) ) {
				return new WP_Error( 'at_claim_invalid', 'This setup link is invalid or expired.' );
			}
			// Re-read while holding the roster lock so a concurrent legacy account
			// creation cannot turn a verified claim into a duplicate account.
			$existing_user = get_user_by( 'email', $locked->email_normalized );
			$linked_guest = at_gathering_roster_guest_by_user( $existing_user ? $existing_user->ID : 0 );
			if ( $existing_user && $linked_guest && (int) $linked_guest->id !== (int) $locked->id ) {
				return new WP_Error( 'at_claim_conflict', 'This invitation needs host review.' );
			}
			if ( $existing_user ) {
				$account_id = (int) $existing_user->ID;
			} else {
				// The email may have belonged to an existing account during the
				// initial lookup and been deleted before this locked re-read. Validate
				// again here so every new-account path requires a chosen password.
				if ( strlen( $password ) < 10 || $password !== $password_confirm ) {
					return new WP_Error( 'at_claim_password_invalid', 'Choose a password of at least 10 characters and enter it twice.' );
				}
				$account_id = wp_insert_user(
					array(
						'user_login'   => at_gathering_unique_login( $locked->email_normalized ),
						'user_pass'    => $password,
						'user_email'   => $locked->email_normalized,
						'display_name' => $display_name,
						'role'         => 'subscriber',
					)
				);
				if ( is_wp_error( $account_id ) ) {
					return new WP_Error( 'at_claim_create_failed', 'The account could not be created.' );
				}
				if ( function_exists( 'bbp_set_user_role' ) && function_exists( 'bbp_get_participant_role' ) ) {
					bbp_set_user_role( $account_id, bbp_get_participant_role() );
				}
			}
			$now = current_time( 'mysql', true );
			$guest_update = array( 'user_id' => (int) $account_id, 'claim_state' => 'claimed', 'token_hash' => '', 'token_expires' => null, 'claimed_at' => $now, 'updated_at' => $now );
			$guest_formats = array( '%d', '%s', '%s', '%s', '%s', '%s' );
			if ( ! $existing_user ) {
				$guest_update['display_name'] = $display_name;
				$guest_formats[] = '%s';
			}
			$updated = $wpdb->update(
				at_gathering_roster_table(),
				$guest_update,
				array( 'id' => (int) $locked->id, 'token_hash' => $token_hash, 'claim_state' => 'invited' ),
				$guest_formats,
				array( '%d', '%s', '%s' )
			);
			if ( 1 !== $updated ) {
				return new WP_Error( 'at_claim_invalid', 'This setup link is invalid or expired.' );
			}
			return (int) $account_id;
		}
	);
	if ( is_wp_error( $user_id ) ) {
		return $user_id;
	}
	return get_user_by( 'id', $user_id );
}

function at_gathering_invitation_rate_limited( $email ) {
	$ip = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ?? 'unknown' ) );
	$email_key = 'at_invite_rate_email_' . hash( 'sha256', at_gathering_normalize_email( $email ) );
	$ip_key = 'at_invite_rate_ip_' . hash( 'sha256', $ip );
	$email_count = (int) get_transient( $email_key );
	$ip_count = (int) get_transient( $ip_key );
	set_transient( $email_key, $email_count + 1, HOUR_IN_SECONDS );
	set_transient( $ip_key, $ip_count + 1, HOUR_IN_SECONDS );
	return $email_count >= 3 || $ip_count >= 20;
}

function at_gathering_signup_shortcode() {
	$token = sanitize_text_field( wp_unslash( $_GET['at_setup'] ?? '' ) );
	$draft_token = at_gathering_rsvp_draft_token( wp_unslash( $_GET['at_rsvp_draft'] ?? '' ) );
	$draft_return = at_gathering_rsvp_draft_return_url( $draft_token );
	$guest = '' !== $token && preg_match( '/\A[a-f0-9]{64}\z/', $token )
		? $GLOBALS['wpdb']->get_row( $GLOBALS['wpdb']->prepare( 'SELECT * FROM ' . at_gathering_roster_table() . ' WHERE token_hash = %s AND claim_state = %s AND token_expires > %s LIMIT 1', hash( 'sha256', $token ), 'invited', current_time( 'mysql', true ) ) )
		: null;
	$existing = $guest ? get_user_by( 'email', $guest->email_normalized ) : false;
	$notice = sanitize_key( wp_unslash( $_GET['at_setup_result'] ?? '' ) );
	ob_start();
	?>
	<div class="at-signup-app">
		<?php if ( 'requested' === $notice ) : ?>
			<div class="at-success" role="status">If an invitation can be set up for that address, we’ll email a link shortly. Check your inbox.</div>
		<?php elseif ( 'claimed' === $notice ) : ?>
			<div class="at-success" role="status"><strong>Your account is ready.</strong><br><a href="<?php echo esc_url( wp_login_url( $draft_return ) ); ?>">Sign in with your password</a><?php echo $draft_return ? ' to continue your RSVP.' : ' to visit the gathering site.'; ?></div>
		<?php elseif ( $guest ) : ?>
			<?php if ( 'error' === $notice ) : ?><div class="at-success at-error" role="alert">We could not finish setting up your account. Check the details and try again.</div><?php endif; ?>
			<form class="at-signup-form at-rsvp-form" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post">
				<div class="at-rsvp-intro"><h2>Set up your account</h2><p>Choose a password for your gathering account.</p></div>
				<input type="hidden" name="action" value="at_claim_invitation"><input type="hidden" name="at_setup_token" value="<?php echo esc_attr( $token ); ?>"><input type="hidden" name="at_rsvp_draft" value="<?php echo esc_attr( $draft_token ); ?>">
				<?php wp_nonce_field( 'at_claim_invitation_' . hash( 'sha256', $token ), 'at_claim_nonce' ); ?>
				<fieldset class="at-account-fields"><legend>Your details</legend><div class="at-rsvp-grid">
					<label>Display name<input type="text" name="at_display_name" value="<?php echo esc_attr( $guest->display_name ); ?>" autocomplete="name" required></label>
					<?php if ( $existing ) : ?><p>This address already has an account. Claiming the invitation will link it without changing its password. <a href="<?php echo esc_url( wp_login_url() ); ?>">Use your existing password to sign in</a>.</p>
					<?php else : ?><label>Password<input type="password" name="at_password" autocomplete="new-password" minlength="10" required></label><label>Confirm password<input type="password" name="at_password_confirm" autocomplete="new-password" minlength="10" required></label><?php endif; ?>
				</div></fieldset><p class="at-form-actions"><button class="at-button" type="submit">Set up account</button></p>
			</form>
		<?php else : ?>
			<?php if ( 'error' === $notice ) : ?><div class="at-success at-error" role="alert">That setup link is unavailable. Request a new link if you have an invitation.</div><?php endif; ?>
			<form class="at-signup-form at-rsvp-form" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post">
				<div class="at-rsvp-intro"><h2>Set up your gathering account</h2><p>Enter your email address and we’ll send a setup link if an invitation is available.</p></div>
				<input type="hidden" name="action" value="at_request_invitation"><input type="hidden" name="at_return_url" value="<?php echo esc_url( home_url( '/signup/' ) ); ?>"><input type="hidden" name="at_rsvp_draft" value="<?php echo esc_attr( $draft_token ); ?>"><?php wp_nonce_field( 'at_request_invitation', 'at_request_nonce' ); ?>
				<p class="at-form-login-note">Already have an account? <a href="<?php echo esc_url( wp_login_url( get_permalink() ) ); ?>">Sign in</a>.</p>
				<label>Email<input type="email" name="at_email" autocomplete="email" required></label><p class="at-form-actions"><button class="at-button" type="submit">Request setup link</button></p>
			</form>
		<?php endif; ?>
	</div>
	<?php
	return ob_get_clean();
}

function at_gathering_request_invitation() {
	if ( ! isset( $_POST['at_request_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['at_request_nonce'] ) ), 'at_request_invitation' ) ) {
		wp_die( 'Sorry, we could not process that request.' );
	}
	$email = sanitize_email( wp_unslash( $_POST['at_email'] ?? '' ) );
	$draft_token = at_gathering_rsvp_draft_token( wp_unslash( $_POST['at_rsvp_draft'] ?? '' ) );
	if ( ! at_gathering_invitation_rate_limited( $email ) ) {
		at_gathering_send_claim_link( $email, $draft_token );
	}
	wp_safe_redirect( add_query_arg( 'at_setup_result', 'requested', home_url( '/signup/' ) ) );
	exit;
}

function at_gathering_claim_invitation_post() {
	$token = sanitize_text_field( wp_unslash( $_POST['at_setup_token'] ?? '' ) );
	$nonce = sanitize_text_field( wp_unslash( $_POST['at_claim_nonce'] ?? '' ) );
	$draft_token = at_gathering_rsvp_draft_token( wp_unslash( $_POST['at_rsvp_draft'] ?? '' ) );
	if ( ! $token || ! wp_verify_nonce( $nonce, 'at_claim_invitation_' . hash( 'sha256', $token ) ) ) {
		wp_safe_redirect( add_query_arg( 'at_setup_result', 'error', home_url( '/signup/' ) ) );
		exit;
	}
	$new_account_id = 0;
	$record_new_account = static function ( $user_id ) use ( &$new_account_id ) {
		$new_account_id = (int) $user_id;
	};
	add_action( 'user_register', $record_new_account, PHP_INT_MAX, 1 );
	$user = at_gathering_claim_guest( $token, wp_unslash( $_POST['at_display_name'] ?? '' ), wp_unslash( $_POST['at_password'] ?? '' ), wp_unslash( $_POST['at_password_confirm'] ?? '' ) );
	remove_action( 'user_register', $record_new_account, PHP_INT_MAX );
	if ( is_wp_error( $user ) ) {
		wp_safe_redirect( add_query_arg( array( 'at_setup' => rawurlencode( $token ), 'at_setup_result' => 'error' ), home_url( '/signup/' ) ) );
		exit;
	}
	$draft_return = at_gathering_rsvp_draft_return_url( $draft_token );
	if ( $draft_return && $new_account_id === (int) $user->ID ) {
		// The new guest has just selected this account password. Continue only
		// this first setup session; later sign-ins still use the password form.
		wp_set_current_user( $user->ID );
		wp_set_auth_cookie( $user->ID );
		do_action( 'wp_login', $user->user_login, $user );
		wp_safe_redirect( $draft_return );
		exit;
	}
	$args = array( 'at_setup_result' => 'claimed' );
	if ( $draft_token ) {
		$args['at_rsvp_draft'] = $draft_token;
	}
	wp_safe_redirect( add_query_arg( $args, home_url( '/signup/' ) ) );
	exit;
}

function at_gathering_save_invitation() {
	if ( ! current_user_can( 'manage_options' ) || ! isset( $_POST['at_invitation_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['at_invitation_nonce'] ) ), 'at_save_invitation' ) ) {
		wp_die( 'Sorry, the invitation could not be saved.' );
	}
	$name = sanitize_text_field( wp_unslash( $_POST['at_invitation_name'] ?? '' ) );
	$email = at_gathering_normalize_email( wp_unslash( $_POST['at_invitation_email'] ?? '' ) );
	if ( ! $name || ! is_email( $email ) ) {
		wp_safe_redirect( add_query_arg( 'at_invitation', 'invalid', admin_url( 'admin.php?page=at-gathering' ) ) );
		exit;
	}
	global $wpdb;
	$guest = at_gathering_roster_guest_by_email( $email );
	if ( $guest && 'claimed' === $guest->claim_state ) {
		wp_safe_redirect( add_query_arg( 'at_invitation', 'claimed', admin_url( 'admin.php?page=at-gathering' ) ) );
		exit;
	}
	if ( $guest ) {
		$wpdb->update( at_gathering_roster_table(), array( 'display_name' => $name, 'updated_at' => current_time( 'mysql', true ) ), array( 'id' => (int) $guest->id ) );
	} else {
		$now = current_time( 'mysql', true );
		$wpdb->insert( at_gathering_roster_table(), array( 'email_normalized' => $email, 'display_name' => $name, 'claim_state' => 'invited', 'created_at' => $now, 'updated_at' => $now ), array( '%s', '%s', '%s', '%s', '%s' ) );
	}
	$sent = at_gathering_send_claim_link( $email );
	wp_safe_redirect( add_query_arg( 'at_invitation', $sent ? 'sent' : 'failed', admin_url( 'admin.php?page=at-gathering' ) ) );
	exit;
}

function at_gathering_invitation_admin_section() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	global $wpdb;
	$guests = $wpdb->get_results( 'SELECT id, display_name, email_normalized, claim_state, invited_at, token_expires FROM ' . at_gathering_roster_table() . ' ORDER BY display_name ASC, id ASC' );
	?>
	<hr><h2>Guest invitations</h2>
	<?php if ( isset( $_GET['at_invitation'] ) ) : ?><div class="notice notice-info"><p><?php echo esc_html( array( 'sent' => 'Invitation link sent.', 'failed' => 'Invitation saved, but its email could not be sent.', 'claimed' => 'That invitation has already been claimed.', 'invalid' => 'Enter a name and valid email address.' )[ sanitize_key( wp_unslash( $_GET['at_invitation'] ) ) ] ?? 'Invitation updated.' ); ?></p></div><?php endif; ?>
	<form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post"><input type="hidden" name="action" value="at_save_invitation"><?php wp_nonce_field( 'at_save_invitation', 'at_invitation_nonce' ); ?>
		<p><label>Name <input type="text" name="at_invitation_name" required></label> <label>Email <input type="email" name="at_invitation_email" required></label> <button class="button button-primary" type="submit">Save and send invitation</button></p>
	</form>
	<table class="widefat striped"><thead><tr><th>Name</th><th>Email</th><th>Status</th><th>Invitation</th><th>Action</th></tr></thead><tbody>
	<?php if ( ! $guests ) : ?><tr><td colspan="5">No invited guests yet.</td></tr><?php else : foreach ( $guests as $guest ) : ?><tr><td><?php echo esc_html( $guest->display_name ); ?></td><td><?php echo esc_html( $guest->email_normalized ); ?></td><td><?php echo esc_html( $guest->claim_state ); ?></td><td><?php echo esc_html( $guest->invited_at ?: 'Not sent' ); ?></td><td><?php if ( 'invited' === $guest->claim_state ) : ?><form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post"><input type="hidden" name="action" value="at_save_invitation"><input type="hidden" name="at_invitation_name" value="<?php echo esc_attr( $guest->display_name ); ?>"><input type="hidden" name="at_invitation_email" value="<?php echo esc_attr( $guest->email_normalized ); ?>"><?php wp_nonce_field( 'at_save_invitation', 'at_invitation_nonce' ); ?><button class="button" type="submit">Resend</button></form><?php endif; ?></td></tr><?php endforeach; endif; ?>
	</tbody></table>
	<?php
}

function at_gathering_reject_legacy_signup() {
	wp_safe_redirect( add_query_arg( 'at_setup_result', 'error', home_url( '/signup/' ) ) );
	exit;
}

function at_gathering_register_invitations() {
	add_shortcode( 'at_signup', 'at_gathering_signup_shortcode' );
	add_action( 'admin_post_at_signup', 'at_gathering_reject_legacy_signup' );
	add_action( 'admin_post_nopriv_at_signup', 'at_gathering_reject_legacy_signup' );
	add_action( 'admin_post_at_request_invitation', 'at_gathering_request_invitation' );
	add_action( 'admin_post_nopriv_at_request_invitation', 'at_gathering_request_invitation' );
	add_action( 'admin_post_at_claim_invitation', 'at_gathering_claim_invitation_post' );
	add_action( 'admin_post_nopriv_at_claim_invitation', 'at_gathering_claim_invitation_post' );
	add_action( 'admin_post_at_save_invitation', 'at_gathering_save_invitation' );
	add_action( 'at_gathering_admin_page_sections', 'at_gathering_invitation_admin_section' );
}
add_action( 'at_gathering_register_invitations', 'at_gathering_register_invitations' );
