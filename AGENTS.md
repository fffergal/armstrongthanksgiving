# Armstrong Thanksgiving development guidance

## Codex environments and worktrees

Configure the Codex environment setup command as:

```sh
npm run env:setup
```

This command finds the primary Git worktree. When the dependency-related parts
of `package.json` and `package-lock.json` match the primary worktree, it links
this worktree's `node_modules` to the primary worktree's installation, avoiding
another large dependency tree. If dependency metadata changes, it detaches the
link and runs `npm ci` locally. Do not run `npm install` in a worktree while its
`node_modules` is linked; rerun `npm run env:setup` after changing dependency
manifests.

The primary worktree is intentionally left with an ordinary `node_modules`
directory. A local worktree installation is only created when its dependency
manifests differ, so dependency changes remain isolated from the primary
worktree.

## WordPress and Docker lifecycle

Use the repository wrappers rather than invoking `wp-env` directly:

```sh
npm run wp:start        # start this worktree and assign an available port
npm run wp:setup        # seed the local WordPress database
npm run wp:stop         # stop containers but keep volumes for a quick restart
npm run wp:cleanup      # remove this worktree's containers, volumes, and cache
npm run env:teardown    # use when closing the worktree
npm run wp:destroy      # deliberate, slower removal including Docker images
```

Each worktree gets a distinct staged config path, wp-env cache identity,
Docker Compose project, containers, network, and volumes. `wp-env` starts from
port 8888 and automatically selects the next available port when needed. The
assigned URL is recorded in `.worktree/runtime.json`; Playwright and Lighthouse
use it automatically. Set `BASE_URL` to a non-default URL to target another
running environment, or set `USE_WORKTREE_RUNTIME=0` to force the configured
`BASE_URL`.

The wrapper refreshes the staged theme, custom plugin, mu-plugin, and cached
community plugins on every start. After editing those files, restart with
`npm run wp:start` before testing. `wp:stop` is intentionally reversible;
`wp:cleanup`/`env:teardown` are the closing-down commands because they remove
the worktree's Docker data and generated runtime state while preserving Docker
images for the next worktree.

## Feature screenshots

When implementing a feature that changes the site's visible interface, finish
by capturing an actual screenshot image of the running local result at a useful
viewport size. Use the internal browser and the worktree URL recorded in
`.worktree/runtime.json`. Make the image itself available in the completion
message, embedded as an image; opening a browser tab, linking to the local page,
or saying that a screenshot was taken does not count. Save screenshots outside
the repository or in ignored output directories. For responsive or materially
different interface states, include the images needed to show those states. If
the feature has no visible interface, or the local site cannot be brought up,
explain that in the completion message.

## Sandbox troubleshooting

When Docker commands, internal browser automation, or the `gh` CLI fail,
consider sandbox and filesystem or process permissions before concluding that
the tool, credentials, or project setup are broken. Check the relevant error
and permissions, then retry the smallest relevant diagnostic or operation with
the required elevated access when available. Compare the restricted and
elevated results before changing credentials, reinstalling tools, or altering
project configuration. Keep using the internal browser for browser automation.
If it is unavailable, check permissions on `/private/tmp/codex-browser-use`
before investigating other causes.

Keep `.worktree/`, `node_modules/`, test reports, Lighthouse reports, and
Docker-generated state out of commits. Never use a broad Docker prune command
while another worktree is running; the wrappers scope cleanup to this
worktree's generated environment.

## Production and commits

The production site is `https://www.armstrongthanksgiving.com/`. Use that
canonical domain for deployment checks and production browser work; the local
site URL comes from `.worktree/runtime.json` instead.

Agents are explicitly free to make commits in this repository after verifying
their changes. Use descriptive commits and leave the worktree with the source
state recorded. This repository does not require GPG-signed commits; when the
local Git configuration attempts signing, commit with
`git -c commit.gpgsign=false commit -m "..."`.

When the user says to close out a worktree, treat that as an integration task,
not just a cleanup request. Run or confirm the relevant checks, create a PR,
resolve any Copilot reviewer comments, and wait for Copilot reviewer approval.
Then squash merge the PR. If the merge triggers a production deployment, wait
for it to succeed. If the deployment workflow skips because no production
components changed, confirm that the skip was expected. After a successful
deployment or a confirmed expected skip, tear down the local worktree
environment and pull the updated `main` branch in the project directory. Then
remove the worktree and its disposable environment. Do not rewrite a shared or
already published branch; preserve user-authored commits and ask before
discarding uncommitted work.

For production-scoped changes, deployment is part of closeout: run the
relevant checks and tests first (or confirm they already passed), then deploy
the verified theme/plugin changes and validate the canonical production site
before removing the worktree. If deployment is not authorized or is blocked,
state that explicitly instead of treating the worktree as finished.

## WordPress admin uploads and browser controls

For production theme or plugin changes, use the repository deployment command
after verifying the relevant component and committing its source:

```sh
npm run deploy:production -- plugin --confirm
npm run deploy:production -- theme --confirm --activate
npm run verify:production
```

The production prerequisites are the WP Super Cache plugin and the WP-CLI
package `wp-cli/wp-super-cache-cli`; recreate the CLI package with:

```sh
ssh dh_mbpyvr@armstrongthanksgiving.com 'wp package install wp-cli/wp-super-cache-cli'
```

The command builds the ZIP from committed source, uploads it over SSH, exports
the production database, archives the previous component, replaces the
component with WP-CLI, preserves its current activation state, flushes the
WordPress object cache and WP Super Cache page cache, and verifies the
production WordPress identity and archive digest. Pass `--activate` when a
component should be activated explicitly. Run with `--dry-run` to build the
archive without changing production. The WordPress admin installer remains
the fallback when the CLI workflow is unavailable. Do not use the online Theme
File Editor for repository changes.

If WordPress says the component is already installed, follow the
**Replace current with uploaded** link and verify the success notice. WordPress
may show a JavaScript confirmation dialog during replacement or deletion. A
browser agent should inspect for an active page dialog and accept it only when
it is the confirmation for the user-requested operation; otherwise dismiss it.
Never repeatedly click while a dialog is open, because the page is blocked
until the dialog is handled.

After a production upload, purge WP Super Cache before validating the public
site. Prefer a fresh public URL check (including an HTTP status check for 404s)
over relying on a stale browser tab.

## Email testing

There are two different email checks:

- Local WordPress does not deliver mail. The local `pre_wp_mail` hook captures
  the final arguments after all mail filters have run, and the RSVP tests read
  that captured message through `at_gathering_last_test_mail`. Use `npm run
  wp:start`, `npm run wp:setup`, and the focused Playwright RSVP tests to verify
  subjects, copy, HTML wrapping, and headers. A passing local test is not proof
  that a message reached an inbox.
- For a real inbox check, the changed theme/plugin must be uploaded to
  `https://www.armstrongthanksgiving.com/` first. In the production WordPress
  admin, open **Gathering RSVPs**, use **Send a sample confirmation**, choose
  the intended administrator recipient, and submit the form. Confirm the
  result both from the WordPress success notice and in that recipient's inbox;
  inspect the message at a narrow width and check links, wrapping, copy, and
  dark-mode legibility. Sending the sample is an external email action, so do
  not click it unless the user has requested that send or confirmed it at the
  point of action.

The local setup script seeds a single bbPress forum and a `Say hello` topic;
keep that fixture when changing setup code because the member test expects the
topic and its turkey avatar. The RSVP tests deliberately mutate local users
and food counts, so reset/reseed before diagnosing visual or member failures.
