# Guest roster and party RSVP foundation contract

The plugin bootstrap loads the shared schema owner and then the invitation and
party RSVP modules. Keep feature behavior in those feature modules. Schema
creation and upgrades belong only to `includes/schema.php`; do not add tables
or columns from a feature module.

## Schema

- `{$wpdb->prefix}at_rsvps` keeps the legacy `guest_count` and `guest_names`
  columns and gains `children_count` (unsigned, non-negative, default `0`).
- `{$wpdb->prefix}at_invited_adults` stores a stable roster ID, unique
  normalized email, display name, optional unique WordPress user ID, claim
  state, hashed setup token and expiry, invitation/claim timestamps, and
  creation/update timestamps.
- `{$wpdb->prefix}at_party_assignments` stores `(rsvp_user_id, guest_id,
  is_owner)`. `guest_id` is unique across parties, so the owner and every
  selected adult use the same one-party-only assignment relation. An owner
  has `is_owner = 1`; all other party members have `0`.
- The RSVP, roster, and assignment tables use InnoDB so a party update can
  commit its RSVP row and assignments together. Database uniqueness remains
  the final guard against concurrent party assignment writes.

Schema upgrades are repeatable through `at_gathering_ensure_schema()` and
`at_gathering_maybe_upgrade()`. Existing RSVP owner accounts are not inferred
into the roster. The host-only
`at_gathering_link_legacy_rsvp_owner( $user_id, $guest_id, $confirmed_email )`
links an existing RSVP owner only when the host's confirmed address normalizes
to the exact same email on both the WordPress account and roster guest. It
does not change the account password or role and creates the owner's
assignment in one transaction. It returns `true` or `WP_Error`.

## Read and write APIs

- `at_gathering_normalize_email( $email )` lowercases and trims a sanitized
  email.
- `at_gathering_roster_guest( $guest_id )`,
  `at_gathering_roster_guest_by_user( $user_id )`, and
  `at_gathering_roster_guest_by_email( $email )` return one row or `null`.
- `at_gathering_roster_choices()` returns only `id` and `display_name` for
  claimed guests; callers must authorize before exposing the choices.
- `at_gathering_party_assignment_for_guest( $guest_id )` returns its
  assignment row or `null`.
- `at_gathering_replace_party_assignments( $rsvp_user_id, $guest_ids,
  $write_callback = null )` replaces the full party in one transaction. The
  owner must be a linked roster guest and appear in `$guest_ids`. The optional
  callback saves the RSVP row in the same transaction and must return `true`
  or `WP_Error`. The function returns `true` or `WP_Error`; it never leaves a
  partially replaced set.
- `at_gathering_transaction( $callback )` is the shared transaction wrapper
  for related roster/account writes. Its callback returns a value or
  `WP_Error`; errors and exceptions roll back.
- `at_gathering_party_rsvps_enabled()` is the reconciliation gate. New
  roster-based party saves must reject writes until a host has reviewed the
  legacy list and confirmed. The review stores a fingerprint of the legacy
  party names; any later edit to that list reopens the gate.

Stable error codes currently include `at_transaction_unavailable`,
`at_party_reconciliation_required`, `at_party_owner_required`,
`at_roster_guest_not_found`, `at_party_assignment_conflict`,
`at_party_assignment_write_failed`, `at_party_write_failed`,
`at_invalid_write_callback`, `at_owner_email_mismatch`, `at_owner_link_conflict`,
`at_legacy_owner_not_found`, and `at_host_required`. Conflict errors include
the conflicting `guest_id` where available. Callers should show a useful
host-resolution message without exposing invitee email addresses.

## Feature integration points

- Invitation registration is isolated in `includes/invitations.php` and
  subscribes to `at_gathering_register_invitations` on `init` for route/hook
  registration. Its host content may render through
  `at_gathering_admin_page_sections`.
- Party RSVP registration is isolated in `includes/party-rsvps.php` and
  subscribes to `at_gathering_register_party_rsvps` on `init`. Its host content
  uses the same `at_gathering_admin_page_sections` action.
- Both registration actions run at `init` priority `1`, after all plugin
  modules are loaded. Admin section callbacks run inside the Gathering RSVPs
  host page and must enforce the host capability for writes.

The existing open-signup and legacy RSVP behavior is kept in its extracted
module during this foundation step. Later feature work replaces those paths;
this foundation adds no new email sends, and existing RSVP confirmation
behavior remains.
