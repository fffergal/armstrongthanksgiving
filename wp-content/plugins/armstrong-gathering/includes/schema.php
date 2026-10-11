<?php
function at_gathering_activate() {
	global $wpdb;

	at_gathering_ensure_schema();

	if ( false === get_option( 'at_gathering_foods', false ) ) {
		update_option( 'at_gathering_foods', at_gathering_default_foods() );
	}
	if ( false === get_option( 'at_gathering_next_avatar', false ) ) {
		update_option( 'at_gathering_next_avatar', 1 );
	}
	if ( false === get_option( 'at_gathering_invite_key', false ) ) {
		update_option( 'at_gathering_invite_key', wp_generate_password( 32, false, false ) );
	}
	at_gathering_migrate_content();
	update_option( 'at_gathering_db_version', AT_GATHERING_VERSION );

	// Give existing members a turkey as soon as the plugin is first enabled.
	foreach ( get_users( array( 'fields' => 'ID' ) ) as $user_id ) {
		at_gathering_assign_avatar( $user_id );
	}

	// Turn the old demo topic into a general hello thread while retaining the
	// old slug redirect WordPress records for existing bookmarks.
	$old_topic = get_page_by_path( 'rsvp-and-food', OBJECT, 'topic' );
	if ( $old_topic ) {
		wp_update_post(
			array(
				'ID'           => $old_topic->ID,
				'post_title'   => 'Say hello',
				'post_name'    => 'say-hello',
				'post_content' => 'Share a hello, a photo, or a small plan for the day. RSVP and food choices live on the RSVP page.',
			)
		);
	}
}

function at_gathering_ensure_schema() {
	global $wpdb;

	$table  = at_gathering_table();
	$collate = $wpdb->get_charset_collate();
	$sql    = "CREATE TABLE {$table} (
		id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
		user_id bigint(20) unsigned NOT NULL,
		status varchar(16) NOT NULL DEFAULT 'yes',
		guest_count smallint(5) unsigned NOT NULL DEFAULT 1,
		guest_names text NOT NULL,
		children_count smallint(5) unsigned NOT NULL DEFAULT 0,
		dietary text NOT NULL,
		foods longtext NOT NULL,
		food_amounts longtext NOT NULL,
		custom_food varchar(191) NOT NULL DEFAULT '',
		notes text NOT NULL,
		created_at datetime NOT NULL,
		updated_at datetime NOT NULL,
		PRIMARY KEY  (id),
		UNIQUE KEY user_id (user_id),
		KEY status (status)
	) ENGINE=InnoDB {$collate};";
	$roster = at_gathering_roster_table();
	$assignments = at_gathering_assignments_table();
	$roster_sql = "CREATE TABLE {$roster} (
		id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
		email_normalized varchar(191) NOT NULL,
		display_name varchar(191) NOT NULL DEFAULT '',
		user_id bigint(20) unsigned NULL DEFAULT NULL,
		claim_state varchar(20) NOT NULL DEFAULT 'invited',
		token_hash char(64) NOT NULL DEFAULT '',
		token_expires datetime NULL DEFAULT NULL,
		invited_at datetime NULL DEFAULT NULL,
		claimed_at datetime NULL DEFAULT NULL,
		created_at datetime NOT NULL,
		updated_at datetime NOT NULL,
		PRIMARY KEY  (id),
		UNIQUE KEY email_normalized (email_normalized),
		UNIQUE KEY user_id (user_id),
		KEY claim_state (claim_state)
	) ENGINE=InnoDB {$collate};";
	$assignments_sql = "CREATE TABLE {$assignments} (
		id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
		rsvp_user_id bigint(20) unsigned NOT NULL,
		guest_id bigint(20) unsigned NOT NULL,
		is_owner tinyint(1) unsigned NOT NULL DEFAULT 0,
		created_at datetime NOT NULL,
		PRIMARY KEY  (id),
		UNIQUE KEY guest_id (guest_id),
		UNIQUE KEY party_guest (rsvp_user_id,guest_id),
		KEY rsvp_user_id (rsvp_user_id)
	) ENGINE=InnoDB {$collate};";

	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	dbDelta( $sql );
	dbDelta( $roster_sql );
	dbDelta( $assignments_sql );
	$rsvp_status = $wpdb->get_row( $wpdb->prepare( 'SHOW TABLE STATUS WHERE Name = %s', $table ) );
	if ( $rsvp_status && 'InnoDB' !== $rsvp_status->Engine ) {
		$wpdb->query( "ALTER TABLE {$table} ENGINE=InnoDB" );
	}
	if ( false === get_option( 'at_gathering_party_reconciliation_complete', false ) ) {
		$has_legacy_parties = (bool) $wpdb->get_var( "SELECT 1 FROM {$table} WHERE guest_names <> '' OR guest_count > 1 LIMIT 1" );
		update_option( 'at_gathering_party_reconciliation_complete', ! $has_legacy_parties );
		if ( ! $has_legacy_parties ) {
			update_option( 'at_gathering_party_reconciliation_reviewed_at', current_time( 'mysql' ) );
			update_option( 'at_gathering_party_reconciliation_review_hash', hash( 'sha256', '[]' ) );
		}
	}
}
register_activation_hook( AT_GATHERING_FILE, 'at_gathering_activate' );

