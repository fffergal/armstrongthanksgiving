# Guest invitations and party RSVPs

## Intention

Replace open account creation and ambiguous free-text group RSVPs with an
invitation-based guest list and explicit party assignments.

Hosts know the adult guests in advance and supply their email addresses. Each
adult claims their own account from an invitation and chooses their own
password. After that, sign-in uses the normal password form; emailed links are
for initial setup and password recovery only.

An RSVP belongs to one account and names the other invited adults attending
with that person. Children are represented by a count and do not get accounts.
An adult included in another person's RSVP may still claim an account and use
the private site, but cannot create a second RSVP while assigned to that party.

## Overview

The work has two connected features:

1. **Invitations and account claiming:** hosts manage the known adult guest
   roster; an invited person verifies their email, chooses a password, and
   receives a WordPress/bbPress member account.
2. **Party RSVPs:** a signed-in guest records their own attendance, selects
   other known adults in their party, and enters the number of children. The
   server enforces one party assignment per adult.

The guest roster is the shared identity source. Build and integrate it before
the party RSVP feature depends on it. Keep account access separate from
attendance: an account does not automatically create an RSVP, and being listed
on another person's RSVP does not remove site access.

## Current implementation

- `wp-content/plugins/armstrong-gathering/armstrong-gathering.php` contains the
  RSVP shortcode and save handler, public account creation, standalone signup,
  mail, and most host admin UI. It is currently a large shared file, so two
  sessions should not edit it concurrently.
- RSVP rows are keyed by the account that submitted them. `guest_count` and
  `guest_names` are free-form group fields; neither identifies attendees or
  prevents duplicates.
- Both the RSVP form and `/signup/` currently let anyone create an account by
  entering a display name, email, and password.
- `wp-content/themes/armstrong-thanksgiving/functions.php` supplies the
  branded email wrapper and some login copy. The plugin supplies the
  invitation/RSVP message content and recipient.
- `wp-content/plugins/armstrong-gathering/assets/gathering.css` styles the
  front-end forms. RSVP acceptance tests are mainly in
  `tests/e2e/rsvp.spec.ts`.
- `docs/email-onboarding.md` describes the current open-signup/password flow
  and production mail checks.

## Product and technical decisions

Treat these as the implementation contract unless the owner changes them:

- Hosts supply an adult's name and email. Normalize email consistently and
  enforce one roster entry per address.
- Invitation links are random, single-use, expiring setup tokens. Store only a
  token hash. The claim form verifies the address, collects the display name
  if needed, and lets the guest set a password.
- An account is created or linked only after successful invitation claim.
  Preserve Subscriber and bbPress Participant behavior. Existing accounts
  must be linked safely after email ownership is proven; never create a
  duplicate account.
- Normal login stays password-based. Keep WordPress password recovery for
  forgotten passwords; do not send routine sign-in links.
- Every RSVP has one RSVP owner and zero or more other adult invitees. The
  owner is an attendee by definition. The RSVP status applies to the whole
  party. Children are a non-negative integer count and have no guest records.
- The owner and every other adult in a party are represented by roster IDs in
  the same assignment relation. Each adult ID has at most one assignment.
  An adult included in another party cannot submit a separate RSVP, but can
  still claim an account and use member pages.
- An assignment remains reserved for every saved RSVP status (`yes`, `maybe`,
  or `no`). Changing status does not free adults for another RSVP. Removing an
  adult from the party or removing the RSVP releases the assignment. A `no`
  response has zero children. The host has an atomic move/release action for
  correcting assignments. A guest who owns an RSVP must have it removed before
  another RSVP can include them.
- Update assignments atomically so concurrent submissions cannot put one
  adult in two parties. If any adult is already assigned, reject the entire
  party update and explain which assignment needs host resolution; do not save
  a partial party.
- Keep invitation eligibility distinct from RSVP participation. An invited
  adult may claim an account without RSVPing or may be included in another
  person's RSVP.
- Close every public account-creation path, including the anonymous RSVP POST
  and standalone `/signup/` form/handler. Uninvited visitors cannot create an
  account through a direct POST.
- On claim, a new guest sets a password. If the verified email already belongs
  to a WordPress account, link that account to the roster entry without
  changing its password or privileges; the guest uses normal sign-in or
  WordPress password recovery. Reject conflicting roster/user links for host
  resolution. Never lower or grant capabilities as a side effect of claiming.
- Existing RSVP owners are a migration exception to self-service claim:
  hosts may explicitly link a legacy RSVP owner to a roster entry by the
  existing RSVP's WordPress user ID and an exact normalized email match.
  Require host review/confirmation, reject conflicts, and do not change the
  account password or capabilities. This exception does not auto-map
  free-text party names or claim an invitation for a non-owner.
