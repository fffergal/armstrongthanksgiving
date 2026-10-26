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
function at_gathering_invitation_url( $token = '', $draft_token = '', $continue_to_rsvp = false ) {
	$url = home_url( '/signup/' );
	$args = array();
	if ( $token ) {
		$args['at_setup'] = $token;
	}
	$draft_token = at_gathering_rsvp_draft_token( $draft_token );
	if ( $draft_token ) {
		$args['at_rsvp_draft'] = $draft_token;
	}
	if ( $continue_to_rsvp ) {
		$args['at_continue'] = 'rsvp';
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

/** Create a signed, unguessable review token that can retain an RSVP handoff. */
function at_gathering_create_invitation_review_token( $draft_token = '', $continue_to_rsvp = false ) {
	$nonce = at_gathering_create_setup_token();
	if ( ! $nonce ) {
		return '';
	}
	$payload = wp_json_encode(
		array(
			'nonce'    => $nonce,
			'draft'    => at_gathering_rsvp_draft_token( $draft_token ),
			'continue' => $continue_to_rsvp ? 1 : 0,
		)
	);
	$encoded = rtrim( strtr( base64_encode( $payload ), '+/', '-_' ), '=' );
	return $encoded . '.' . hash_hmac( 'sha256', $encoded, wp_salt( 'auth' ) );
}

/** Validate and decode an invitation-review bearer token. */
function at_gathering_decode_invitation_review_token( $token ) {
	if ( ! preg_match( '/\A([A-Za-z0-9_-]+)\.([a-f0-9]{64})\z/', (string) $token, $matches ) || ! hash_equals( hash_hmac( 'sha256', $matches[1], wp_salt( 'auth' ) ), $matches[2] ) ) {
		return false;
	}
	$encoded = strtr( $matches[1], '-_', '+/' );
	$encoded .= str_repeat( '=', ( 4 - strlen( $encoded ) % 4 ) % 4 );
	$payload = json_decode( base64_decode( $encoded, true ), true );
	if ( ! is_array( $payload ) || ! preg_match( '/\A[a-f0-9]{64}\z/', (string) ( $payload['nonce'] ?? '' ) ) ) {
		return false;
	}
	return array(
		'draft_token'      => at_gathering_rsvp_draft_token( $payload['draft'] ?? '' ),
		'continue_to_rsvp' => 1 === (int) ( $payload['continue'] ?? 0 ),
	);
}

/**
 * Rotate an invited guest's setup token and send the link.
 * This is also the shared entry point for RSVP account-claim handoffs.
 * Public callers must always return the same generic response.
 */
function at_gathering_send_claim_link( $email, $draft_token = '', $continue_to_rsvp = false, $allow_blank_name = false ) {
	global $wpdb;
	$email = at_gathering_normalize_email( $email );
	if ( ! is_email( $email ) ) {
		return false;
	}
	$guest = at_gathering_roster_guest_by_email( $email );
	if ( ! $guest || ! empty( $guest->user_id ) || ( ! $allow_blank_name && '' === trim( (string) $guest->display_name ) ) ) {
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
			$updated = $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET token_hash = %s, token_expires = %s, updated_at = %s WHERE id = %d AND user_id IS NULL AND claim_state = %s", hash( 'sha256', $token ), $expires, $now, (int) $guest->id, 'invited' ) );
			return 1 === $updated ? true : new WP_Error( 'at_invitation_unavailable', 'This invitation is not available.' );
		}
	);
	if ( is_wp_error( $result ) ) {
		return false;
	}
	$draft_token = at_gathering_rsvp_draft_token( $draft_token );
	$url = at_gathering_invitation_url( $token, $draft_token, $continue_to_rsvp );
	$message = $continue_to_rsvp
		? '<p>You have been invited to the Armstrong Thanksgiving gathering. Use the link below to set up your account and continue to your RSVP.</p><p><a href="' . esc_url( $url ) . '">Set up your account and continue to RSVP</a>. This link expires in two days and can be used once.</p>'
		: '<p>You have been invited to join the Armstrong Thanksgiving gathering site.</p><p><a href="' . esc_url( $url ) . '">Set up your account</a>. This link expires in two days and can be used once.</p>';
	$subject = $continue_to_rsvp ? 'Continue your Armstrong Thanksgiving RSVP' : 'Set up your Armstrong Thanksgiving account';
	$sent = (bool) wp_mail( $email, $subject, $message, array( 'Content-Type: text/html; charset=UTF-8' ) );
	if ( $sent ) {
		$sent_at = current_time( 'mysql', true );
		$wpdb->insert( at_gathering_invitation_sends_table(), array( 'guest_id' => (int) $guest->id, 'sent_at' => $sent_at ), array( '%d', '%s' ) );
		$wpdb->update( at_gathering_roster_table(), array( 'invited_at' => $sent_at, 'updated_at' => $sent_at ), array( 'id' => (int) $guest->id ) );
	}
	return $sent;
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
	global $wpdb;
	$ip = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ?? 'unknown' ) );
	$email_key = 'at_invite_rate_email_' . hash( 'sha256', at_gathering_normalize_email( $email ) );
	$ip_key = 'at_invite_rate_ip_' . hash( 'sha256', $ip );
	$window = (int) floor( time() / HOUR_IN_SECONDS );
	$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE 'at_invite_rate_%' AND CAST(SUBSTRING_INDEX(option_value, ':', 1) AS UNSIGNED) < %d",
			$window
		)
	);
	$increment = static function ( $key ) use ( $wpdb, $window ) {
		$initial = $window . ':1';
		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no') ON DUPLICATE KEY UPDATE option_value = IF(CAST(SUBSTRING_INDEX(option_value, ':', 1) AS UNSIGNED) = %d, CONCAT(%d, ':', CAST(SUBSTRING_INDEX(option_value, ':', -1) AS UNSIGNED) + 1), %s)",
				$key,
				$initial,
				$window,
				$window,
				$initial
			)
		);
		wp_cache_delete( $key, 'options' );
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT SUBSTRING_INDEX(option_value, ':', -1) FROM {$wpdb->options} WHERE option_name = %s LIMIT 1", $key ) );
	};
	$email_count = $increment( $email_key );
	$ip_count = $increment( $ip_key );
	return $email_count > 3 || $ip_count > 20;
}

