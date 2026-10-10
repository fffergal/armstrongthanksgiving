# Email and friend onboarding

WordPress mail uses the production mailbox `turkeyteam@armstrongthanksgiving.com`.
SMTP secrets stay in WordPress settings and are never stored in this repository.

## Rollout status

Invitation claiming, roster-based party RSVPs, and invitation guidance on the
login page are integrated on `main`. `/signup/` requests an invitation setup
link; it no longer creates an account through open registration. On an existing
site, guests cannot save roster-based party RSVPs until a host reviews the
legacy party records and confirms the reconciliation gate in **Gathering
RSVPs**. Confirm the code is deployed and complete that host review before
relying on the workflow in production.

## Guest flow

1. Hosts add each adult's name and email to the guest roster and send an
   invitation. Each adult opens their own email invitation and claims their
   account. The invitation link is for initial setup, not routine sign-in.
2. An invited adult who needs a new account sets their own password during the
   one-time setup. If the verified email already belongs to a WordPress account,
   link that account without changing its password or privileges. Future
   sign-in is password-based; a friend who forgets their password uses **Lost
   your password?** to request a reset.
3. One account submits the RSVP for one party. The account owner is an adult
   attendee; the form lists other claimed adult roster members by name.
   Children are recorded as a count, without child accounts or adult roster
   entries. A “no” RSVP has zero children.
4. An adult included in another party may still claim and use their own account
   for the private site, but cannot submit a second RSVP or be counted twice.
   Ask a host to correct a mistaken assignment. Changing an RSVP to “maybe” or
   “no” does not release its adult assignments; removing the RSVP does.
5. If an adult is already assigned to another party, reject the entire party
   update. A host can move an included adult's assignment. If that adult owns
   an RSVP, the host must remove that RSVP before assigning them to another
   party.

## Invitation administration

Hosts use **Gathering RSVPs → Guest invitations** to add adults, review claim
state, and send or resend invitations. **Save and send invitation** creates or
updates the roster entry and sends its setup link. **Resend** rotates the
single-use link, invalidating the previous one; links expire after two days. If
a link expires or has been replaced, resend the invitation. If the address
already belongs to a WordPress account, the adult claims the roster entry while
keeping the existing password and privileges. Do not share a guest's password
or copy a setup token into Git, issue trackers, or chat.

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

In **Gathering RSVPs → Legacy party reconciliation**, link each existing RSVP
owner to the correct roster adult only after confirming the existing WordPress
user ID and exact normalized email. Use that RSVP's party editor to assign
known adults explicitly; the RSVP owner is included automatically. The editor
does not infer identities from legacy names and does not edit the children
count. A legacy `guest_count` does not distinguish adults from children, so
after the host confirms the adult mappings and opens roster-based saves, ask
each linked RSVP owner to review and save the correct children count in their
RSVP. Until owners update those counts, legacy parties may be undercounted.

Confirm the host review only after checking every legacy party and resolving
uncertain or conflicting links. The confirmation enables roster-based party
saves and is tied to the current legacy-name snapshot; if that list changes,
reload it, review again, and reconfirm.

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
intended recipient first. In **Gathering RSVPs → Guest invitations**, use
**Save and send invitation** for a new roster entry or **Resend** for an
existing unclaimed guest. For an RSVP message check, use **Gathering RSVPs →
Send a sample confirmation** and select the intended administrator recipient;
this sends the normal confirmation without creating or changing an RSVP.
Confirm the WordPress result and arrival in the recipient's inbox, then inspect
the link and message at a narrow viewport and in dark mode. Never paste a
one-time setup link or token into this repository or chat.

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
