# GitHub workflow

The repository workflow is built around short-lived worktree branches and pull
requests into `main`:

1. Make a feature in its own worktree.
2. Run `npm test` in the worktree before pushing. On macOS this runs the native
   browser suite and repeats the visual contracts in an x64 Linux Playwright
   container to match GitHub Actions. On Apple Silicon, Docker runs that image
   under QEMU with Chromium's GPU and zygote processes disabled. If a
   deliberate visual change needs new baselines, use
   `npm run test:update-snapshots` locally, review the changed images, and
   commit them with the code change before pushing.
3. Push the branch and open a pull request into `main`.
4. The base-branch CI workflow starts WordPress, seeds the fixture data, runs the
   browser acceptance suite and Lighthouse budgets on an x64 Ubuntu runner,
   and runs visual contracts in the same x64 Playwright container used by local
   snapshot checks. Docker Buildx imports and exports the container's layer
   cache through Actions cache, keyed by the Playwright Dockerfile and lockfile.
   Trusted pushes to `main` save cache versions; `pull_request_target` runs can
   restore the default-branch cache but cannot write to it. This lets separate
   PRs and main builds reuse the same image layers without a registry or
   persistent runner, while keeping PR-controlled Dockerfile content out of
   the shared cache. The shared container includes DejaVu Sans for the Ubuntu
   system-font fallback. Reports are retained as workflow artifacts. The
   workflow definition comes from `main`, while the source under test comes
   from the pull request, so a PR cannot replace the required check by editing
   its own CI YAML. This separation lets the check be created with
   `checks: write` while the test runner has only `contents: read`, does not use
   production secrets, and disables checkout credentials. Separate
   jobs create and complete `CI / test` with `checks: write`, including when a
   token-created automation PR cannot start a normal pull-request workflow.
5. The GitHub Codex reviewer is optional and may not be enabled for every PR.
   Review any available feedback and address useful findings before merging;
   reviewer approval is not required. There is no need to trigger a review
   manually or wait indefinitely for feedback.
   If you decide not to take the reviewer's advice, resolve the corresponding
   review conversation anyway; unresolved conversations block merging.
6. After required checks pass and available review feedback is addressed,
   squash-merge the pull request. If no Codex review arrives, proceed once the
   documented merge gates pass.
7. A push to `main` deploys changed theme, plugin, and block-editor content in
   sequence, then checks the public site. When publishing a changed Home page,
   deployment compares production against the previous committed page (or the
   incoming page) inside the same database transaction as the update, holding
   a row lock throughout. It stops if newer editor changes would be
   overwritten. Theme/plugin-only deploys do not touch or depend on page
   content.

## One-time repository settings

The `Protect main` repository ruleset configures the `main` branch with:

- Require a pull request before merging; no direct pushes or bypasses.
- Require zero approvals.
- GitHub Codex review, when enabled, is advisory and is not an approval gate.
- Require the `CI / test` status check.
- Require branches to be up to date before merging.
- Require conversation resolution before merging.
- Disable merge commits and rebase merges; leave squash merging enabled.
- Disable branch deletion and force pushes.

The review process uses available GitHub Codex feedback as an advisory part of
the pull request conversation. The reviewer is not always enabled. Assess and
address useful comments before merging; reviewer approval is not required.
Do not trigger a review manually or wait indefinitely for one. Keep the
`CI / test` check, up-to-date branch requirement, and conversation-resolution
requirement as the merge gates.
If you decide not to take the reviewer's advice, resolve the corresponding
review conversation anyway so it does not block merging.

The deployment workflow expects these Actions secrets, preferably on a
`production` environment with any required approval gate:

- `PROD_SSH_PRIVATE_KEY`: the deployment key.
- `PROD_SSH_KNOWN_HOSTS`: the pinned `known_hosts` entry for the production SSH
  host.

The default SSH host, user, and WordPress path match the existing production
deployment script. They can be overridden with repository or environment
variables if the hosting account changes.

## Syncing block-editor edits

The **Sync block editor content** workflow runs daily at 03:17 UTC and is also
available through **Actions → Sync block editor content → Run workflow**. It
reads the published `Home` page from production, compares it with the current
sync branch, and updates one reusable `automation/sync-block-editor` PR when it
finds a difference. If that PR has not been merged yet, later runs merge the
latest `main` into it and add a new content commit instead of opening
duplicates. If the PR is merged, the next scheduled or manual run can create a
new one.
The PR receives the same CI checks as every other change. The optional GitHub
Codex reviewer may provide feedback, but it may not be enabled and no reviewer
approval is required. Do not trigger a review manually or wait indefinitely;
if no review arrives, proceed once the documented merge gates pass. Once
merged, the deployment workflow publishes the checked-in block document back to
production. Since the sync workflow writes with
`GITHUB_TOKEN`, it explicitly dispatches the base-branch CI workflow from `main`
with the PR number and head SHA; that workflow reports the required
`CI / test` check on the PR commit. The workflow runs from `main` while it
tests the PR's immutable merge commit. Address available GitHub Codex feedback
as part of the ordinary PR conversation; it does not create an approval gate.