- Claim-token consumption, account creation/linking, resend invalidation, and
  party-assignment replacement must be atomic. A simultaneous claim or
  assignment request must have only one winner.
- RSVP guest selectors return display names and stable IDs only. Do not expose
  invitee email addresses to other guests.
- Do not silently guess which roster guests correspond to legacy free-text
  names. Preserve the old names/count for host reconciliation during rollout.

### Suggested data shape

The precise SQL can follow WordPress conventions, but preserve these
relationships and invariants:

- **Invited adult:** stable ID, normalized unique email, display name,
  optional linked WordPress user ID (unique when present), invitation/claim
  timestamps, hashed token and expiry while a claim is pending.
- **RSVP:** existing owner account and attendance/details, plus
  `children_count`. Derive total attendees from the owner, assigned adult
  guests, and children rather than trusting a user-entered total.
- **Party assignment:** RSVP owner/account ID plus invited adult ID, with a
  unique constraint on adult ID. Include the owner in this relation so an
  RSVP owner cannot also be assigned to another party.
- **Migration:** retain legacy `guest_names` and `guest_count` until the host
  has reviewed existing rows and assigned known adults. Add a clear admin
  reconciliation path or report; do not turn free text into account links
  automatically.

Prefer dedicated tables for the roster and party assignments over serialized
arrays in WordPress options: uniqueness, lookup, claim state, and atomic party
updates need database enforcement.

## Ordered work

### 0. Foundation: split shared code and lock the contracts

Do this first in one session. Extract the plugin bootstrap/shared schema and
the feature modules so later work can own separate files. Keep behavior
unchanged in this step and run the existing focused checks. Establish shared
helpers for roster lookup, RSVP ownership/assignments, and admin-page section
registration. Put schema upgrades in one owned module; invitation and RSVP
modules must not independently edit schema/bootstrap files.

The foundation owns the additive schema needed by both features: invited adult
records, unique adult-to-party assignments (including owners), claim state,
and the RSVP child count. It should retain legacy RSVP name/count fields for
host reconciliation. Define concrete read/write helper signatures, error
results, transaction boundaries, and admin/shortcode registration hooks in
code comments or a short contract resource before parallel sessions start;
feature sessions consume this contract rather than adding competing tables
or columns. The foundation author remains the integration owner for shared
schema/bootstrap changes after parallel work begins.

Suggested boundaries:

- `armstrong-gathering.php`: constants, bootstrap, shared hooks.
- `includes/schema.php`: schema creation/upgrades and legacy compatibility.
- `includes/invitations.php`: guest roster, invitation claim, account setup,
  standalone `/signup/` replacement, account-creation APIs, and invitation
  admin section. It exposes the claim/setup interface used by RSVP handoffs.
- `includes/party-rsvps.php`: RSVP form/save, party assignments, child count,
  confirmation content, and RSVP admin section.
- Keep CSS, theme email wrapper, tests, and docs in their current separate
  files.

Before enabling the new party RSVP flow on a database with existing RSVPs,
provide a host review/reconcile path. Owners may be linked by their existing
RSVP user ID and exact normalized email after host confirmation. Hosts must
review legacy party names and assign known adults explicitly; no free-text
name is auto-mapped. Do not accept new assignments while an existing party
remains unreconciled; otherwise an old free-text guest could silently be
assigned twice. Include an upgrade fixture with existing users and RSVP rows,
and make upgrades safe to run repeatedly.

The exact names can change, but retain the ownership separation. If extraction
would be unusually disruptive, do not parallelize plugin implementation:
complete the invitation and RSVP PHP work sequentially in the shared file.

**Session prompt (copy or reference this document):**

> Read `docs/guest-invitations-and-party-rsvps-plan.md` and implement work
> section 0, “Foundation: split shared code and lock the contracts.” First
> inspect the current plugin and tests. Extract modules with no intended
> behavior changes, centralize schema upgrades, and add the agreed roster,
> unique party assignment including RSVP owners, claim state, and child-count
> schema while preserving legacy RSVP fields. Establish separate invitation
> and party-RSVP integration points and publish concrete read/write APIs,
> errors, and transaction boundaries. Keep feature logic out of bootstrap/
> schema owner files. Add repeatable upgrade coverage for existing
> accounts/RSVPs, including a host-confirmed owner link by existing user ID
> and exact normalized email. Add a host reconciliation gate for legacy party
> names before new party saves are enabled. Run relevant existing tests, fix
> regressions, and report resulting schema, ownership, and interfaces. Do not
> send real email.

