# GitHub workflow

The repository workflow is built around short-lived worktree branches and pull
requests into `main`:

1. Make a feature in its own worktree and push the branch.
2. Open a pull request into `main`.
3. The `CI / test` check starts WordPress, seeds the fixture data, runs the
   complete Playwright suite, and runs the Lighthouse budgets. Its reports are
   retained as a workflow artifact.
4. The Copilot workflow requests `copilot-pull-request-reviewer[bot]` and the
   `Copilot approval` check stays red until that bot has approved the current
   commit.
5. After the checks and approval are complete, squash-merge the pull request.
6. A push to `main` deploys changed theme, plugin, and block-editor content in
   sequence, then checks the public site.

## One-time repository settings

Workflows cannot create branch-protection settings, configure the repository's
merge buttons, or enable Copilot approvals. An administrator should configure
the `main` branch rule with:

- Require a pull request before merging; no direct pushes or bypasses.
- Require one approval and dismiss stale approvals. The required `Copilot
  approval` check separately verifies approval of the current commit; enabling
  GitHub's additional "most recent push" reviewer toggle may require a human
  approval as well, so leave it off unless that is intentional.
- Require the `CI / test` and `Copilot approval` status checks.
- Require branches to be up to date before merging.
- Require conversation resolution before merging.
- Disable merge commits and rebase merges; leave squash merging enabled.
- Disable branch deletion and force pushes.

In the repository's Copilot code-review settings, enable automatic review for
pull requests, allow Copilot to approve pull requests, and allow Copilot
approvals to count toward merge requirements. Copilot approvals are currently
a GitHub public-preview feature. The repository's explicit `Copilot approval`
check also verifies that the approval belongs to the current PR commit.

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
finds a difference. If that PR has not been merged yet, later runs add a new
commit to it instead of opening duplicates. If the PR is merged, the next
production change creates a new one. The PR receives the same tests and Copilot
approval gate as every other change. Once merged, the deployment workflow
publishes the checked-in block document back to production. Because GitHub does
not automatically fan out new workflow events from the repository's
`GITHUB_TOKEN`, the sync workflow explicitly dispatches the two required checks
against the updated branch.