function at_gathering_maybe_upgrade() {
	if ( get_option( 'at_gathering_db_version' ) !== AT_GATHERING_VERSION ) {
		at_gathering_ensure_schema();
		at_gathering_migrate_content();
		update_option( 'at_gathering_db_version', AT_GATHERING_VERSION );
	}
}
add_action( 'init', 'at_gathering_maybe_upgrade', 20 );

function at_gathering_roster_table() {
	global $wpdb;
	return $wpdb->prefix . 'at_invited_adults';
}

function at_gathering_assignments_table() {
	global $wpdb;
	return $wpdb->prefix . 'at_party_assignments';
}

function at_gathering_normalize_email( $email ) {
	return strtolower( trim( sanitize_email( (string) $email ) ) );
}

/**
 * Shared feature contract:
 * - Reads return objects/arrays or null; writes return true or WP_Error.
 * - Party writes must run inside at_gathering_transaction() and replace the
 *   complete assignment set only after checking all IDs for conflicts.
 * - RSVP owners are normal roster assignments with is_owner=1.
 * - `at_party_assignment_conflict`, `at_party_reconciliation_required`,
 *   `at_owner_email_mismatch`, `at_owner_link_conflict`, and
 *   `at_transaction_unavailable` are stable error codes.
 */
function at_gathering_roster_guest( $guest_id ) {
	global $wpdb;
	return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . at_gathering_roster_table() . ' WHERE id = %d', absint( $guest_id ) ) );
}

function at_gathering_roster_guest_by_user( $user_id ) {
	global $wpdb;
	return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . at_gathering_roster_table() . ' WHERE user_id = %d', absint( $user_id ) ) );
}

function at_gathering_roster_guest_by_email( $email ) {
	global $wpdb;
	return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . at_gathering_roster_table() . ' WHERE email_normalized = %s', at_gathering_normalize_email( $email ) ) );
}

function at_gathering_roster_choices() {
	global $wpdb;
	return $wpdb->get_results( 'SELECT id, display_name FROM ' . at_gathering_roster_table() . " WHERE claim_state = 'claimed' ORDER BY display_name ASC, id ASC" );
}

function at_gathering_party_assignment_for_guest( $guest_id ) {
	global $wpdb;
	return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . at_gathering_assignments_table() . ' WHERE guest_id = %d', absint( $guest_id ) ) );
}

/**
 * Atomically replace all adult assignments for one party. Pass a callback to
 * persist RSVP fields in the same transaction. `$guest_ids` must include the
 * linked owner; callbacks receive no arguments and must return true or WP_Error.
 */
