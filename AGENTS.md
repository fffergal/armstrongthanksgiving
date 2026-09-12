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

Keep `.worktree/`, `node_modules/`, test reports, Lighthouse reports, and
Docker-generated state out of commits. Never use a broad Docker prune command
while another worktree is running; the wrappers scope cleanup to this
worktree's generated environment.

## WordPress admin uploads and browser controls

For production theme or plugin changes, prepare a ZIP locally and use the
WordPress admin installer. The upload page has two controls: first open
**Upload Theme** or **Upload Plugin**, then activate the nested **Theme zip
file** or **Plugin zip file** control. In browser automation, wait for the file
chooser from that nested control before setting the ZIP path. Do not use the
online Theme File Editor for repository changes.

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

The local setup script seeds a single bbPress forum and a `Say hello` topic;
keep that fixture when changing setup code because the member test expects the
topic and its turkey avatar. The RSVP tests deliberately mutate local users
and food counts, so reset/reseed before diagnosing visual or member failures.