/** Reject known automated test mailboxes and submissions that fill the trap field. */
function at_gathering_invitation_request_is_spam( $email, $honeypot = '' ) {
	if ( '' !== trim( (string) $honeypot ) ) {
		return true;
	}
	$email  = at_gathering_normalize_email( $email );
	$domain = substr( strrchr( $email, '@' ) ?: '', 1 );
	return 'formtests.info' === $domain || ( strlen( $domain ) > strlen( '.formtests.info' ) && '.formtests.info' === substr( $domain, -strlen( '.formtests.info' ) ) );
}

function at_gathering_signup_shortcode() {
	$token = sanitize_text_field( wp_unslash( $_GET['at_setup'] ?? '' ) );
	$draft_token = at_gathering_rsvp_draft_token( wp_unslash( $_GET['at_rsvp_draft'] ?? '' ) );
	$draft_return = at_gathering_rsvp_draft_return_url( $draft_token );
	$continue_to_rsvp = 'rsvp' === sanitize_key( wp_unslash( $_GET['at_continue'] ?? '' ) );
	$guest = '' !== $token && preg_match( '/\A[a-f0-9]{64}\z/', $token )
		? $GLOBALS['wpdb']->get_row( $GLOBALS['wpdb']->prepare( 'SELECT * FROM ' . at_gathering_roster_table() . ' WHERE token_hash = %s AND claim_state = %s AND token_expires > %s LIMIT 1', hash( 'sha256', $token ), 'invited', current_time( 'mysql', true ) ) )
		: null;
	$existing = $guest ? get_user_by( 'email', $guest->email_normalized ) : false;
	$notice = sanitize_key( wp_unslash( $_GET['at_setup_result'] ?? '' ) );
	$login_return = $draft_return ? $draft_return : ( $continue_to_rsvp ? home_url( '/rsvp/' ) : home_url( '/' ) );
	ob_start();
	?>
	<div class="at-signup-app">
		<?php if ( 'requested' === $notice ) : ?>
			<div class="at-success" role="status">Thanks, we’ve received your request. If you don’t get an email straight away, we’ll send you one soon.</div>
		<?php elseif ( 'claimed' === $notice ) : ?>
			<div class="at-success" role="status"><strong><?php echo $continue_to_rsvp ? 'Your invitation is connected.' : 'Your account is ready.'; ?></strong><br><a href="<?php echo esc_url( wp_login_url( $login_return ) ); ?>">Sign in with your password</a><?php echo ( $draft_return || $continue_to_rsvp ) ? ' to continue your RSVP.' : ' to visit the gathering site.'; ?></div>
		<?php elseif ( $guest ) : ?>
			<?php if ( 'error' === $notice ) : ?><div class="at-success at-error" role="alert">We could not finish setting up your account. Check the details and try again.</div><?php endif; ?>
			<form class="at-signup-form at-rsvp-form" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post">
				<div class="at-rsvp-intro"><h2><?php echo $continue_to_rsvp ? 'Continue to your RSVP' : 'Set up your account'; ?></h2><p><?php echo $continue_to_rsvp ? ( $existing ? 'Confirm your details to link your invitation, then sign in to continue to your RSVP.' : 'Choose a password to set up your account and continue to your RSVP.' ) : ( $existing ? 'Confirm your details to link your existing account to the invitation.' : 'Choose a password for your gathering account.' ); ?></p></div>
				<input type="hidden" name="action" value="at_claim_invitation"><input type="hidden" name="at_setup_token" value="<?php echo esc_attr( $token ); ?>"><input type="hidden" name="at_rsvp_draft" value="<?php echo esc_attr( $draft_token ); ?>"><input type="hidden" name="at_continue" value="<?php echo $continue_to_rsvp ? 'rsvp' : ''; ?>">
				<?php wp_nonce_field( 'at_claim_invitation_' . hash( 'sha256', $token ), 'at_claim_nonce' ); ?>
				<fieldset class="at-account-fields"><legend>Your details</legend><div class="at-rsvp-grid">
					<label>Display name<input type="text" name="at_display_name" value="<?php echo esc_attr( $guest->display_name ); ?>" autocomplete="name" required></label>
					<?php if ( $existing ) : ?><p>This address already has an account. Set up the invitation to link it without changing your password or access. You can sign in with your existing password afterward.</p>
					<?php else : ?><label>Password<input type="password" name="at_password" autocomplete="new-password" minlength="10" required></label><label>Confirm password<input type="password" name="at_password_confirm" autocomplete="new-password" minlength="10" required></label><?php endif; ?>
				</div></fieldset><p class="at-form-actions"><button class="at-button" type="submit"><?php echo $continue_to_rsvp && ! $existing ? 'Set up account and continue' : 'Set up account'; ?></button></p>
			</form>
		<?php else : ?>
			<?php if ( 'error' === $notice ) : ?><div class="at-success at-error" role="alert">That setup link is unavailable. Request a new link if you have an invitation.</div><?php endif; ?>
			<form class="at-signup-form at-rsvp-form" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post">
				<div class="at-rsvp-intro"><h2>Set up your gathering account</h2><p>Enter your email address and we’ll send a setup link if an invitation is available.</p></div>
				<input type="hidden" name="action" value="at_request_invitation"><input type="hidden" name="at_return_url" value="<?php echo esc_url( home_url( '/signup/' ) ); ?>"><input type="hidden" name="at_rsvp_draft" value="<?php echo esc_attr( $draft_token ); ?>"><?php wp_nonce_field( 'at_request_invitation', 'at_request_nonce' ); ?>
				<label class="at-spam-trap" aria-hidden="true">Leave this field empty<input type="text" name="at_website" value="" tabindex="-1" autocomplete="off"></label>
				<p class="at-form-login-note">Already have an account? <a href="<?php echo esc_url( wp_login_url( $draft_return ? $draft_return : get_permalink() ) ); ?>">Sign in</a>.</p>
				<label>Email<input type="email" name="at_email" autocomplete="email" required></label><p class="at-form-actions"><button class="at-button" type="submit">Request setup link</button></p>
			</form>
		<?php endif; ?>
	</div>
	<?php
	return ob_get_clean();
}