function at_gathering_replace_party_assignments( $rsvp_user_id, $guest_ids, $write_callback = null ) {
	global $wpdb;
	$rsvp_user_id = absint( $rsvp_user_id );
	$guest_ids = array_values( array_unique( array_filter( array_map( 'absint', (array) $guest_ids ) ) ) );
	if ( ! function_exists( 'at_gathering_party_rsvps_enabled' ) || ! at_gathering_party_rsvps_enabled() ) {
		return new WP_Error( 'at_party_reconciliation_required', 'Hosts must review existing party names before party RSVPs can be saved.' );
	}
	$owner = at_gathering_roster_guest_by_user( $rsvp_user_id );
	if ( ! $owner || ! in_array( (int) $owner->id, $guest_ids, true ) ) {
		return new WP_Error( 'at_party_owner_required', 'The RSVP owner must be a linked roster guest and included in the party.' );
	}
	if ( $write_callback && ! is_callable( $write_callback ) ) {
		return new WP_Error( 'at_invalid_write_callback', 'The RSVP write callback must be callable.' );
	}
	foreach ( $guest_ids as $guest_id ) {
		if ( ! at_gathering_roster_guest( $guest_id ) ) {
			return new WP_Error( 'at_roster_guest_not_found', 'One of the selected guests is no longer on the roster.' );
		}
	}
	return at_gathering_transaction(
		function () use ( $wpdb, $rsvp_user_id, $guest_ids, $owner, $write_callback ) {
			$table = at_gathering_assignments_table();
			foreach ( $guest_ids as $guest_id ) {
				$conflict = $wpdb->get_row( $wpdb->prepare( "SELECT rsvp_user_id FROM {$table} WHERE guest_id = %d AND rsvp_user_id <> %d LIMIT 1", $guest_id, $rsvp_user_id ) );
				if ( $conflict ) {
					return new WP_Error( 'at_party_assignment_conflict', 'A selected guest is already assigned to another party and needs host resolution.', array( 'guest_id' => $guest_id, 'rsvp_user_id' => (int) $conflict->rsvp_user_id ) );
				}
			}
			if ( false === $wpdb->delete( $table, array( 'rsvp_user_id' => $rsvp_user_id ), array( '%d' ) ) ) {
				return new WP_Error( 'at_party_assignment_write_failed', 'The existing party assignments could not be replaced.' );
			}
			foreach ( $guest_ids as $guest_id ) {
				$inserted = $wpdb->insert( $table, array( 'rsvp_user_id' => $rsvp_user_id, 'guest_id' => $guest_id, 'is_owner' => (int) $owner->id === $guest_id ? 1 : 0, 'created_at' => current_time( 'mysql', true ) ), array( '%d', '%d', '%d', '%s' ) );
				if ( false === $inserted ) {
					return new WP_Error( 'at_party_assignment_conflict', 'A selected guest was assigned to another party while this RSVP was being saved.', array( 'guest_id' => $guest_id ) );
				}
			}
			if ( $write_callback ) {
				$result = call_user_func( $write_callback );
				if ( is_wp_error( $result ) || true !== $result ) {
					return is_wp_error( $result ) ? $result : new WP_Error( 'at_party_write_failed', 'The RSVP details could not be saved.' );
				}
			}
			return true;
		}
	);
}

/** Run a callback atomically, returning its value or a stable transaction error. */
function at_gathering_transaction( $callback ) {
	global $wpdb;
	if ( ! is_callable( $callback ) || false === $wpdb->query( 'START TRANSACTION' ) ) {
		return new WP_Error( 'at_transaction_unavailable', 'The database could not start a transaction.' );
	}
	try {
		$result = call_user_func( $callback );
		if ( is_wp_error( $result ) || false === $wpdb->query( 'COMMIT' ) ) {
			$wpdb->query( 'ROLLBACK' );
			return is_wp_error( $result ) ? $result : new WP_Error( 'at_transaction_unavailable', 'The database could not commit this change.' );
		}
		return $result;
	} catch ( Throwable $error ) {
		$wpdb->query( 'ROLLBACK' );
		return new WP_Error( 'at_transaction_unavailable', 'The database change was rolled back.' );
	}
}

