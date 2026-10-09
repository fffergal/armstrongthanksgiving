<?php
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

function at_gathering_get_rsvp( $user_id ) {
	global $wpdb;
	return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . at_gathering_table() . ' WHERE user_id = %d', absint( $user_id ) ) );
}

function at_gathering_rsvp_has_other_people( $guest_count, $guest_names ) {
	return (int) $guest_count > 1 || (bool) preg_match( '/\band\b/i', (string) $guest_names );
}


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

/** New roster based party writes remain closed until a host reviews legacy names. */
function at_gathering_party_legacy_names_fingerprint() {
	global $wpdb;
	$rows = $wpdb->get_results( 'SELECT user_id, guest_names FROM ' . at_gathering_table() . " WHERE guest_names <> '' ORDER BY user_id ASC", ARRAY_A );
	return hash( 'sha256', wp_json_encode( $rows ?: array() ) );
}

function at_gathering_party_rsvps_enabled() {
	if ( ! get_option( 'at_gathering_party_reconciliation_complete', false ) ) {
		return false;
	}
	$reviewed_hash = (string) get_option( 'at_gathering_party_reconciliation_review_hash', '' );
	return $reviewed_hash && hash_equals( $reviewed_hash, at_gathering_party_legacy_names_fingerprint() );
}

function at_gathering_reconcile_legacy_parties() {
	if ( ! current_user_can( 'manage_options' ) || ! isset( $_POST['at_party_reconcile_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['at_party_reconcile_nonce'] ) ), 'at_party_reconcile' ) ) {
		wp_die( 'Sorry, only a host can confirm legacy party review.' );
	}
	update_option( 'at_gathering_party_reconciliation_complete', true );
	update_option( 'at_gathering_party_reconciliation_reviewed_at', current_time( 'mysql' ) );
	update_option( 'at_gathering_party_reconciliation_review_hash', at_gathering_party_legacy_names_fingerprint() );
	wp_safe_redirect( admin_url( 'admin.php?page=at-gathering&at_party_reconcile=complete' ) );
	exit;
}
function at_gathering_admin_menu() {
	add_menu_page( 'Gathering RSVPs', 'Gathering RSVPs', 'manage_options', 'at-gathering', 'at_gathering_admin_page', 'dashicons-heart', 26 );
}
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
		<h2>Legacy party reconciliation</h2>
		<?php if ( at_gathering_party_rsvps_enabled() ) : ?>
			<div class="notice notice-success inline"><p>Legacy party names have been reviewed. Roster based party saves are enabled.</p></div>
		<?php else : ?>
			<div class="notice notice-warning inline"><p>Roster based party saves are locked until a host reviews the legacy party names below and records any needed roster assignments. Names are preserved as entered and are never matched automatically.</p></div>
			<table class="widefat striped"><thead><tr><th>RSVP owner</th><th>Legacy party names</th><th>Updated</th></tr></thead><tbody>
			<?php
			$legacy_rows = $wpdb->get_results( 'SELECT r.user_id, r.guest_names, r.updated_at, u.display_name FROM ' . at_gathering_table() . ' r LEFT JOIN ' . $wpdb->users . ' u ON u.ID = r.user_id WHERE r.guest_names <> \'\' ORDER BY r.updated_at DESC' );
			if ( ! $legacy_rows ) :
				?><tr><td colspan="3">No legacy party names are waiting for review.</td></tr><?php
			else :
				foreach ( $legacy_rows as $legacy_row ) :
					?><tr><td><?php echo esc_html( $legacy_row->display_name ?: 'Unknown account #' . absint( $legacy_row->user_id ) ); ?> (user ID <?php echo esc_html( $legacy_row->user_id ); ?>)</td><td><?php echo esc_html( $legacy_row->guest_names ); ?></td><td><?php echo esc_html( mysql2date( 'j M Y, H:i', $legacy_row->updated_at ) ); ?></td></tr><?php
				endforeach;
			endif;
			?>
			</tbody></table>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="at_party_reconcile">
				<?php wp_nonce_field( 'at_party_reconcile', 'at_party_reconcile_nonce' ); ?>
				<p><label><input type="checkbox" required> I reviewed the legacy names and completed any needed explicit roster links and party assignments.</label></p>
				<?php submit_button( 'Confirm legacy party review' ); ?>
			</form>
		<?php endif; ?>
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
		<table class="widefat striped at-rsvp-admin-list"><thead><tr><th>Friend</th><th>Status</th><th>People</th><th>Food</th><th>Dietary</th><th>Note for hosts</th><th>Updated</th><th>Actions</th></tr></thead><tbody>
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
		<?php do_action( 'at_gathering_admin_page_sections' ); ?>
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

function at_gathering_register_party_rsvps() {
	add_action( 'admin_post_at_rsvp_signin', 'at_gathering_rsvp_signin' );
	add_action( 'admin_post_nopriv_at_rsvp_signin', 'at_gathering_rsvp_signin' );
	add_shortcode( 'at_rsvp', 'at_gathering_rsvp_shortcode' );
	add_shortcode( 'at_rsvp_confirmation', 'at_gathering_rsvp_confirmation_shortcode' );
	add_action( 'admin_post_at_save_rsvp', 'at_gathering_save_rsvp' );
	add_action( 'admin_post_nopriv_at_save_rsvp', 'at_gathering_save_rsvp' );
	add_action( 'admin_post_at_party_reconcile', 'at_gathering_reconcile_legacy_parties' );
	add_action( 'admin_menu', 'at_gathering_admin_menu' );
	add_action( 'admin_post_at_remove_rsvp', 'at_gathering_remove_rsvp' );
}
add_action( 'at_gathering_register_party_rsvps', 'at_gathering_register_party_rsvps' );
