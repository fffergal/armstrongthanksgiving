# GitHub workflow

The repository workflow is built around short-lived worktree branches and pull
requests into `main`:

1. Make a feature in its own worktree and push the branch.
2. Open a pull request into `main`.
3. The trusted CI workflow starts WordPress, seeds the fixture data, runs the
   complete Playwright suite, and runs the Lighthouse budgets against the
   immutable merge commit captured with the PR head used for the required
   check. Its reports are retained as a workflow artifact. The
   workflow definition comes from `main`, while the source under test comes
   from the pull request, so a PR cannot replace the required check by editing
   its own CI YAML. The test runner has only `contents: read`, does not use
   production secrets, and disables checkout credentials. Separate trusted
   jobs create and complete `CI / test` with `checks: write`, including when a
   token-created automation PR cannot start a normal pull-request workflow.
4. Codex review is enabled. Review any feedback it provides and address useful
   findings before merging; reviewer approval is not required.
5. After required checks pass and review feedback is addressed, squash-merge the
   pull request.
6. A push to `main` deploys changed theme, plugin, and block-editor content in
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
- Do not automatically request Copilot review.
- Codex review is enabled; use its feedback to improve the change, but it is not
  an approval gate.
- Require the `CI / test` status check.
- Require branches to be up to date before merging.
- Require conversation resolution before merging.
- Disable merge commits and rebase merges; leave squash merging enabled.
- Disable branch deletion and force pushes.

The current review configuration has Copilot reviewer requests turned off and
no required approvals. Codex review is enabled and may leave feedback; assess
and address useful comments before merging. Keep the `CI / test` check,
up-to-date branch requirement, and conversation-resolution requirement as the
merge gates.

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
The PR receives the same CI checks as every other change. Codex review may
provide feedback, but no reviewer approval is required. Once merged, the
deployment workflow publishes the checked-in block
document back to production. Since the sync workflow writes with
`GITHUB_TOKEN`, it explicitly dispatches the trusted CI workflow from `main`
with the PR number and head SHA; that workflow reports the required
`CI / test` check on the PR commit. Codex review feedback can be addressed as
part of the ordinary PR conversation; it does not create an approval gate.
