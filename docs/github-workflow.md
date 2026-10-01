# GitHub workflow

The repository workflow is built around short-lived worktree branches and pull
requests into `main`:

1. Make a feature in its own worktree and push the branch.
2. Open a pull request into `main`.
3. The trusted CI workflow starts WordPress, seeds the fixture data, runs the
   complete Playwright suite, and runs the Lighthouse budgets against the pull
   request's merge ref. Its reports are retained as a workflow artifact. The
   workflow definition comes from `main`, while the source under test comes
   from the pull request, so a PR cannot replace the required check by editing
   its own CI YAML. The test runner has only `contents: read`, does not use
   production secrets, and disables checkout credentials. Separate trusted
   jobs create and complete `CI / test` with `checks: write`, including when a
   token-created automation PR cannot start a normal pull-request workflow.
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
document back to production. Since the sync workflow writes with
`GITHUB_TOKEN`, it explicitly dispatches the trusted CI workflow from `main`
with the PR number and head SHA; that workflow reports the required
`CI / test` check on the PR commit. Copilot review is requested by the ruleset.

## When Copilot cannot approve a change

Copilot may be unable to approve a change whose correctness depends on
repository-level settings that are not visible in the checkout: rulesets,
required checks, environment approvals, deployment secrets, or other GitHub
configuration. A comment saying that the change looks good does not satisfy
GitHub's approval requirement, and copying an arbitrary human-authored branch
into a bot-authored PR would undermine the independent-review gate.

Keep those changes in a normal human-authored PR and have an independent
reviewer approve them. The initial workflow setup PR is the one-time bootstrap
exception: after checking the diff and the relevant settings, an administrator
can temporarily add themselves as a ruleset bypass, squash-merge that PR, and
remove the bypass immediately. The block-editor sync PR is different: it is
bot-authored from content read from the published site, not from an arbitrary
coding branch, and still goes through the normal checks and Copilot approval.