### 1. Parallel implementation after section 0 is integrated

Start these sessions from a base that includes section 0. Give each session
exclusive ownership of its listed files. Do not have two sessions edit the
bootstrap, schema, or another session's module. If section 0 does not create
the suggested boundaries, run the invitation and party-RSVP work sequentially
instead. The section 0 author owns changes to shared schema/bootstrap files;
feature sessions report the needed contract change for that owner to integrate.

#### 1A. Guest roster, invitations, and password setup

Own `includes/invitations.php` and invitation-specific tests in a new test
file. Implement host management for invited adults, invitation send/resend,
single-use expiring email verification, password setup, account linking, and
anti-enumeration/rate-limit behavior. Atomically consume a token while
creating/linking an account; resend invalidates the previous token. For an
existing user with the same verified email, link the roster record without
changing the password or capabilities. Replace open standalone `/signup/`
with invitation setup, including its direct POST handler. Own all
new-account creation logic and expose the agreed claim/setup API for RSVP
handoffs. Do not edit the RSVP save handler; section 1B removes its inline
anonymous account creation. Return generic request responses that do not
disclose whether an address is invited. Register through shared interfaces.
Do not change schema or party assignment rules; request shared changes from
the foundation/integration owner. Do not send real email.

**Short referring prompt:**

> Read `docs/guest-invitations-and-party-rsvps-plan.md`; implement section 1A,
> “Guest roster, invitations, and password setup.” Own only
> `includes/invitations.php` and a new invitation E2E test file. Follow the
> section 0 APIs. Replace `/signup/` form/direct POST registration with
> invitation setup; own all new-account creation and expose the shared claim
> API used by RSVP handoffs. Do not edit the RSVP save handler; section 1B
> removes its inline account creation. Use single-use expiring setup links,
> atomically link verified email, leave existing passwords/capabilities
> unchanged, and keep future login password-based. Generic responses must not
> reveal invite membership. Run focused checks; report shared-contract changes
> for the integration owner. Do not send real email.

#### 1B. Party RSVPs and duplicate prevention

Own `includes/party-rsvps.php` and party-specific tests in a new test file.
Represent the owner and selected other adults in the same unique assignment
relation. Implement children count, RSVP edits, confirmation details, host
visibility/correction, and server/database enforcement that each adult is
assigned once. Remove inline anonymous account creation from the RSVP save
handler; require a claimed account and hand unclaimed guests to section 1A's
claim/setup API while preserving their RSVP draft. Saved `yes`, `maybe`, and
`no` responses reserve assignments;
removing an adult or RSVP releases them. A `no` response has zero children.
Reject the whole update on an assignment conflict. An included adult's own
account remains usable while their separate RSVP is blocked. Preserve legacy
names/count; do not auto-map them. Do not accept new party RSVPs until existing
rows are reconciled. Do not send real email.

**Short referring prompt:**

> Read `docs/guest-invitations-and-party-rsvps-plan.md`; implement section 1B,
> “Party RSVPs and duplicate prevention.” Own only
> `includes/party-rsvps.php` and a new party-RSVP E2E test file. Use the shared
> guest/schema APIs from section 0. Remove inline anonymous account creation
> from the RSVP save handler and hand unclaimed guests to the 1A claim/setup
> API with the RSVP draft preserved. Include RSVP owners in the unique adult
> assignment relation. Keep assignments reserved for every saved status;
> release only when removed, and set children to zero for `no`. Reject the
> entire save on conflict. Preserve and reconcile legacy RSVP data before
> accepting new party RSVPs. Run focused checks and report shared-contract
> needs for the integration owner. Do not send real email.

#### 1C. Form styling and responsive states

Own `wp-content/plugins/armstrong-gathering/assets/gathering.css`. Style the
new invited-adult selector, assignment notice, children count, and claim/setup
forms after sections 1A/1B publish their markup/classes. Check narrow layouts,
focus states, labels, and error/success states. Do not change PHP markup or
behavior.

**Short referring prompt:**

> Read `docs/guest-invitations-and-party-rsvps-plan.md`; implement section 1C,
> “Form styling and responsive states.” Own only
> `wp-content/plugins/armstrong-gathering/assets/gathering.css`. Coordinate
> against the integrated invitation and party-RSVP markup, and cover desktop,
> tablet, and phone widths, keyboard focus, and validation states. Use only
> guest names/stable IDs in selectors; do not expose invitee email addresses.
> Do not edit PHP or tests or send real email.

