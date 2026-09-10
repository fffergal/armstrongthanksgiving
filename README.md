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

Open `http://localhost:8888`. The local WordPress defaults supplied by `wp-env` are username `admin` and password `password`; replace test credentials in `.env` after fixture users are added.

The start wrapper stages the theme in the operating system's temporary directory before launching Docker. This avoids macOS Docker file-sharing restrictions on projects stored in `Documents`; restart the environment after theme changes so the mirror is refreshed.

## Test layers

- `npm test`: Chromium desktop and mobile-Safari-emulated functional checks, with trace/video/screenshots retained on failure.
- `npm run test:visual`: screenshot regression checks. Establish intentional baselines with `npm run test:update-snapshots`.
- `npm run test:a11y`: axe automated accessibility checks.
- `npm run test:performance`: current Lighthouse engine, run three times with median budgets for LCP, layout shift, blocking time, accessibility, and best practices. Raw reports are retained locally.
- `npm run test:all`: browser acceptance followed by Lighthouse budgets.

Synthetic tests catch regressions before deployment. Once production exists, the same read-only suite can target it through `BASE_URL`. Real-user Web Vitals collection and geographic synthetic runs will be added only after the privacy implications and retention policy are agreed; neither should collect visitor identity or private page contents.

The initial local performance run deliberately remains over budget: the unthemed WordPress login route has a roughly 6.1-second median simulated LCP. This is a recorded product issue, not a relaxed threshold; the themed login and plugin asset loading must bring it below 2.5 seconds.

`@wordpress/env` is development-only. Its current upstream dependency tree has moderate advisories in archive extraction and an optional preview server; it must never be installed or exposed on production. We track upstream releases and audit upgrades, but do not downgrade to the older release npm suggests because that version has more severe known issues.

## Required production checks

The suite will grow with the site and cover account claiming, password/passwordless login, access control, forum posting and subscriptions, album creation/upload, responsive rendering, keyboard navigation, email delivery, direct media access, caching headers, and plugin-update compatibility.

See [plugin evaluation](docs/plugin-evaluation.md) for the theming and acceptance gates.
