# Armstrong Thanksgiving

This repository contains the custom WordPress theme, reproducible local environment, plugin inventory, and browser-based acceptance tests. The production database, uploads, caches, and secrets are backed up separately and are not stored in Git.

Production site: <https://www.armstrongthanksgiving.com/>

## Local setup

Requirements: Docker Desktop, Node.js 22+, and npm.

```sh
cp .env.example .env
npm run env:setup
npx playwright install chromium
npx playwright install webkit
npm run wp:start
npm run wp:setup
```

Open the URL printed by `npm run wp:start`. The bootstrap creates a local `guest` / `password` subscriber for the member journey; override `WP_TEST_USER` and `WP_TEST_PASSWORD` in `.env` when needed.

## Worktree environments

Run `npm run env:setup` from each worktree. If the dependency metadata matches
the primary worktree, it links the existing `node_modules` directory instead
of creating another copy. A worktree with changed dependency metadata gets its
own `npm ci` installation.

`npm run wp:start` gives each worktree its own wp-env config/cache identity and
Docker Compose project. It automatically chooses an available HTTP port and
writes the resulting URL to `.worktree/runtime.json`, so the Playwright and
Lighthouse commands follow the correct local WordPress instance. Use
`npm run wp:stop` for a reversible pause and `npm run env:teardown` when the
worktree is finished; the latter removes that worktree's containers, volumes,
generated wp-env files, and runtime metadata while retaining Docker images.

If `.env` was copied before this change, remove its default
`BASE_URL=http://localhost:8888` line or set `BASE_URL=` so the per-worktree
URL can be used. A non-default `BASE_URL` still takes precedence for production
or other shared environments.

The start wrapper stages the theme and must-use plugin in Docker Desktop's shared temporary directory, starts the stock WordPress volume, then copies the theme and cached community plugins into that volume. This avoids macOS Docker file-sharing and image-initialiser issues for projects stored in `Documents`; restart the environment after theme or mu-plugin changes so the mirror is refreshed. Plugin archives remain in wp-env's cache rather than Git.

## Test layers

- `npm test`: Chromium desktop and mobile-Safari-emulated privacy checks, standard WordPress sign-in, plus authenticated member, RSVP, forum, food, and album journeys, with trace/video/screenshots retained on failure.
- `npm run test:visual`: screenshot regression checks against both the native
  platform and a Linux container. On Apple Silicon, Docker runs the x64 Linux
  image under QEMU with Chromium's GPU and zygote processes disabled. The
  container includes DejaVu Sans, matching Ubuntu 24.04's bold system font and
  the single Linux snapshot set used by GitHub Actions. CI runs its visual
  project in this same image.
- `npm run test:update-snapshots`: refreshes both native and Linux screenshot
  baselines for an intentional visual change. On Apple Silicon, Linux
  baselines are updated in the x64 container used by GitHub Actions. Review and
  commit the changed images from
  `tests/e2e/visual.spec.ts-snapshots/`.
- `npm run test:visual:linux`: runs only the shared Linux baseline pass.
- `npm run test:a11y`: axe automated accessibility checks.
- `npm run test:performance`: current Lighthouse engine, run three times with median budgets for LCP, layout shift, blocking time, accessibility, and best practices. LCP warns above 1.5 seconds and fails above 2.5 seconds. Raw reports are retained locally.
- `npm run test:all`: browser acceptance (including Linux screenshot checks)
  followed by Lighthouse budgets.

Every Playwright run against this worktree's local WordPress site automatically
resets browser-generated test data and restores the standard pages, settings,
and forum fixture before the tests start. This includes RSVP rows, test-created
accounts, forum topics/replies, and captured test email. Administrator accounts
and the configured `WP_TEST_USER` are retained. Remote `BASE_URL` targets are
left untouched.

For a manual reset outside Playwright, use:

```sh
npm run wp:reset
npm run wp:start
npm run wp:setup
npm test
```

