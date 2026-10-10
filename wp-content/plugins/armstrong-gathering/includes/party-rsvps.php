<?php
function at_gathering_form_values() {
	$token = sanitize_key( wp_unslash( $_GET['at_form'] ?? '' ) );
	if ( $token ) {
		$values = get_transient( 'at_gathering_form_' . $token );
		delete_transient( 'at_gathering_form_' . $token );
		return is_array( $values ) ? $values : array();
	}

	$draft_token = sanitize_key( wp_unslash( $_GET['at_rsvp_draft'] ?? '' ) );
	if ( ! $draft_token ) {
		return array();
	}
	$values = get_transient( 'at_gathering_rsvp_draft_' . $draft_token );
	if ( is_user_logged_in() && $values ) {
		delete_transient( 'at_gathering_rsvp_draft_' . $draft_token );
	}
	return is_array( $values ) ? $values : array();
}

function at_gathering_party_choices_authorized() {
	if ( current_user_can( 'manage_options' ) ) {
		return true;
	}
	$user = wp_get_current_user();
	$guest = $user->exists() ? at_gathering_roster_guest_by_user( $user->ID ) : null;
	return $guest && 'claimed' === $guest->claim_state;
}

function at_gathering_party_roster_choices() {
	if ( ! at_gathering_party_choices_authorized() ) {
		return array();
	}
	return at_gathering_roster_choices();
}

function at_gathering_party_roster_choices_endpoint() {
	if ( ! is_user_logged_in() || ! at_gathering_party_choices_authorized() || ! check_ajax_referer( 'at_party_roster_choices', 'nonce', false ) ) {
		wp_send_json_error( array( 'message' => 'Sign in with a claimed guest account to view party choices.' ), 403 );
	}
	$choices = array_map(
		static function ( $guest ) {
			return array( 'id' => (int) $guest->id, 'display_name' => (string) $guest->display_name );
		},
		at_gathering_party_roster_choices()
	);
	wp_send_json_success( $choices );
}

function at_gathering_admin_update_party() {
	$user_id = absint( $_POST['at_rsvp_user_id'] ?? 0 );
	if ( ! current_user_can( 'manage_options' ) || ! $user_id || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['at_party_update_nonce'] ?? '' ) ), 'at_party_update_' . $user_id ) ) {
		wp_die( 'Sorry, that party could not be updated.' );
	}
	$owner = at_gathering_roster_guest_by_user( $user_id );
	if ( ! $owner ) {
		wp_die( 'That RSVP owner is not linked to a guest roster entry.' );
	}
	$guest_ids = array_values( array_unique( array_map( 'absint', (array) wp_unslash( $_POST['at_party_guest_ids'] ?? array() ) ) ) );
	$guest_ids[] = (int) $owner->id;
	$result = at_gathering_host_move_party_assignments( $user_id, $guest_ids );
	$state = is_wp_error( $result ) ? 'error' : 'updated';
	wp_safe_redirect( admin_url( 'admin.php?page=at-gathering&at_party=' . $state ) );
	exit;
}

function at_gathering_admin_link_legacy_owner() {
	$user_id = absint( $_POST['at_legacy_user_id'] ?? 0 );
	$guest_id = absint( $_POST['at_legacy_guest_id'] ?? 0 );
	$email = sanitize_email( wp_unslash( $_POST['at_legacy_confirmed_email'] ?? '' ) );
	if ( ! current_user_can( 'manage_options' ) || ! $user_id || ! $guest_id || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['at_legacy_owner_nonce'] ?? '' ) ), 'at_legacy_owner_' . $user_id ) ) {
		wp_die( 'Sorry, that legacy RSVP owner could not be linked.' );
	}
	$result = at_gathering_link_legacy_rsvp_owner( $user_id, $guest_id, $email );
	$state = is_wp_error( $result ) ? 'error' : 'saved';
	wp_safe_redirect( admin_url( 'admin.php?page=at-gathering&at_legacy_owner=' . $state ) );
	exit;
}

