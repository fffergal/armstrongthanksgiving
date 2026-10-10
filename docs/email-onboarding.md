# Email and friend onboarding

WordPress mail uses the production mailbox `turkeyteam@armstrongthanksgiving.com`.
SMTP secrets stay in WordPress settings and are never stored in this repository.

## Rollout status

This guide records the guest invitation and party-RSVP behavior specified in
the guest invitation plan. In this checkout, the foundation schema is present,
but invitation claiming and roster-based RSVP editing have not been integrated.
At present, `/signup/` and the RSVP form still support the earlier open-signup
and free-text party-name flow. The host guest-roster administration and party
assignment controls described below are not live yet. Complete and verify plan
sections 1A and 1B before using the planned workflow in production.

The **Gathering RSVPs → Legacy party reconciliation** panel is available, but
the RSVP save handler is not yet connected to its review gate. Owner linking
and party assignment UI also remain to be integrated; the existing review
confirmation does not match names or create assignments.

## Planned guest flow

1. Hosts add each adult's name and email to the guest roster and send an
   invitation. Each adult opens their own email invitation and claims their
   account. The invitation link is for initial setup, not routine sign-in.
2. Each invited adult sets their own password once. If the verified email
   already belongs to a WordPress account, link that account without changing
   its password or privileges. Future sign-in is password-based; a friend who
   forgets their password uses **Lost your password?** to request a reset.
3. One account submits the RSVP for one party. The account owner is an adult
   attendee; the form lists any other invited adult party members by name.
   Children are recorded as a count, without child accounts or adult roster
   entries.
4. An adult included in another party may still claim and use their own account
   for the private site, but cannot submit a second RSVP or be counted twice.
   Ask a host to correct a mistaken assignment. Changing an RSVP to “maybe” or
   “no” does not release its adult assignments; removing the RSVP does.
5. If an adult is already assigned to another party, reject the entire party
   update. A host resolves the conflict by moving or releasing that assignment
   before the guest retries.

## Invitation administration

Once invitation management is integrated, hosts will use **Gathering RSVPs**
to add adults, review claim state, and send or resend invitations. Resending
invalidates the previous setup link. Links are single-use and expire; if a link
expires or has been replaced, send a new invitation. If the address already
belongs to a WordPress account, the adult claims the roster entry while keeping
the existing password and privileges. Do not share a guest's password or copy
a setup token into Git, issue trackers, or chat.

Resolve duplicate roster email addresses before sending. Being on the roster
does not create an RSVP, and being included in a party does not prevent an
adult from using the private site.

## Legacy RSVP reconciliation

Older RSVP records contain free-text party names and a guest count. Hosts must
review the names and explicitly match known adults; never infer a roster
identity from a matching name string. Preserve the legacy text until review is
complete. Link an existing RSVP owner only after confirming that the WordPress
user ID and exact normalized email match the roster adult. This migration link
must leave the account password and capabilities unchanged. Resolve conflicting
links before confirming the review.

The current legacy review panel displays the preserved names and provides a
host confirmation gate, but its confirmation does not assign names to roster
adults and is not yet enforced by the RSVP save handler. The owner-link and
party-assignment controls must be integrated before hosts can complete those
mappings in the admin page. Once connected, review every legacy party, correct
its children count, resolve uncertain adults, and confirm the review before
enabling new roster-based party saves. If the legacy list changes during
review, reload it and review again.

## Production mail procedure

The production WP Mail SMTP configuration is:

- Mailer: Other SMTP
- Host: `smtp.dreamhost.com`
- Encryption: TLS, port `587`
- Authentication: enabled; username `turkeyteam@armstrongthanksgiving.com`
- From address/name: `turkeyteam@armstrongthanksgiving.com` /
  `Armstrong Thanksgiving`
- Return-Path: enabled

The theme wraps ordinary WordPress HTML and plain-text messages in the branded
email layout. Test the changed plugin/theme locally, then deploy the committed
component with the repository production deployment workflow and run
`npm run verify:production`. After deployment, purge WP Super Cache before
checking the public site. A successful SMTP handoff or WordPress success notice
does not establish inbox delivery.

Real invitation or sample-confirmation messages are external email actions;
send them only when requested. For an authorized production mail check, use one
intended recipient first. Once the invitation UI is integrated, use **Send
invitation** for that roster entry. For an RSVP message check, use **Gathering
RSVPs → Send a sample confirmation** and select the intended administrator
recipient; this sends the normal confirmation without creating or changing an
RSVP. Confirm the WordPress result and arrival in the recipient's inbox, then
inspect the link and message at a narrow viewport and in dark mode. Never paste
a one-time setup link or token into this repository or chat.

## Deliverability and message checks

The last recorded check found SPF passing and no DMARC record detected. Verify
DNS and the current provider state before making changes. If DMARC is still
absent, begin with a monitoring policy and review aggregate reports before
considering enforcement:

```text
Host: _dmarc
Type: TXT
Value: v=DMARC1; p=none; rua=mailto:turkeyteam@armstrongthanksgiving.com
```

For an authorized production mail check, inspect an invitation/setup message
and RSVP confirmation in Gmail and iOS Mail at a narrow width. Confirm that
content fits the viewport, long links wrap, and the cream/brown palette stays
legible in dark mode. Check Outlook too if a guest uses it. Automated local
tests can verify captured message content and headers, but cannot prove that
production mail reached an inbox.
