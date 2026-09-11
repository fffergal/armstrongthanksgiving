# Armstrong Thanksgiving

This repository contains the custom WordPress theme, reproducible local environment, plugin inventory, and browser-based acceptance tests. The production database, uploads, caches, and secrets are backed up separately and are not stored in Git.

## Local setup

Requirements: Docker Desktop, Node.js 22+, and npm.

```sh
cp .env.example .env
npm install
npx playwright install chromium
npx playwright install webkit
npm run wp:start
npm run wp:setup
```

Open `http://localhost:8888`. The bootstrap creates a local `guest` / `password` subscriber for the member journey; override `WP_TEST_USER` and `WP_TEST_PASSWORD` in `.env` when needed.

The start wrapper stages the theme and must-use plugin in Docker Desktop's shared temporary directory, starts the stock WordPress volume, then copies the theme and cached community plugins into that volume. This avoids macOS Docker file-sharing and image-initialiser issues for projects stored in `Documents`; restart the environment after theme or mu-plugin changes so the mirror is refreshed. Plugin archives remain in wp-env's cache rather than Git.

## Test layers

- `npm test`: Chromium desktop and mobile-Safari-emulated privacy checks, passwordless-login request states, plus authenticated member, forum, album, RSVP, food, and memories journeys, with trace/video/screenshots retained on failure.
- `npm run test:visual`: screenshot regression checks. Establish intentional baselines with `npm run test:update-snapshots`.
- `npm run test:a11y`: axe automated accessibility checks.
- `npm run test:performance`: current Lighthouse engine, run three times with median budgets for LCP, layout shift, blocking time, accessibility, and best practices. Raw reports are retained locally.
- `npm run test:all`: browser acceptance followed by Lighthouse budgets.

Synthetic tests catch regressions before deployment. Once production exists, the same read-only suite can target it through `BASE_URL`. Real-user Web Vitals collection and geographic synthetic runs will be added only after the privacy implications and retention policy are agreed; neither should collect visitor identity or private page contents.

The local benchmark covers the anonymous redirect/login experience and runs three times with median budgets. The current themed login median is comfortably below the 2.5-second LCP budget after removing album and admin assets that do not belong on a friend sign-in page.

`@wordpress/env` is development-only. Its current upstream dependency tree has moderate advisories in archive extraction and an optional preview server; it must never be installed or exposed on production. We track upstream releases and audit upgrades, but do not downgrade to the older release npm suggests because that version has more severe known issues.

## Required production checks

The suite will grow with the site and cover account claiming, password/passwordless login, access control, forum posting and subscriptions, album creation/upload, responsive rendering, keyboard navigation, email delivery, direct media access, caching headers, and plugin-update compatibility.

See [plugin evaluation](docs/plugin-evaluation.md) for the theming and acceptance gates.

See [email and friend onboarding](docs/email-onboarding.md) for the production mail settings,
invite flow, deliverability follow-up, and the evidence needed to confirm a real invitation.