/** Host-only atomic correction: move unowned adults from their current party. */
function at_gathering_host_move_party_assignments( $target_user_id, $guest_ids ) {
	global $wpdb;
	$target_user_id = absint( $target_user_id );
	$guest_ids = array_values( array_unique( array_filter( array_map( 'absint', (array) $guest_ids ) ) ) );
	$owner = at_gathering_roster_guest_by_user( $target_user_id );
	if ( ! current_user_can( 'manage_options' ) ) {
		return new WP_Error( 'at_host_required', 'A host must correct party assignments.' );
	}
	if ( ! $owner || ! in_array( (int) $owner->id, $guest_ids, true ) ) {
		return new WP_Error( 'at_party_owner_required', 'The target RSVP owner must remain assigned to their own party.' );
	}
	return at_gathering_transaction(
		function () use ( $wpdb, $target_user_id, $guest_ids ) {
			$table = at_gathering_assignments_table();
			$conflicts = array();
			foreach ( $guest_ids as $guest_id ) {
				$guest = at_gathering_roster_guest( $guest_id );
				if ( ! $guest || 'claimed' !== $guest->claim_state ) {
					return new WP_Error( 'at_roster_guest_not_found', 'A selected adult is no longer available.' );
				}
				$assignment = at_gathering_party_assignment_for_guest( $guest_id );
				if ( $assignment && (int) $assignment->rsvp_user_id !== $target_user_id ) {
					if ( (int) $assignment->is_owner ) {
						return new WP_Error( 'at_party_assignment_conflict', 'An RSVP owner must have their own RSVP removed before they can be assigned to another party.', array( 'guest_id' => $guest_id ) );
					}
					$conflicts[] = $guest_id;
				}
			}
			foreach ( $conflicts as $guest_id ) {
				if ( false === $wpdb->delete( $table, array( 'guest_id' => $guest_id ), array( '%d' ) ) ) {
					return new WP_Error( 'at_party_assignment_write_failed', 'An adult could not be moved from the previous party.' );
				}
			}
			if ( false === $wpdb->delete( $table, array( 'rsvp_user_id' => $target_user_id ), array( '%d' ) ) ) {
				return new WP_Error( 'at_party_assignment_write_failed', 'The current party assignments could not be replaced.' );
			}
			foreach ( $guest_ids as $guest_id ) {
				$inserted = $wpdb->insert( $table, array( 'rsvp_user_id' => $target_user_id, 'guest_id' => $guest_id, 'is_owner' => (int) at_gathering_roster_guest_by_user( $target_user_id )->id === $guest_id ? 1 : 0, 'created_at' => current_time( 'mysql', true ) ), array( '%d', '%d', '%d', '%s' ) );
				if ( false === $inserted ) {
					return new WP_Error( 'at_party_assignment_conflict', 'The host correction conflicted with another party update.', array( 'guest_id' => $guest_id ) );
				}
			}
			return true;
		}
	);
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
	$children_count = 'no' === $status ? 0 : max( 0, min( 12, absint( $_POST['at_children_count'] ?? 0 ) ) );
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
	$dietary = sanitize_textarea_field( wp_unslash( $_POST['at_dietary'] ?? '' ) );
	$custom_food = sanitize_text_field( wp_unslash( $_POST['at_custom_food'] ?? '' ) );
	$custom_food_amount = min( 9999, absint( $_POST['at_custom_food_amount'] ?? 0 ) );
	$notes = sanitize_textarea_field( wp_unslash( $_POST['at_notes'] ?? '' ) );
	$party_guest_ids = array_values( array_unique( array_map( 'absint', (array) wp_unslash( $_POST['at_party_guest_ids'] ?? array() ) ) ) );
	$touched = ! empty( $_POST['_at_rsvp_touched'] ) || 'yes' !== $status || 0 !== $children_count || ! empty( $party_guest_ids ) || '' !== $dietary || ! empty( $food_amounts ) || '' !== $custom_food || 0 < $custom_food_amount || '' !== $notes;
	$values = array(
		'status'            => $status,
		'children_count'    => $children_count,
		'party_guest_ids'   => $party_guest_ids,
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
	$form_count  = isset( $values['children_count'] ) ? (int) $values['children_count'] : ( $rsvp && isset( $rsvp->children_count ) ? (int) $rsvp->children_count : 0 );
	$party_choices = at_gathering_party_roster_choices();
	$linked_guest = $user->exists() ? at_gathering_roster_guest_by_user( $user->ID ) : null;
	$assigned_ids = array();
	if ( ! array_key_exists( 'party_guest_ids', $values ) && $rsvp && at_gathering_party_choices_authorized() ) {
		global $wpdb;
		$assigned_ids = array_map( 'intval', $wpdb->get_col( $wpdb->prepare( 'SELECT guest_id FROM ' . at_gathering_assignments_table() . ' WHERE rsvp_user_id = %d', $user->ID ) ) );
	}
	if ( array_key_exists( 'party_guest_ids', $values ) ) {
		$assigned_ids = array_map( 'intval', (array) $values['party_guest_ids'] );
	}
	if ( $linked_guest ) {
		$assigned_ids[] = (int) $linked_guest->id;
	}
	$can_view_contributors = $user->exists();

	ob_start();
	?>
	<div class="at-rsvp-app">
		<?php if ( isset( $_GET['at_rsvp'] ) && 'error' === sanitize_key( $_GET['at_rsvp'] ) ) : ?>
			<div class="at-success at-error" role="alert"><strong>We could not save that RSVP.</strong><br><?php echo esc_html( sanitize_text_field( wp_unslash( $_GET['at_message'] ?? 'Please check the form and try again.' ) ) ); ?></div>
		<?php endif; ?>
		<form id="at-rsvp-form" class="at-rsvp-form" data-party-roster-nonce="<?php echo esc_attr( wp_create_nonce( 'at_party_roster_choices' ) ); ?>" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post">
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
			<?php if ( $party_choices ) : ?>
				<fieldset class="at-party-roster"><legend>Adults in your party</legend><p class="at-field-help">Your RSVP reserves each selected adult, including you, for this party.</p>
					<?php foreach ( $party_choices as $choice ) : ?>
						<label><input type="checkbox" name="at_party_guest_ids[]" value="<?php echo esc_attr( $choice->id ); ?>" <?php checked( in_array( (int) $choice->id, $assigned_ids, true ) ); ?> <?php disabled( $linked_guest && (int) $linked_guest->id === (int) $choice->id ); ?>> <?php echo esc_html( $choice->display_name ); ?><?php echo $linked_guest && (int) $linked_guest->id === (int) $choice->id ? ' (you)' : ''; ?></label>
					<?php endforeach; ?>
				</fieldset>
			<?php elseif ( is_user_logged_in() && ! at_gathering_party_choices_authorized() ) : ?>
				<p class="at-field-help">To RSVP, first claim your invitation from the <a href="<?php echo esc_url( home_url( '/signup/' ) ); ?>">guest setup page</a>.</p>
			<?php endif; ?>
			<label>Children (ages 0–17)
				<select name="at_children_count"><?php for ( $i = 0; $i <= 12; $i++ ) : ?><option value="<?php echo esc_attr( $i ); ?>" <?php selected( $form_count, $i ); ?>><?php echo esc_html( $i ); ?></option><?php endfor; ?></select>
			</label>
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
			<?php if ( ! $user->exists() ) : ?><p class="at-form-login-note at-form-login-note-top">Already claimed your invitation? <button class="at-link-button" type="submit" name="action" value="at_rsvp_signin" formnovalidate>Sign in first</button>. Your RSVP draft will be kept.</p><?php endif; ?>
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
	$rsvp = $user->exists() ? at_gathering_get_rsvp( $user->ID ) : null;
	$party_names = array();
	if ( $saved && $rsvp ) {
		global $wpdb;
		$party_names = $wpdb->get_col( $wpdb->prepare( 'SELECT g.display_name FROM ' . at_gathering_assignments_table() . ' a JOIN ' . at_gathering_roster_table() . ' g ON g.id = a.guest_id WHERE a.rsvp_user_id = %d ORDER BY a.is_owner DESC, g.display_name ASC', $user->ID ) );
	}

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
				<?php if ( $rsvp ) : ?><p><strong>Attendance:</strong> <?php echo esc_html( at_gathering_status_label( $rsvp->status ) ); ?></p><p><strong>Adults reserved:</strong> <?php echo esc_html( implode( ', ', $party_names ) ?: 'Your RSVP owner' ); ?></p><p><strong>Children:</strong> <?php echo esc_html( (int) ( $rsvp->children_count ?? 0 ) ); ?></p><?php endif; ?>
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
	$group_signup = '';

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
	$return = esc_url_raw( wp_unslash( $_POST['at_return_url'] ?? home_url( '/rsvp/' ) ) );
	$status = sanitize_key( wp_unslash( $_POST['at_status'] ?? 'yes' ) );
	$status = in_array( $status, array( 'yes', 'maybe', 'no' ), true ) ? $status : 'yes';
	$children_count = 'no' === $status ? 0 : max( 0, min( 12, absint( $_POST['at_children_count'] ?? 0 ) ) );
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
	$party_guest_ids = array_values( array_unique( array_filter( array_map( 'absint', (array) wp_unslash( $_POST['at_party_guest_ids'] ?? array() ) ) ) ) );
	$form_values = compact( 'status', 'children_count', 'party_guest_ids', 'dietary', 'foods', 'food_amounts', 'custom_food', 'custom_food_amount', 'notes' );
	$user = wp_get_current_user();
	$owner_guest = $user->exists() ? at_gathering_roster_guest_by_user( $user->ID ) : null;
	if ( ! $user->exists() || ! $owner_guest || 'claimed' !== $owner_guest->claim_state ) {
		$token = strtolower( wp_generate_password( 32, false, false ) );
		set_transient( 'at_gathering_rsvp_draft_' . $token, $form_values, 30 * MINUTE_IN_SECONDS );
		$return_with_draft = add_query_arg( 'at_rsvp_draft', $token, $return );
		$claim_url = apply_filters( 'at_gathering_rsvp_claim_setup_url', add_query_arg( 'at_rsvp_draft', $token, home_url( '/signup/' ) ), $return_with_draft, $token );
		$pending_guest = $user->exists() ? at_gathering_roster_guest_by_email( $user->user_email ) : null;
		if ( $user->exists() && $pending_guest && 'invited' === $pending_guest->claim_state && function_exists( 'at_gathering_send_claim_link' ) ) {
			$rate_limited = function_exists( 'at_gathering_invitation_rate_limited' ) && at_gathering_invitation_rate_limited( $user->user_email );
			if ( ! $rate_limited ) {
				at_gathering_send_claim_link( $user->user_email, $token );
			}
			$claim_url = add_query_arg(
				array(
					'at_setup_result' => 'requested',
					'at_rsvp_draft'   => $token,
				),
				home_url( '/signup/' )
			);
		}
		wp_safe_redirect( $user->exists() ? $claim_url : wp_login_url( $claim_url ) );
		exit;
	}
	if ( ! at_gathering_party_choices_authorized() ) {
		at_gathering_redirect_error( $return, 'Claim your invitation before saving a party RSVP.', $form_values );
	}
	$party_guest_ids[] = (int) $owner_guest->id;
	$party_guest_ids = array_values( array_unique( array_filter( array_map( 'absint', $party_guest_ids ) ) ) );
	foreach ( $party_guest_ids as $guest_id ) {
		$choice = at_gathering_roster_guest( $guest_id );
		if ( ! $choice || 'claimed' !== $choice->claim_state ) {
			at_gathering_redirect_error( $return, 'One selected adult is no longer available. Please review the party choices.', $form_values );
		}
	}

	$data = array(
		'user_id'     => $user->ID,
		'status'      => $status,
		// Keep the legacy fields for host reconciliation; never infer guests from them.
		'guest_count' => (int) ( ( at_gathering_get_rsvp( $user->ID )->guest_count ?? 1 ) ),
		'guest_names' => (string) ( ( at_gathering_get_rsvp( $user->ID )->guest_names ?? '' ) ),
		'children_count' => $children_count,
		'dietary'     => $dietary,
		'foods'       => wp_json_encode( $foods ),
		'food_amounts' => wp_json_encode( $food_amounts ),
		'custom_food' => $custom_food,
		'notes'       => $notes,
		'updated_at'  => current_time( 'mysql' ),
	);

	global $wpdb;
	$existing = at_gathering_get_rsvp( $user->ID );
	$data['created_at'] = $existing ? $existing->created_at : current_time( 'mysql' );
	$write_rsvp = static function () use ( $wpdb, $data, $user ) {
		$updated = $wpdb->replace( at_gathering_table(), $data, array( '%d', '%s', '%d', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s' ) );
		return false === $updated ? new WP_Error( 'at_party_write_failed', 'The RSVP details could not be saved.' ) : true;
	};
	$saved = at_gathering_replace_party_assignments( $user->ID, $party_guest_ids, $write_rsvp );
	if ( is_wp_error( $saved ) ) {
		$message = 'at_party_assignment_conflict' === $saved->get_error_code() ? $saved->get_error_message() . ' Please contact the hosts.' : $saved->get_error_message();
		at_gathering_redirect_error( $return, $message, $form_values );
	}
	if ( function_exists( 'wp_cache_clear_cache' ) ) {
		wp_cache_clear_cache();
	}

	$party_names = array();
	foreach ( $party_guest_ids as $guest_id ) { $guest = at_gathering_roster_guest( $guest_id ); if ( $guest ) { $party_names[] = $guest->display_name; } }
	$total_people = 'no' === $status ? 0 : count( $party_guest_ids ) + $children_count;
	$message = at_gathering_confirmation_message( $user, $status, $total_people, implode( ', ', $party_names ) . ( $children_count ? ' · ' . $children_count . ' children' : '' ), $foods, $data['dietary'], $data['notes'], '', $food_amounts );
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
function at_gathering_party_legacy_names_fingerprint( $rows = null ) {
	global $wpdb;
	if ( 0 === func_num_args() ) {
		$rows = $wpdb->get_results( 'SELECT user_id, guest_names FROM ' . at_gathering_table() . " WHERE guest_names <> '' ORDER BY user_id ASC" );
	}
	$fingerprint_rows = array();
	foreach ( (array) $rows as $row ) {
		$fingerprint_rows[] = array(
			'user_id'     => absint( is_array( $row ) ? ( $row['user_id'] ?? 0 ) : ( $row->user_id ?? 0 ) ),
			'guest_names' => (string) ( is_array( $row ) ? ( $row['guest_names'] ?? '' ) : ( $row->guest_names ?? '' ) ),
		);
	}
	usort(
		$fingerprint_rows,
		static function ( $a, $b ) {
			return $a['user_id'] <=> $b['user_id'];
		}
	);
	return hash( 'sha256', wp_json_encode( $fingerprint_rows ) );
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
	$reviewed_hash = sanitize_text_field( wp_unslash( $_POST['at_party_reconcile_hash'] ?? '' ) );
	if ( ! $reviewed_hash || ! hash_equals( $reviewed_hash, at_gathering_party_legacy_names_fingerprint() ) ) {
		wp_safe_redirect( admin_url( 'admin.php?page=at-gathering&at_party_reconcile=changed' ) );
		exit;
	}
	update_option( 'at_gathering_party_reconciliation_complete', true );
	update_option( 'at_gathering_party_reconciliation_reviewed_at', current_time( 'mysql' ) );
	update_option( 'at_gathering_party_reconciliation_review_hash', $reviewed_hash );
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
		<?php
		$attendance = array( 'yes' => 0, 'maybe' => 0, 'no' => 0 );
		$expected_people = 0;
		$party_assignments_authoritative = at_gathering_party_rsvps_enabled();
		foreach ( $rows as $attendance_row ) {
			$attendance[ $attendance_row->status ] = ( $attendance[ $attendance_row->status ] ?? 0 ) + 1;
			if ( 'yes' === $attendance_row->status ) {
				$assigned_adults = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . at_gathering_assignments_table() . ' WHERE rsvp_user_id = %d', $attendance_row->user_id ) );
				// Partial host mappings do not replace legacy totals until reconciliation is confirmed.
				$adults = $party_assignments_authoritative && $assigned_adults ? $assigned_adults : (int) $attendance_row->guest_count;
				$expected_people += $adults + (int) ( $attendance_row->children_count ?? 0 );
			}
		}
		?>
		<p class="at-party-totals"><strong><?php echo esc_html( $expected_people ); ?></strong> expected attendees · <strong><?php echo esc_html( $attendance['maybe'] ); ?></strong> maybe parties · <strong><?php echo esc_html( $attendance['no'] ); ?></strong> no parties</p>
		<?php if ( 'updated' === ( $_GET['at_party'] ?? '' ) ) : ?><div class="notice notice-success is-dismissible"><p>Party assignments updated.</p></div><?php elseif ( 'error' === ( $_GET['at_party'] ?? '' ) ) : ?><div class="notice notice-error is-dismissible"><p>The party assignments could not be updated because of a conflict. Review the current assignments and try again.</p></div><?php endif; ?>
		<h2>Legacy party reconciliation</h2>
		<?php if ( 'changed' === ( $_GET['at_party_reconcile'] ?? '' ) ) : ?><div class="notice notice-error inline"><p>The legacy party list changed while you were reviewing it. Please review the current names and confirm again.</p></div><?php endif; ?>
		<?php if ( 'error' === ( $_GET['at_legacy_owner'] ?? '' ) ) : ?><div class="notice notice-error inline"><p>The legacy owner could not be linked. Confirm the account email and roster selection, then resolve any existing link or assignment conflict.</p></div><?php elseif ( 'saved' === ( $_GET['at_legacy_owner'] ?? '' ) ) : ?><div class="notice notice-success inline"><p>Legacy RSVP owner linked. Review and assign the legacy party adults below before confirming reconciliation.</p></div><?php endif; ?>
		<?php if ( at_gathering_party_rsvps_enabled() ) : ?>
			<div class="notice notice-success inline"><p>Legacy party names have been reviewed. Roster based party saves are enabled.</p></div>
		<?php else : ?>
			<div class="notice notice-warning inline"><p>Guest party saves are locked until a host links legacy owners and assigns known adults explicitly. Names are preserved as entered and are never matched automatically. Use each RSVP’s party editor below, then confirm this snapshot.</p></div>
			<div class="at-gathering-responsive-table" role="region" aria-label="Legacy party reconciliation" tabindex="0" style="max-width:100%; overflow-x:auto;">
			<table class="widefat striped"><thead><tr><th>RSVP owner</th><th>Legacy party names</th><th>Owner roster link</th><th>Updated</th></tr></thead><tbody>
			<?php
			$legacy_rows = $wpdb->get_results( 'SELECT r.user_id, r.guest_names, r.updated_at, u.display_name, u.user_email FROM ' . at_gathering_table() . ' r LEFT JOIN ' . $wpdb->users . ' u ON u.ID = r.user_id WHERE r.guest_names <> \'\' ORDER BY r.updated_at DESC' );
			$legacy_hash = at_gathering_party_legacy_names_fingerprint( $legacy_rows );
			if ( ! $legacy_rows ) :
				?><tr><td colspan="4">No legacy party names are waiting for review.</td></tr><?php
			else :
				foreach ( $legacy_rows as $legacy_row ) :
					$legacy_owner = at_gathering_roster_guest_by_user( $legacy_row->user_id );
					?><tr><td><?php echo esc_html( $legacy_row->display_name ?: 'Unknown account #' . absint( $legacy_row->user_id ) ); ?> (user ID <?php echo esc_html( $legacy_row->user_id ); ?>)</td><td><?php echo esc_html( $legacy_row->guest_names ); ?></td><td><?php if ( $legacy_owner ) : ?><strong><?php echo esc_html( $legacy_owner->display_name ); ?></strong> (linked)<?php else : ?><form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="at_legacy_owner_link"><input type="hidden" name="at_legacy_user_id" value="<?php echo esc_attr( $legacy_row->user_id ); ?>"><?php wp_nonce_field( 'at_legacy_owner_' . absint( $legacy_row->user_id ), 'at_legacy_owner_nonce' ); ?><label><span class="screen-reader-text">Roster guest for <?php echo esc_html( $legacy_row->display_name ); ?></span><select name="at_legacy_guest_id" required><option value="">Select roster guest</option><?php foreach ( $wpdb->get_results( 'SELECT id, display_name, email_normalized FROM ' . at_gathering_roster_table() . ' ORDER BY display_name ASC, id ASC' ) as $roster_guest ) : ?><option value="<?php echo esc_attr( $roster_guest->id ); ?>"><?php echo esc_html( $roster_guest->display_name . ' · ' . $roster_guest->email_normalized ); ?></option><?php endforeach; ?></select></label><label><span class="screen-reader-text">Confirm current email for <?php echo esc_html( $legacy_row->display_name ); ?></span><input type="email" name="at_legacy_confirmed_email" value="<?php echo esc_attr( $legacy_row->user_email ); ?>" required></label><button class="button" type="submit">Link owner</button></form><?php endif; ?></td><td><?php echo esc_html( mysql2date( 'j M Y, H:i', $legacy_row->updated_at ) ); ?></td></tr><?php
				endforeach;
			endif;
			?>
			</tbody></table>
			</div>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="at_party_reconcile">
				<input type="hidden" name="at_party_reconcile_hash" value="<?php echo esc_attr( $legacy_hash ); ?>">
				<?php wp_nonce_field( 'at_party_reconcile', 'at_party_reconcile_nonce' ); ?>
				<p><label><input type="checkbox" required> I reviewed the legacy names, linked the owners, and explicitly assigned every known adult who should remain reserved.</label></p>
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
		<div class="at-gathering-responsive-table" role="region" aria-label="RSVP list" tabindex="0" style="max-width:100%; overflow-x:auto;">
		<table class="widefat striped at-rsvp-admin-list"><thead><tr><th>Friend</th><th>Status</th><th>People</th><th>Food</th><th>Dietary</th><th>Note for hosts</th><th>Updated</th><th>Actions</th></tr></thead><tbody>
		<?php if ( ! $rows ) : ?><tr><td colspan="8">No RSVPs yet.</td></tr><?php endif; ?>
		<?php foreach ( $rows as $row ) : $user = get_user_by( 'id', $row->user_id ); $row_foods = (array) json_decode( $row->foods, true ); $row_food_amounts = at_gathering_rsvp_food_amounts( $row ); $assigned = $wpdb->get_col( $wpdb->prepare( 'SELECT guest_id FROM ' . at_gathering_assignments_table() . ' WHERE rsvp_user_id = %d', $row->user_id ) ); ?>
			<tr><td><strong><?php echo esc_html( $user ? $user->display_name : 'Unknown friend' ); ?></strong><br><small><?php echo esc_html( $user ? $user->user_email : '' ); ?></small></td><td><?php echo esc_html( at_gathering_status_label( $row->status ) ); ?></td><td><?php echo esc_html( count( $assigned ) ); ?> adults + <?php echo esc_html( (int) ( $row->children_count ?? 0 ) ); ?> children<?php if ( $row->guest_names ) : ?><br><small>Legacy names: <?php echo esc_html( $row->guest_names ); ?></small><?php endif; ?><form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="at_party_update"><input type="hidden" name="at_rsvp_user_id" value="<?php echo esc_attr( $row->user_id ); ?>"><?php wp_nonce_field( 'at_party_update_' . absint( $row->user_id ), 'at_party_update_nonce' ); ?><select name="at_party_guest_ids[]" multiple aria-label="Adults assigned to this RSVP" style="min-width:16rem;max-width:100%;"><?php foreach ( at_gathering_roster_choices() as $choice ) : if ( (int) $choice->id === (int) ( at_gathering_roster_guest_by_user( $row->user_id )->id ?? 0 ) ) { continue; } ?><option value="<?php echo esc_attr( $choice->id ); ?>" <?php selected( in_array( (int) $choice->id, array_map( 'intval', $assigned ), true ) ); ?>><?php echo esc_html( $choice->display_name ); ?></option><?php endforeach; ?></select><button class="button" type="submit">Save party</button></form></td><td><?php echo esc_html( at_gathering_food_amounts_text( $row_foods, $row_food_amounts ) ?: '—' ); ?></td><td><?php echo esc_html( $row->dietary ?: '—' ); ?></td><td><?php echo esc_html( $row->notes ?: '—' ); ?></td><td><?php echo esc_html( mysql2date( 'j M, H:i', $row->updated_at ) ); ?></td><td><form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="at-remove-rsvp-form"><input type="hidden" name="action" value="at_remove_rsvp"><input type="hidden" name="at_rsvp_user_id" value="<?php echo esc_attr( $row->user_id ); ?>"><?php wp_nonce_field( 'at_remove_rsvp_' . absint( $row->user_id ), 'at_remove_rsvp_nonce' ); ?><button class="button button-secondary" type="submit" onclick="return confirm('Remove this RSVP? The member account will remain.');">Remove RSVP</button></form></td></tr>
		<?php endforeach; ?></tbody></table>
		</div>
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

	$deleted = at_gathering_remove_rsvp_records( $user_id );
	if ( is_wp_error( $deleted ) ) {
		wp_die( 'Sorry, that RSVP could not be removed.' );
	}
	if ( $deleted && function_exists( 'wp_cache_clear_cache' ) ) {
		wp_cache_clear_cache();
	}

	$result = $deleted ? 'removed' : 'not_found';
	wp_safe_redirect( admin_url( 'admin.php?page=at-gathering&at_rsvp=' . $result ) );
	exit;
}

/** Delete the RSVP and its guest reservations together so removed guests can be reassigned. */
function at_gathering_remove_rsvp_records( $user_id ) {
	global $wpdb;
	$user_id = absint( $user_id );
	return at_gathering_transaction(
		function () use ( $wpdb, $user_id ) {
			$assignments_deleted = $wpdb->delete( at_gathering_assignments_table(), array( 'rsvp_user_id' => $user_id ), array( '%d' ) );
			if ( false === $assignments_deleted ) {
				return new WP_Error( 'at_rsvp_remove_failed', 'The party assignments could not be removed.' );
			}
			$rsvp_deleted = $wpdb->delete( at_gathering_table(), array( 'user_id' => $user_id ), array( '%d' ) );
			if ( false === $rsvp_deleted ) {
				return new WP_Error( 'at_rsvp_remove_failed', 'The RSVP could not be removed.' );
			}
			return (bool) $rsvp_deleted;
		}
	);
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
	add_action( 'admin_post_at_party_update', 'at_gathering_admin_update_party' );
	add_action( 'admin_post_at_legacy_owner_link', 'at_gathering_admin_link_legacy_owner' );
	add_action( 'wp_ajax_at_gathering_party_roster_choices', 'at_gathering_party_roster_choices_endpoint' );
	add_action( 'wp_ajax_nopriv_at_gathering_party_roster_choices', 'at_gathering_party_roster_choices_endpoint' );
}
add_action( 'at_gathering_register_party_rsvps', 'at_gathering_register_party_rsvps' );
