# Email and friend onboarding

This site uses the DreamHost mailbox `turkeyteam@armstrongthanksgiving.com` for
WordPress mail. Secrets stay in WordPress' SMTP settings and are never stored
in this repository.

## Mail transport

The production WP Mail SMTP settings are:

- Mailer: Other SMTP
- Host: `smtp.dreamhost.com`
- Encryption: TLS
- Port: `587`
- Authentication: enabled
- Username: `turkeyteam@armstrongthanksgiving.com`
- From address/name: `turkeyteam@armstrongthanksgiving.com` / `Armstrong Thanksgiving`
- Return-Path: enabled

The theme wraps ordinary WordPress HTML and plain-text messages in the
flyer-inspired email layout. Plugin updates must be tested locally before they
are promoted, because a plugin can supply its own content type or markup.

## Onboarding flow

1. Create the friend as a WordPress Subscriber and bbPress Participant, using
   the email address they will use to sign in.
2. Send the friend the normal WordPress account email or a separate note with
   the username and temporary password. The host does not need to explain a
   second login system.
3. The friend signs in at the site with that username/email and password. If
   needed, the standard “Lost your password?” link sends a reset email.
4. After signing in, the friend should check the private home page, forum,
   albums, RSVP, food, and forum links at both desktop and phone widths.

The first production test account is `anna` (`armstronganna1@gmail.com`). Do
not put a password or a one-time login token in Git, issue trackers, or chat.

## Deliverability checks

The WP Mail SMTP test to the test address completed without a recorded sending
error, and the domain's SPF check passed. The plugin reports that no DMARC
record is currently detected. Add a DMARC record in the DNS provider once the
mailbox is confirmed, starting with a monitoring policy such as:

```text
Host: _dmarc
Type: TXT
Value: v=DMARC1; p=none; rua=mailto:turkeyteam@armstrongthanksgiving.com
```

Review aggregate reports before considering `quarantine` or `reject`. Do not
change the policy as part of a routine plugin deployment.

Before sending a broad invitation, manually open one password email and one RSVP
confirmation in Gmail and iOS Mail at a narrow width. Check that the 560px table
stays within the viewport, long links wrap without horizontal scrolling, and the
cream/brown palette remains legible in dark mode. Check Outlook too if it is used
by any of the guest list; the HTML uses presentation tables for that reason.

## Acceptance evidence

Automated local tests cover the login request state, access control, responsive
private routes, accessibility, visual baselines, and the member journey. A
production invite is only fully verified when the recipient confirms that the
branded message arrived and its link opens the private site; the token itself
must not be copied into this repository or chat.