/** Queue an invite request for hosts without revealing roster membership publicly. */
function at_gathering_notify_admins_of_invitation_request( $email, $draft_token = '', $continue_to_rsvp = false ) {
	global $wpdb;
	$email = at_gathering_normalize_email( $email );
	if ( ! is_email( $email ) ) {
		return false;
	}
	$token = at_gathering_create_invitation_review_token( $draft_token, $continue_to_rsvp );
	if ( ! $token ) {
		return false;
	}
	$roster_table = at_gathering_roster_table();
	$now = current_time( 'mysql', true );
	$expires = gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS );
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$roster_table} WHERE claim_state = %s AND token_expires < %s", 'requested', $now ) );
	$guest = at_gathering_roster_guest_by_email( $email );
	if ( $guest && 'requested' !== $guest->claim_state ) {
		return false;
	}
	$request_data = array(
		'token_hash'             => hash( 'sha256', $token ),
		'token_expires'          => $expires,
		'updated_at'             => $now,
	);
	if ( $guest ) {
		$stored = $wpdb->update( $roster_table, $request_data, array( 'id' => (int) $guest->id, 'claim_state' => 'requested' ) );
	} else {
		$request_data['email_normalized'] = $email;
		$request_data['display_name'] = '';
		$request_data['claim_state'] = 'requested';
		$request_data['user_id'] = null;
		$request_data['created_at'] = $now;
		$stored = $wpdb->insert( $roster_table, $request_data );
	}
	if ( false === $stored || 0 === $stored ) {
		return false;
	}

	$admin_users = get_users( array( 'role' => 'administrator', 'fields' => array( 'user_email' ) ) );
	$recipients = array();
	foreach ( $admin_users as $admin_user ) {
		$admin_email = sanitize_email( is_object( $admin_user ) ? $admin_user->user_email : $admin_user );
		if ( is_email( $admin_email ) ) {
			$recipients[] = $admin_email;
		}
	}
	$recipients = array_values( array_unique( $recipients ) );
	if ( ! $recipients ) {
		$fallback = sanitize_email( get_option( 'admin_email' ) );
		if ( is_email( $fallback ) ) {
			$recipients[] = $fallback;
		}
	}
	$decision_url = static function ( $decision ) use ( $token ) {
		return add_query_arg(
			array(
				'action'   => 'at_review_invitation_request',
				'decision' => $decision,
				'token'    => $token,
			),
			admin_url( 'admin-post.php' )
		);
	};
	$message = '<p>Someone requested an invitation to the Armstrong Thanksgiving gathering.</p>'
		. '<p>Email address: <strong>' . esc_html( $email ) . '</strong></p>'
		. '<p><a href="' . esc_url( $decision_url( 'approve' ) ) . '">Yes, add them to the invite list and send an invitation</a></p>'
		. '<p><a href="' . esc_url( $decision_url( 'decline' ) ) . '">No, decline this request</a></p>'
		. '<p>These links expire in 24 hours. Opening a link will show a confirmation page; it will not make a change until you confirm.</p>';
	$sent = $recipients && wp_mail( $recipients, 'Invitation request for Armstrong Thanksgiving', $message, array( 'Content-Type: text/html; charset=UTF-8' ) );
	if ( ! $sent ) {
		$wpdb->delete( $roster_table, array( 'email_normalized' => $email, 'claim_state' => 'requested', 'token_hash' => hash( 'sha256', $token ) ) );
	}
	return (bool) $sent;
}