`npm run wp:setup` is also the repair step if a local forum, topic, or page
fixture is missing. The suite verifies the current RSVP journey, account
creation, food-count updates, sign-in draft restoration, responsive layouts,
accessibility, visuals, forums, albums, and 404 recovery. It does not prove
delivery through the production SMTP service.

Synthetic tests catch regressions before deployment. Once production exists, the same read-only suite can target it through `BASE_URL`. Real-user Web Vitals collection and geographic synthetic runs will be added only after the privacy implications and retention policy are agreed; neither should collect visitor identity or private page contents.

The local benchmark covers the anonymous redirect/login experience and runs three times with median budgets. The current themed login median is comfortably below the 2.5-second LCP budget after removing album and admin assets that do not belong on a friend sign-in page.

The included turkey avatar pool contains 50 illustrated avatars, intended for the
private guest list. If the list grows beyond 50 friends, extend the pool before
inviting the next person so every account can keep a distinct turkey.

`@wordpress/env` is development-only. Its current upstream dependency tree has moderate advisories in archive extraction and an optional preview server; it must never be installed or exposed on production. We track upstream releases and audit upgrades, but do not downgrade to the older release npm suggests because that version has more severe known issues.

## Required production checks

As the suite grows, production checks should cover account and access behavior,
forum posting and subscriptions, album creation/upload, responsive rendering,
keyboard navigation, RSVP handling, direct media access, caching headers, and
plugin-update compatibility. Invitation claiming and roster-based adult-party
assignments remain rollout checks; verify them after plan sections 1A and 1B
are integrated. Routine sign-in is password-based, with WordPress password
recovery for forgotten passwords.

See [plugin evaluation](docs/plugin-evaluation.md) for the theming and acceptance gates.

See [email and friend onboarding](docs/email-onboarding.md) for the specified
invitation/password flow, party RSVP rules, legacy reconciliation, production
mail procedure, and current rollout status.

## Production updates

The GitHub workflow for multiple concurrent worktrees is documented in
[GitHub workflow](docs/github-workflow.md). Pull requests into `main` run the
complete browser and Lighthouse suite. No reviewer approval is required;
Codex review is enabled and its useful feedback can be addressed before merge.
Squash merges to `main` deploy the changed theme, plugin, and block-editor
content. The **Sync block editor content** workflow can pull the published Home
page into a PR.

Production deployment uses the server's SSH-accessible WP-CLI. From a committed
source state, preview or deploy the relevant component with:

```sh
npm run deploy:production -- plugin --dry-run
npm run deploy:production -- plugin --confirm
npm run deploy:production -- theme --confirm --activate
npm run verify:production
```

The local setup installs and activates WP Super Cache, and installs the WP-CLI
package `wp-cli/wp-super-cache-cli`, when they are missing. The production
prerequisites are the WP Super Cache plugin and that WP-CLI package; recreate
the CLI package with:

```sh
ssh dh_mbpyvr@armstrongthanksgiving.com 'wp package install wp-cli/wp-super-cache-cli'
```

The current production and local installs resolve to `dev-main` commit
`480d326`; check `wp package list` after recreating the package.

The local page cache remains off by default so browser tests are deterministic;
the `wp super-cache` commands are nevertheless available for cache-specific
checks.

The command creates a ZIP from `HEAD`, uploads it to the production server,
exports the database, archives the previous component, replaces the component,
preserves its current activation state, flushes both the WordPress object cache
and WP Super Cache page cache, and verifies the production identity and archive
digest. Use `--activate` when the deployment should activate the component
explicitly. The WordPress admin installer remains the fallback deployment path.
Do not edit the theme through the online Theme File Editor.

The **Gathering RSVPs** admin page includes **Send a sample confirmation**. It
sends the normal RSVP confirmation through WordPress mail without creating or
changing an RSVP. This sends a real email and should only be used when that
send has been requested. A successful WordPress handoff still needs to be
checked in the recipient inbox. The invitation and party workflow described in
[email and friend onboarding](docs/email-onboarding.md) is not live until
sections 1A and 1B have been integrated and verified.