#### 1D. Onboarding and operator documentation

Own `docs/email-onboarding.md` and relevant README copy. Document invitation
management, account claim, password recovery, party RSVP assignments, child
counts, and how to resolve a duplicate or mistaken assignment. Keep production
email verification instructions and the rule that real sample/invitation mail
must only be sent when requested. This can start in parallel as a draft; do a
final accuracy pass after sections 1A/1B are integrated.

**Short referring prompt:**

> Read `docs/guest-invitations-and-party-rsvps-plan.md`; implement section 1D,
> “Onboarding and operator documentation.” Own `docs/email-onboarding.md` and
> relevant README copy. Describe the specified behavior: invited adults set
> their own password once; future login is password-based; RSVPs list adult
> party members; children are counted; an included adult can use their account
> but cannot RSVP twice. Include invitation administration, legacy RSVP
> reconciliation, and production mail procedure. Draft in parallel, then
> verify against integrated behavior. Do not change application code or send
> real email.

#### 1E. Login-page guidance

Own `wp-content/themes/armstrong-thanksgiving/functions.php`. Update the
WordPress login message and any theme-owned account guidance so it points new
friends to the invitation/setup route rather than open signup. Preserve the
existing password login, lost-password link, and branded email wrapper. Do not
change plugin behavior or email message content.

**Short referring prompt:**

> Read `docs/guest-invitations-and-party-rsvps-plan.md`; implement section 1E,
> “Login-page guidance.” Own only
> `wp-content/themes/armstrong-thanksgiving/functions.php`. Replace stale
> open-signup guidance with the integrated invitation/setup route, while
> keeping regular password sign-in, password recovery, and the existing email
> wrapper intact. Do not edit the plugin/docs or send real email.

### 2. Integrate, reconcile, and verify

Run after 1A and 1B are integrated; include 1C/1D/1E before final review.

- Review schema upgrades against the current RSVP table and existing accounts.
- Update existing `tests/e2e/rsvp.spec.ts` assumptions about public signup and
  free-text party names, and reconcile shared fixtures. Keep new
  invitation/party test files owned by 1A/1B. Use the repository's scoped
  `npm run wp:*` wrappers for local environments; concurrency coverage must
  issue genuinely overlapping assignment requests.
- Verify a roster entry can claim only the account for its verified address;
  expired, reused, resent, and invalid tokens behave safely.
- Verify a guest can claim an account while assigned to a party, access member
  pages, and cannot save another RSVP until unassigned.
- Verify adult assignments remain unique on create, edit, removal, and
  concurrent/duplicate submissions; host correction releases the old
  assignment.
- Verify child counts and derived totals in the RSVP list and confirmation
  email.
- Reconcile legacy free-text party names with the host; retain them until that
  review is complete.
- Confirm the app blocks new party submissions until existing owner/party
  records have been reconciled, and that the reconciliation state survives
  repeatable upgrades and later RSVP edits.
- Run focused RSVP/invitation tests, broader project checks, accessibility
  checks, and responsive visual checks. For visible changes, capture fresh
  local screenshots at desktop, tablet, and phone widths using the internal
  browser and the worktree runtime URL.
- Update any production rollout steps only after the local flow is verified.
  A real invitation or sample email is an external send and requires an
  explicit request at the point of sending.

**Integration prompt:**

> Read `docs/guest-invitations-and-party-rsvps-plan.md`; integrate and verify
> sections 1A–1E. Review the combined schema, account-claim and
> party-assignment behavior; update existing `tests/e2e/rsvp.spec.ts` and
> shared fixtures for the removed open-signup/free-text flows; resolve
> integration conflicts; and run focused and broader repository checks using
> scoped WordPress wrappers. Include repeatable upgrade/reconciliation
> coverage and genuinely overlapping assignment submissions. Capture fresh
> desktop, tablet, and phone screenshots of the local result. Do not send real
> email. Report results, migration needs, and any remaining product decision.

## Suggested session order at a glance

```text
0. Foundation and contracts
   └─ integrate
      ├─ 1A. Invitations and account setup ─┐
      ├─ 1B. Party RSVPs ──────────────────┤
      ├─ 1C. CSS (after markup contracts) ─┤─ 2. Integrate and verify
      ├─ 1D. Operator docs ────────────────┤
      └─ 1E. Login-page guidance ──────────┘
```

Parallel sessions should use separate worktrees/branches. Integrate section 0
before starting 1A/1B so every worktree sees the same module boundaries and
schema contract. If separate modules are not established, keep 1A and 1B
sequential because their code currently converges on the same plugin file.