/** Host-confirmed migration link for a legacy RSVP owner; never alters the WP account. */
function at_gathering_link_legacy_rsvp_owner( $user_id, $guest_id, $confirmed_email ) {
	global $wpdb;
	$user_id = absint( $user_id );
	$guest_id = absint( $guest_id );
	$user = get_user_by( 'id', $user_id );
	$guest = at_gathering_roster_guest( $guest_id );
	if ( ! current_user_can( 'manage_options' ) ) {
		return new WP_Error( 'at_host_required', 'A host must confirm this owner link.' );
	}
	if ( ! $user || ! $guest || ! $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . at_gathering_table() . ' WHERE user_id = %d', $user_id ) ) ) {
		return new WP_Error( 'at_legacy_owner_not_found', 'The existing RSVP owner or roster guest was not found.' );
	}
	$confirmed = at_gathering_normalize_email( $confirmed_email );
	if ( ! $confirmed || $confirmed !== at_gathering_normalize_email( $user->user_email ) || $confirmed !== $guest->email_normalized ) {
		return new WP_Error( 'at_owner_email_mismatch', 'The confirmed email must exactly match the existing account and roster entry.' );
	}
	if ( $guest->user_id && (int) $guest->user_id !== $user_id ) {
		return new WP_Error( 'at_owner_link_conflict', 'This roster guest is already linked to another account.' );
	}
	return at_gathering_transaction(
		function () use ( $wpdb, $user_id, $guest_id, $guest ) {
			$existing = at_gathering_roster_guest_by_user( $user_id );
			if ( $existing && (int) $existing->id !== $guest_id ) {
				return new WP_Error( 'at_owner_link_conflict', 'This account is already linked to another roster guest.' );
			}
			$wpdb->query( $wpdb->prepare( 'UPDATE ' . at_gathering_roster_table() . ' SET user_id = %d, claim_state = %s, claimed_at = %s, updated_at = %s WHERE id = %d AND (user_id IS NULL OR user_id = %d)', $user_id, 'claimed', current_time( 'mysql', true ), current_time( 'mysql', true ), $guest_id, $user_id ) );
			if ( $wpdb->last_error ) {
				return new WP_Error( 'at_owner_link_conflict', 'This roster guest is already linked to another account.' );
			}
			$linked_guest = at_gathering_roster_guest( $guest_id );
			if ( ! $linked_guest || (int) $linked_guest->user_id !== $user_id || $linked_guest->email_normalized !== $guest->email_normalized ) {
				return new WP_Error( 'at_owner_link_conflict', 'This roster guest changed while the owner link was being saved.' );
			}
			$inserted = $wpdb->insert( at_gathering_assignments_table(), array( 'rsvp_user_id' => $user_id, 'guest_id' => $guest_id, 'is_owner' => 1, 'created_at' => current_time( 'mysql', true ) ), array( '%d', '%d', '%d', '%s' ) );
			if ( false === $inserted ) {
				return new WP_Error( 'at_owner_link_conflict', 'This guest already has a party assignment that needs host resolution.' );
			}
			return true;
		}
	);
}

function at_gathering_migrate_content() {
	// Seed the confirmed menu once; later upgrades preserve host-added dishes.
	if ( false === get_option( 'at_gathering_foods', false ) ) {
		update_option( 'at_gathering_foods', at_gathering_default_foods() );
	}
	if ( false === get_option( 'at_gathering_event_details', false ) ) {
		update_option( 'at_gathering_event_details', array() );
	}
	if ( false === get_option( 'at_gathering_invite_key', false ) ) {
		update_option( 'at_gathering_invite_key', wp_generate_password( 32, false, false ) );
	}
	foreach ( get_users( array( 'fields' => 'ID' ) ) as $user_id ) {
		at_gathering_assign_avatar( $user_id );
	}
	$page = get_page_by_path( 'rsvp' );
	if ( $page && '[at_rsvp]' !== trim( $page->post_content ) ) {
		wp_update_post( array( 'ID' => $page->ID, 'post_content' => '[at_rsvp]' ) );
	}
	$signup_page = get_page_by_path( 'signup' );
	if ( ! $signup_page ) {
		wp_insert_post(
			array(
				'post_title'   => 'Sign up',
				'post_name'    => 'signup',
				'post_content' => '[at_signup]',
				'post_status'  => 'publish',
				'post_type'    => 'page',
			)
		);
	} elseif ( false === strpos( (string) $signup_page->post_content, '[at_signup]' ) ) {
		wp_update_post( array( 'ID' => $signup_page->ID, 'post_content' => '[at_signup]' ) );
	}
	$confirmation_page = get_page_by_path( 'rsvp-confirmation' );
	if ( ! $confirmation_page ) {
		wp_insert_post(
			array(
				'post_title'   => 'RSVP confirmation',
				'post_name'    => 'rsvp-confirmation',
				'post_content' => '[at_rsvp_confirmation]',
				'post_status'  => 'publish',
				'post_type'    => 'page',
			)
		);
	} elseif ( false === strpos( (string) $confirmation_page->post_content, '[at_rsvp_confirmation]' ) ) {
		wp_update_post( array( 'ID' => $confirmation_page->ID, 'post_content' => '[at_rsvp_confirmation]' ) );
	}
}