/** Process an invitation request without disclosing whether the address is listed. */
function at_gathering_process_invitation_request( $email, $draft_token = '', $continue_to_rsvp = false ) {
	$email = at_gathering_normalize_email( $email );
	if ( ! is_email( $email ) || at_gathering_invitation_rate_limited( $email ) ) {
		return false;
	}
	$guest = at_gathering_roster_guest_by_email( $email );
	if ( $guest && 'invited' === $guest->claim_state && empty( $guest->user_id ) ) {
		return at_gathering_send_claim_link( $email, $draft_token, $continue_to_rsvp );
	}
	if ( ! $guest || 'requested' === $guest->claim_state ) {
		return at_gathering_notify_admins_of_invitation_request( $email, $draft_token, $continue_to_rsvp );
	}
	return false;
}

/** Render a token-protected decision form, with no changes on the link GET. */
function at_gathering_render_invitation_request_review( $decision, $token, $email ) {
	$approve = 'approve' === $decision;
	$label = $approve ? 'Yes, add them to the invite list and send an invitation' : 'No, decline this request';
	$nonce_action = 'at_invitation_review_' . hash( 'sha256', $token . ':' . $decision );
	status_header( 200 );
	nocache_headers();
	?>
	<!doctype html><html <?php language_attributes(); ?>><head><meta charset="<?php bloginfo( 'charset' ); ?>"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Review invitation request</title><style>body{margin:0;padding:2rem;background:#f6ede1;color:#4c2518;font:16px/1.5 system-ui,sans-serif}main{max-width:38rem;margin:8vh auto;padding:2rem;border-radius:1rem;background:#fffaf4;box-shadow:0 18px 45px rgba(76,37,24,.12)}h1{font-family:Georgia,serif}button{padding:.8rem 1rem;border:0;border-radius:999px;background:#b33f31;color:white;font:inherit;font-weight:700;cursor:pointer}@media(max-width:600px){body{padding:1rem}main{margin:5vh auto;padding:1.25rem}}</style></head><body><main>
	<h1>Review invitation request</h1>
	<p><?php echo $approve ? 'Add this address to the invite list and send an invitation?' : 'Decline the invitation request for this address?'; ?></p>
	<p><strong><?php echo esc_html( $email ); ?></strong></p>
	<form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post">
		<input type="hidden" name="action" value="at_review_invitation_request">
		<input type="hidden" name="decision" value="<?php echo esc_attr( $decision ); ?>">
		<input type="hidden" name="token" value="<?php echo esc_attr( $token ); ?>">
		<?php wp_nonce_field( $nonce_action, 'at_invitation_review_nonce' ); ?>
		<button type="submit"><?php echo esc_html( $label ); ?></button>
	</form>
	</main></body></html>
	<?php
	exit;
}

/** Handle a host's tokenized decision without requiring a WordPress login. */
function at_gathering_review_invitation_request() {
	global $wpdb;
	$method = strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ?? 'GET' ) ) );
	$input = 'POST' === $method ? $_POST : $_GET;
	$token = sanitize_text_field( wp_unslash( $input['token'] ?? '' ) );
	$decision = sanitize_key( wp_unslash( $input['decision'] ?? '' ) );
	$review_payload = at_gathering_decode_invitation_review_token( $token );
	if ( ! $review_payload || ! in_array( $decision, array( 'approve', 'decline' ), true ) ) {
		wp_die( 'This invitation request link is invalid or has expired.', 'Invitation request', array( 'response' => 404 ) );
	}
	$token_hash = hash( 'sha256', $token );
	$table = at_gathering_roster_table();
	if ( 'GET' === $method ) {
		$request = $wpdb->get_row( $wpdb->prepare( "SELECT email_normalized FROM {$table} WHERE token_hash = %s AND claim_state = %s AND token_expires > %s LIMIT 1", $token_hash, 'requested', current_time( 'mysql', true ) ) );
		if ( ! $request ) {
			wp_die( 'This invitation request link is invalid or has expired.', 'Invitation request', array( 'response' => 404 ) );
		}
		at_gathering_render_invitation_request_review( $decision, $token, $request->email_normalized );
	}
	$nonce = sanitize_text_field( wp_unslash( $_POST['at_invitation_review_nonce'] ?? '' ) );
	$nonce_action = 'at_invitation_review_' . hash( 'sha256', $token . ':' . $decision );
	if ( 'POST' !== $method || ! wp_verify_nonce( $nonce, $nonce_action ) ) {
		wp_die( 'Please open the invitation request link again and confirm your choice.', 'Invitation request', array( 'response' => 403 ) );
	}
	$result = at_gathering_transaction(
		static function () use ( $wpdb, $table, $token_hash, $decision ) {
			$request = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE token_hash = %s AND claim_state = %s AND token_expires > %s LIMIT 1 FOR UPDATE", $token_hash, 'requested', current_time( 'mysql', true ) ) );
			if ( ! $request ) {
				return new WP_Error( 'at_invitation_request_expired', 'This invitation request link is invalid or has expired.' );
			}
			if ( 'approve' === $decision ) {
				$updated = $wpdb->update(
					$table,
					array( 'claim_state' => 'invited', 'token_hash' => '', 'token_expires' => null, 'updated_at' => current_time( 'mysql', true ) ),
					array( 'id' => (int) $request->id, 'claim_state' => 'requested', 'token_hash' => $token_hash ),
					array( '%s', '%s', '%s', '%s' ),
					array( '%d', '%s', '%s' )
				);
			} else {
				$updated = $wpdb->delete( $table, array( 'id' => (int) $request->id, 'claim_state' => 'requested', 'token_hash' => $token_hash ) );
			}
			if ( 1 !== $updated ) {
				return new WP_Error( 'at_invitation_request_resolved', 'This invitation request has already been handled.' );
			}
			return $request;
		}
	);
	if ( is_wp_error( $result ) ) {
		wp_die( esc_html( $result->get_error_message() ), 'Invitation request', array( 'response' => 409 ) );
	}
	if ( 'approve' === $decision ) {
		$sent = at_gathering_send_claim_link( $result->email_normalized, $review_payload['draft_token'], $review_payload['continue_to_rsvp'], true );
		$message = $sent ? 'The address was added to the invite list and an invitation email was sent.' : 'The address was added to the invite list, but the invitation email could not be sent. You can send it from the gathering admin page.';
	} else {
		$message = 'The invitation request was declined. The address was not added to the invite list.';
	}
	wp_die( '<p>' . esc_html( $message ) . '</p>', 'Invitation request', array( 'response' => 200 ) );
}

