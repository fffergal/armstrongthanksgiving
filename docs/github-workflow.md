# GitHub workflow

The repository workflow is built around short-lived worktree branches and pull
requests into `main`:

1. Make a feature in its own worktree and push the branch.
2. Open a pull request into `main`.
3. The `CI / test` check starts WordPress, seeds the fixture data, runs the
   complete Playwright suite, and runs the Lighthouse budgets. Its reports are
   retained as a workflow artifact.
4. The repository ruleset automatically requests Copilot review on new pull
   requests and new pushes. Copilot's native approval counts as the required
   pull-request approval when repository Copilot approval settings are enabled.
5. After the checks and approval are complete, squash-merge the pull request.
6. A push to `main` deploys changed theme, plugin, and block-editor content in
   sequence, then checks the public site.

## One-time repository settings

The `Protect main` repository ruleset configures the `main` branch with:

- Require a pull request before merging; no direct pushes or bypasses.
- Require one approval and dismiss stale approvals.
- Automatically request Copilot review on new pull requests and new pushes.
- Require the `CI / test` status check.
- Require branches to be up to date before merging.
- Require conversation resolution before merging.
- Disable merge commits and rebase merges; leave squash merging enabled.
- Disable branch deletion and force pushes.

In the repository's Copilot code-review settings, enable **Allow Copilot to
approve pull requests** and **Allow Copilot approvals to count toward merge
requirements**. Copilot approvals are currently a GitHub public-preview
feature. The ruleset handles review requests and the normal pull-request
approval gate handles the approval itself; no custom Actions reviewer check is
needed.

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
duplicates. If the PR is merged, the next production change creates a new one.
The PR receives the same CI and native Copilot approval gate as every other
change. Once merged, the deployment workflow publishes the checked-in block
document back to production. Because GitHub does not automatically fan out new
workflow events from the repository's `GITHUB_TOKEN`, the sync workflow
explicitly dispatches the required CI check against the updated branch;
Copilot review is requested by the ruleset.

The repository's active `Protect main` ruleset is the source of truth for
required checks and approvals. The legacy `Copilot approval` Actions check was
removed when that ruleset was enabled; native Copilot review plus its approval
setting now supplies the review requirement.

## Opening a bot-authored maintenance PR

Sometimes Copilot can review the diff but cannot approve a PR because the
change depends on repository-level settings that are not visible in the
checkout: rulesets, required checks, environment approvals, deployment
secrets, or other GitHub configuration. If the PR was opened by the same human
who prepared the change, that human cannot approve their own PR either. A
comment saying that the change looks good does not satisfy GitHub's approval
requirement.

For that case, use **Actions → Open maintenance PR → Run workflow**. Enter the
existing source branch and optionally a title. The workflow copies that branch
to a distinct `automation/maintenance/<source-branch>` branch, opens or
updates one PR as `github-actions[bot]`, explicitly dispatches CI, and prints
the PR link in the workflow summary. The bot identity only separates PR
authorship from the human reviewer; it does not assert that the change is
trusted or grant a bypass. Review the diff and the relevant GitHub settings,
then approve the PR normally. The same ruleset still enforces current CI, an
up-to-date branch, resolved conversations, and the configured review approval
before merge.