function at_gathering_request_invitation() {
	if ( ! isset( $_POST['at_request_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['at_request_nonce'] ) ), 'at_request_invitation' ) ) {
		wp_die( 'Sorry, we could not process that request.' );
	}
	$email = sanitize_email( wp_unslash( $_POST['at_email'] ?? '' ) );
	$draft_token = at_gathering_rsvp_draft_token( wp_unslash( $_POST['at_rsvp_draft'] ?? '' ) );
	if ( ! at_gathering_invitation_request_is_spam( $email, wp_unslash( $_POST['at_website'] ?? '' ) ) ) {
		at_gathering_process_invitation_request( $email, $draft_token );
	}
	wp_safe_redirect( add_query_arg( 'at_setup_result', 'requested', home_url( '/signup/' ) ) );
	exit;
}

/** Send an invitation setup link from the signed-out RSVP entry point. */
function at_gathering_request_rsvp_link() {
	if ( ! isset( $_POST['at_rsvp_request_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['at_rsvp_request_nonce'] ) ), 'at_rsvp_request_link' ) ) {
		wp_die( 'Sorry, we could not process that request.' );
	}
	$email = sanitize_email( wp_unslash( $_POST['at_email'] ?? '' ) );
	if ( ! at_gathering_invitation_request_is_spam( $email, wp_unslash( $_POST['at_website'] ?? '' ) ) ) {
		at_gathering_process_invitation_request( $email, '', true );
	}
	wp_safe_redirect( add_query_arg( 'at_setup_result', 'requested', home_url( '/rsvp/' ) ) );
	exit;
}

function at_gathering_claim_invitation_post() {
	$token = sanitize_text_field( wp_unslash( $_POST['at_setup_token'] ?? '' ) );
	$nonce = sanitize_text_field( wp_unslash( $_POST['at_claim_nonce'] ?? '' ) );
	$draft_token = at_gathering_rsvp_draft_token( wp_unslash( $_POST['at_rsvp_draft'] ?? '' ) );
	$continue_to_rsvp = 'rsvp' === sanitize_key( wp_unslash( $_POST['at_continue'] ?? '' ) );
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
		$args = array(
			'at_setup'        => rawurlencode( $token ),
			'at_setup_result' => 'error',
		);
		if ( $draft_token ) {
			$args['at_rsvp_draft'] = $draft_token;
		}
		if ( $continue_to_rsvp ) {
			$args['at_continue'] = 'rsvp';
		}
		wp_safe_redirect( add_query_arg( $args, home_url( '/signup/' ) ) );
		exit;
	}
	$draft_return = at_gathering_rsvp_draft_return_url( $draft_token );
	if ( ( $draft_return || $continue_to_rsvp ) && $new_account_id === (int) $user->ID ) {
		// The new guest has just selected this account password. Continue only
		// this first setup session; later sign-ins still use the password form.
		wp_set_current_user( $user->ID );
		wp_set_auth_cookie( $user->ID );
		do_action( 'wp_login', $user->user_login, $user );
		wp_safe_redirect( $draft_return ? $draft_return : home_url( '/rsvp/' ) );
		exit;
	}
	$args = array( 'at_setup_result' => 'claimed' );
	if ( $draft_token ) {
		$args['at_rsvp_draft'] = $draft_token;
	}
	if ( $continue_to_rsvp ) {
		$args['at_continue'] = 'rsvp';
	}
	wp_safe_redirect( add_query_arg( $args, home_url( '/signup/' ) ) );
	exit;
}

function at_gathering_save_invitation() {
	if ( ! current_user_can( 'manage_options' ) || ! isset( $_POST['at_invitation_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['at_invitation_nonce'] ) ), 'at_save_invitation' ) ) {
		wp_die( 'Sorry, the invitation could not be saved.' );
	}
	$mode = sanitize_key( wp_unslash( $_POST['at_invitation_mode'] ?? 'save' ) );
	if ( 'visibility' === $mode ) {
		global $wpdb;
		$guest_id = absint( $_POST['at_invitation_id'] ?? 0 );
		$hidden = 'hide' === sanitize_key( wp_unslash( $_POST['at_invitation_visibility'] ?? '' ) ) ? 1 : 0;
		$updated = $guest_id ? $wpdb->update( at_gathering_roster_table(), array( 'hidden_from_picker' => $hidden, 'updated_at' => current_time( 'mysql', true ) ), array( 'id' => $guest_id ), array( '%d', '%s' ), array( '%d' ) ) : false;
		wp_safe_redirect( add_query_arg( 'at_invitation', false === $updated ? 'visibility_failed' : ( $hidden ? 'hidden' : 'shown' ), admin_url( 'admin.php?page=at-gathering' ) ) );
		exit;
	}
	if ( 'delete' === $mode ) {
		$guest_id = absint( $_POST['at_invitation_id'] ?? 0 );
		$result = $guest_id ? at_gathering_delete_pending_invitation( $guest_id ) : false;
		$notice = true === $result ? 'deleted' : ( is_wp_error( $result ) ? 'delete_failed' : 'claimed' );
		wp_safe_redirect( add_query_arg( 'at_invitation', $notice, admin_url( 'admin.php?page=at-gathering' ) ) );
		exit;
	}
	if ( 'send_all' === $mode ) {
		global $wpdb;
		$emails = $wpdb->get_col( 'SELECT email_normalized FROM ' . at_gathering_roster_table() . " WHERE claim_state = 'invited' AND display_name <> '' ORDER BY id ASC" );
		$sent = 0;
		foreach ( $emails as $email ) {
			if ( at_gathering_send_claim_link( $email ) ) {
				++$sent;
			}
		}
		wp_safe_redirect( add_query_arg( array( 'at_invitation' => 'all_sent', 'at_sent_count' => $sent, 'at_total_count' => count( $emails ) ), admin_url( 'admin.php?page=at-gathering' ) ) );
		exit;
	}
	if ( 'bulk_add' === $mode ) {
		$lines = preg_split( '/\r\n|\r|\n/', (string) wp_unslash( $_POST['at_invitation_emails'] ?? '' ) );
		$added = 0;
		$skipped = 0;
		$previous_text = '';
		$previous_was_email = false;
		foreach ( $lines as $line ) {
			$line = trim( sanitize_text_field( $line ) );
			if ( '' === $line ) {
				continue;
			}
			if ( false === strpos( $line, '@' ) ) {
				$previous_text = $line;
				$previous_was_email = false;
				continue;
			}
			$email = at_gathering_normalize_email( $line );
			if ( ! is_email( $email ) ) {
				++$skipped;
				$previous_text = '';
				$previous_was_email = false;
				continue;
			}
			$name = $previous_was_email ? '' : $previous_text;
			if ( at_gathering_store_invitation( $email, $name ) ) {
				++$added;
			} else {
				++$skipped;
			}
			$previous_text = '';
			$previous_was_email = true;
		}
		wp_safe_redirect( add_query_arg( array( 'at_invitation' => 'bulk_added', 'at_added_count' => $added, 'at_skipped_count' => $skipped ), admin_url( 'admin.php?page=at-gathering' ) ) );
		exit;
	}
	$name = sanitize_text_field( trim( wp_unslash( $_POST['at_invitation_name'] ?? '' ) ) );
	$email = at_gathering_normalize_email( wp_unslash( $_POST['at_invitation_email'] ?? '' ) );
	$existing_guest = at_gathering_roster_guest_by_email( $email );
	$can_send_without_name = $existing_guest && empty( $existing_guest->user_id ) && 'invited' === $existing_guest->claim_state;
	if ( ! is_email( $email ) || ( 'send' === $mode && ! $name && ! $can_send_without_name ) ) {
		wp_safe_redirect( add_query_arg( 'at_invitation', 'invalid', admin_url( 'admin.php?page=at-gathering' ) ) );
		exit;
	}
	$stored = at_gathering_store_invitation( $email, $name );
	if ( ! $stored ) {
		wp_safe_redirect( add_query_arg( 'at_invitation', 'claimed', admin_url( 'admin.php?page=at-gathering' ) ) );
		exit;
	}
	$sent = 'send' === $mode ? at_gathering_send_claim_link( $email, '', false, true ) : null;
	wp_safe_redirect( add_query_arg( 'at_invitation', true === $sent ? 'sent' : ( false === $sent ? 'failed' : 'saved' ), admin_url( 'admin.php?page=at-gathering' ) ) );
	exit;
}

/** Delete an invitation only while it remains unclaimed and unlinked to a user. */
function at_gathering_delete_pending_invitation( $guest_id ) {
	global $wpdb;
	$guest_id = absint( $guest_id );
	if ( ! $guest_id ) {
		return false;
	}
	return at_gathering_transaction(
		static function () use ( $wpdb, $guest_id ) {
			$table = at_gathering_roster_table();
			$guest = $wpdb->get_row( $wpdb->prepare( "SELECT id, user_id, claim_state FROM {$table} WHERE id = %d FOR UPDATE", $guest_id ) );
			if ( ! $guest || ! empty( $guest->user_id ) || 'invited' !== $guest->claim_state ) {
				return false;
			}
			$deleted = $wpdb->delete( $table, array( 'id' => $guest_id, 'user_id' => null, 'claim_state' => 'invited' ), array( '%d', '%d', '%s' ) );
			if ( 1 !== $deleted ) {
				return false;
			}
			$history_deleted = $wpdb->delete( at_gathering_invitation_sends_table(), array( 'guest_id' => $guest_id ), array( '%d' ) );
			if ( false === $history_deleted ) {
				return new WP_Error( 'at_invitation_delete_failed', 'Invitation history could not be deleted.' );
			}
			return true;
		}
	);
}

/** Save or update an unclaimed roster entry without sending an email. */
function at_gathering_store_invitation( $email, $name ) {
	global $wpdb;
	$guest = at_gathering_roster_guest_by_email( $email );
	if ( $guest && 'claimed' === $guest->claim_state ) {
		return false;
	}
	if ( $guest ) {
		$wpdb->update( at_gathering_roster_table(), array( 'display_name' => $name, 'updated_at' => current_time( 'mysql', true ) ), array( 'id' => (int) $guest->id ) );
	} else {
		$now = current_time( 'mysql', true );
		$wpdb->insert( at_gathering_roster_table(), array( 'email_normalized' => $email, 'display_name' => $name, 'claim_state' => 'invited', 'created_at' => $now, 'updated_at' => $now ), array( '%s', '%s', '%s', '%s', '%s' ) );
	}
	return true;
}

function at_gathering_invitation_admin_section() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	global $wpdb;
	$guests = $wpdb->get_results( 'SELECT id, display_name, email_normalized, user_id, claim_state, invited_at, token_expires, hidden_from_picker FROM ' . at_gathering_roster_table() . " WHERE claim_state <> 'requested' ORDER BY display_name ASC, id ASC" );
	$send_history = $wpdb->get_results( 'SELECT guest_id, sent_at FROM ' . at_gathering_invitation_sends_table() . ' ORDER BY sent_at DESC, id DESC' );
	$send_history_by_guest = array();
	foreach ( $send_history as $send ) {
		$send_history_by_guest[ (int) $send->guest_id ][] = $send->sent_at;
	}
	?>
	<hr><h2>Guest invitations</h2>
	<?php if ( isset( $_GET['at_invitation'] ) ) : $notice = sanitize_key( wp_unslash( $_GET['at_invitation'] ) ); ?><div class="notice notice-info"><p><?php echo esc_html( array( 'sent' => 'Invitation link sent.', 'failed' => 'Invitation saved, but its email could not be sent.', 'saved' => 'Guest added to the invite list. No email was sent.', 'deleted' => 'Pending invitation deleted.', 'delete_failed' => 'The pending invitation could not be deleted. Please try again.', 'claimed' => 'That invitation has already been claimed or is no longer available.', 'hidden' => 'Guest hidden from the RSVP adult picker. Their account and RSVP are unchanged.', 'shown' => 'Guest shown in the RSVP adult picker again.', 'visibility_failed' => 'Guest visibility could not be updated.', 'invalid' => 'Enter a valid email address. New invitations need a name before sending.', 'bulk_added' => sprintf( '%d guests added. %d lines skipped. No emails were sent.', absint( $_GET['at_added_count'] ?? 0 ), absint( $_GET['at_skipped_count'] ?? 0 ) ), 'all_sent' => sprintf( 'Invitation emails sent to %1$d of %2$d guests who have not signed up.', absint( $_GET['at_sent_count'] ?? 0 ), absint( $_GET['at_total_count'] ?? 0 ) ) )[ $notice ] ?? 'Invitation updated.' ); ?></p></div><?php endif; ?>
	<form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post"><input type="hidden" name="action" value="at_save_invitation"><input type="hidden" name="at_invitation_mode" value="save"><?php wp_nonce_field( 'at_save_invitation', 'at_invitation_nonce' ); ?>
		<p><label>Name (optional) <input type="text" name="at_invitation_name"></label> <label>Email <input type="email" name="at_invitation_email" required></label> <button class="button button-primary" type="submit">Add to invite list</button></p>
	</form>
	<form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post"><input type="hidden" name="action" value="at_save_invitation"><input type="hidden" name="at_invitation_mode" value="bulk_add"><?php wp_nonce_field( 'at_save_invitation', 'at_invitation_nonce' ); ?>
		<p><label for="at-invitation-emails">Paste guest names and emails</label><br><span class="description">Lines with an @ are emails. The previous nonblank line is the name; consecutive email lines leave the name blank.</span><br><textarea id="at-invitation-emails" name="at_invitation_emails" rows="5" class="large-text" required></textarea></p><p><button class="button" type="submit">Add emails to invite list</button></p>
	</form>
	<p class="description">Hide test or other accounts from the RSVP adult picker without deleting their account, RSVP, or invite history.</p>
	<table class="widefat striped at-invitation-admin-list"><thead><tr><th>Name</th><th>Email</th><th>Status</th><th>RSVP picker</th><th>Invites sent</th><th>Action</th></tr></thead><tbody>
	<?php if ( ! $guests ) : ?><tr><td colspan="6">No invited guests yet.</td></tr><?php else : foreach ( $guests as $guest ) : ?><tr><td data-label="Name"><?php echo esc_html( $guest->display_name ?: 'Name needed' ); ?></td><td data-label="Email"><?php echo esc_html( $guest->email_normalized ); ?></td><td data-label="Status"><?php echo esc_html( $guest->claim_state ); ?></td><td data-label="RSVP picker"><?php echo ! empty( $guest->hidden_from_picker ) ? 'Hidden' : 'Shown'; ?></td><td data-label="Invites sent"><?php $history = $send_history_by_guest[ (int) $guest->id ] ?? array(); if ( ! $history && $guest->invited_at ) { $history[] = $guest->invited_at; } $history = array_map( static function ( $sent_at ) { return get_date_from_gmt( $sent_at, 'Y-m-d H:i' ); }, $history ); echo $history ? nl2br( esc_html( implode( "\n", $history ) ) ) : 'Not sent'; ?></td><td data-label="Actions"><div class="at-invitation-actions"><form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post"><input type="hidden" name="action" value="at_save_invitation"><input type="hidden" name="at_invitation_mode" value="visibility"><input type="hidden" name="at_invitation_visibility" value="<?php echo ! empty( $guest->hidden_from_picker ) ? 'show' : 'hide'; ?>"><input type="hidden" name="at_invitation_id" value="<?php echo esc_attr( $guest->id ); ?>"><?php wp_nonce_field( 'at_save_invitation', 'at_invitation_nonce' ); ?><button class="button" type="submit"><?php echo ! empty( $guest->hidden_from_picker ) ? 'Show in picker' : 'Hide from picker'; ?></button></form><?php if ( 'invited' === $guest->claim_state && empty( $guest->user_id ) ) : ?><form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post"><input type="hidden" name="action" value="at_save_invitation"><input type="hidden" name="at_invitation_mode" value="save"><input type="hidden" name="at_invitation_email" value="<?php echo esc_attr( $guest->email_normalized ); ?>"><?php wp_nonce_field( 'at_save_invitation', 'at_invitation_nonce' ); ?><label>Name <input type="text" name="at_invitation_name" value="<?php echo esc_attr( $guest->display_name ); ?>"></label> <button class="button" type="submit">Save name</button></form><form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post"><input type="hidden" name="action" value="at_save_invitation"><input type="hidden" name="at_invitation_mode" value="send"><input type="hidden" name="at_invitation_name" value="<?php echo esc_attr( $guest->display_name ); ?>"><input type="hidden" name="at_invitation_email" value="<?php echo esc_attr( $guest->email_normalized ); ?>"><?php wp_nonce_field( 'at_save_invitation', 'at_invitation_nonce' ); ?><button class="button" type="submit"><?php echo $guest->invited_at ? 'Send reminder' : 'Send invitation'; ?></button></form><form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post"><input type="hidden" name="action" value="at_save_invitation"><input type="hidden" name="at_invitation_mode" value="delete"><input type="hidden" name="at_invitation_id" value="<?php echo esc_attr( $guest->id ); ?>"><?php wp_nonce_field( 'at_save_invitation', 'at_invitation_nonce' ); ?><button class="button" type="submit">Delete invite</button></form><?php endif; ?></div></td></tr><?php endforeach; endif; ?>
	</tbody></table>
	<form class="at-invitation-send-all" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post"><input type="hidden" name="action" value="at_save_invitation"><input type="hidden" name="at_invitation_mode" value="send_all"><?php wp_nonce_field( 'at_save_invitation', 'at_invitation_nonce' ); ?><p><button class="button" type="submit">Send to everyone on the invite list who hasn’t signed up</button></p></form>
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
	add_action( 'admin_post_at_review_invitation_request', 'at_gathering_review_invitation_request' );
	add_action( 'admin_post_nopriv_at_review_invitation_request', 'at_gathering_review_invitation_request' );
	add_action( 'admin_post_at_rsvp_request_link', 'at_gathering_request_rsvp_link' );
	add_action( 'admin_post_nopriv_at_rsvp_request_link', 'at_gathering_request_rsvp_link' );
	add_action( 'admin_post_at_claim_invitation', 'at_gathering_claim_invitation_post' );
	add_action( 'admin_post_nopriv_at_claim_invitation', 'at_gathering_claim_invitation_post' );
	add_action( 'admin_post_at_save_invitation', 'at_gathering_save_invitation' );
	add_action( 'at_gathering_admin_page_sections', 'at_gathering_invitation_admin_section' );
}
add_action( 'at_gathering_register_invitations', 'at_gathering_register_invitations' );
