import crypto from 'node:crypto';
import dotenv from 'dotenv';
import fs from 'node:fs';
import path from 'node:path';
import { spawnSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
dotenv.config({ path: path.join(root, '.env') });
const visualOnly = process.argv.includes('--visual-only');
const linuxVisualOnly = process.argv.includes('--linux-visual-only');
const excludeVisual = process.argv.includes('--exclude-visual');
const updateSnapshots = process.argv.includes('--update-snapshots');
const localTarget = new URL(localBaseUrl());
const isLoopbackTarget = ['localhost', '127.0.0.1', '[::1]'].includes(localTarget.hostname);
let worktreeRuntimeOrigin;
try {
  worktreeRuntimeOrigin = new URL(JSON.parse(fs.readFileSync(path.join(root, '.worktree/runtime.json'), 'utf8')).url).origin;
} catch {
  // Without worktree metadata, the shared container cannot join the local site.
}
const useSharedLinuxVisuals = process.platform === 'linux'
  && isLoopbackTarget
  && localTarget.origin === worktreeRuntimeOrigin;
const canRunLocalLinuxVisuals = useSharedLinuxVisuals || (
  process.platform === 'darwin'
  && isLoopbackTarget
  && localTarget.origin === worktreeRuntimeOrigin
);
const helperFlags = new Set(['--visual-only', '--linux-visual-only', '--exclude-visual', '--update-snapshots']);
const forwardedArgs = process.argv.slice(2).filter(argument => !helperFlags.has(argument));
const playwrightArgs = visualOnly || linuxVisualOnly || updateSnapshots
  ? ['test', '--project=visual', ...(updateSnapshots ? ['--update-snapshots'] : []), ...forwardedArgs]
  : ['test', ...(excludeVisual || useSharedLinuxVisuals ? ['--grep-invert=visual'] : []), ...forwardedArgs];

function run(command, args, options = {}) {
  const result = spawnSync(command, args, {
    cwd: root,
    stdio: 'inherit',
    env: { ...process.env, SKIP_WP_CLI_PACKAGE: '1' },
    ...options,
  });
  if (result.error) throw result.error;
  return result.status ?? 1;
}

function localBaseUrl() {
  let runtime = {};
  try {
    runtime = JSON.parse(fs.readFileSync(path.join(root, '.worktree/runtime.json'), 'utf8'));
  } catch {
    // Non-worktree installs use the configured URL or wp-env's default port.
  }
  const configuredBaseURL = process.env.BASE_URL?.trim();
  const explicitNonDefaultBaseURL = configuredBaseURL && configuredBaseURL !== 'http://localhost:8888';
  return process.env.USE_WORKTREE_RUNTIME === '0' || explicitNonDefaultBaseURL
    ? configuredBaseURL || 'http://localhost:8888'
    : runtime.url || 'http://localhost:8888';
}

function shellQuote(value) {
  return `'${value.replaceAll("'", "'\\''")}'`;
}

function worktreeRuntime() {
  try {
    return JSON.parse(fs.readFileSync(path.join(root, '.worktree/runtime.json'), 'utf8'));
  } catch {
    throw new Error('The Linux screenshot pass needs a running worktree WordPress environment. Start it with `npm run wp:start`.');
  }
}

function updateWordPressUrl(value) {
  const wrapper = path.join(root, 'scripts/wp-env.mjs');
  for (const constant of ['WP_HOME', 'WP_SITEURL']) {
    const status = run(process.execPath, [wrapper, 'run', 'cli', 'wp', 'config', 'set', constant, value, '--type=constant']);
    if (status !== 0) return status;
  }
  return 0;
}

function runLinuxVisuals({ reset = true, skipForRemoteTarget = false, args = [] } = {}) {
  const localUrl = localBaseUrl();
  const localOrigin = new URL(localUrl).origin;
  const isLoopback = ['localhost', '127.0.0.1', '[::1]'].includes(new URL(localUrl).hostname);
  if (skipForRemoteTarget && !isLoopback) {
    console.log(`Skipping the automatic Linux screenshot pass because BASE_URL targets ${localOrigin}; it only runs against the local worktree WordPress site.`);
    return 0;
  }
  const runtime = worktreeRuntime();
  if (localOrigin !== new URL(runtime.url).origin) {
    if (skipForRemoteTarget) {
      console.log(`Skipping the automatic Linux screenshot pass because BASE_URL targets ${localOrigin}; it only runs against this local worktree at ${runtime.url}.`);
      return 0;
    }
    throw new Error(`The Linux screenshot pass targets the local worktree site at ${runtime.url}; current BASE_URL is ${localOrigin}.`);
  }
  const networkName = `${path.basename(runtime.installPath)}_default`;
  // GitHub Actions uses linux/amd64. On Apple Silicon Docker runs that image
  // under QEMU; the Chromium launch flags below keep its browser process stable.
  const dockerPlatform = 'linux/amd64';
  const dockerArchitecture = 'amd64';
  const lock = fs.readFileSync(path.join(root, 'package-lock.json'));
  const volumeKey = crypto.createHash('sha256').update(lock).digest('hex').slice(0, 12);
  const lockfile = JSON.parse(lock);
  const playwrightVersion = lockfile.packages['node_modules/@playwright/test'].version;
  const nodeVersion = '22.23.2';
  const dockerfile = fs.readFileSync(path.join(root, 'scripts/playwright.Dockerfile'));
  const dockerfileKey = crypto.createHash('sha256').update(dockerfile).digest('hex').slice(0, 8);
  const localImage = `armstrong-thanksgiving-playwright:ubuntu-24.04-node-${nodeVersion}-pw-${playwrightVersion}-${dockerfileKey}`;
  console.log('\nRunning the Linux screenshot baselines used by GitHub Actions...');

  const imageExists = spawnSync('docker', ['image', 'inspect', localImage], {
    cwd: root,
    stdio: 'ignore',
  }).status === 0;
  if (!imageExists) {
    const buildArgs = [
      'buildx', 'build', '--load', '--platform=linux/amd64',
      '--build-arg', `NODE_VERSION=${nodeVersion}`,
      '--build-arg', `PLAYWRIGHT_VERSION=${playwrightVersion}`,
      '--tag', localImage,
      '--file', path.join(root, 'scripts/playwright.Dockerfile'),
      path.join(root, 'scripts'),
    ];
    const cacheFrom = process.env.PLAYWRIGHT_DOCKER_CACHE_FROM;
    const cacheTo = process.env.PLAYWRIGHT_DOCKER_CACHE_TO;
    if (cacheFrom && (cacheFrom.startsWith('type=') || fs.existsSync(cacheFrom))) {
      buildArgs.push('--cache-from', cacheFrom.startsWith('type=') ? cacheFrom : `type=local,src=${cacheFrom}`);
    }
    if (cacheTo) {
      buildArgs.push('--cache-to', cacheTo.startsWith('type=') ? cacheTo : `type=local,dest=${cacheTo},mode=max`);
    }
    const buildStatus = run('docker', buildArgs);
    if (buildStatus !== 0) return buildStatus;
  }

  // Reset the local WordPress fixture on the host: the worktree-specific
  // wp-env configuration lives outside the repository and is not mounted in
  // the Linux browser container.
  if (reset) {
    const resetStatus = run(process.execPath, [path.join(root, 'scripts/prepare-tests.mjs')]);
    if (resetStatus !== 0) return resetStatus;
  }

  // The browser container joins the WordPress network directly. Point the
  // disposable local site's canonical URL at its Docker DNS alias during the
  // run, then restore the original worktree URL even when tests fail.
  // The named node_modules volume is isolated from the host's Darwin install.
  // npm ci runs once per lockfile version and reuses a separate npm download cache.
  const dockerArgs = [
    'run', '--rm', `--platform=${dockerPlatform}`,
    '--network', networkName,
    '--mount', `type=bind,source=${root},target=/workspace`,
    '--mount', `type=volume,source=armstrong-thanksgiving-node-${dockerArchitecture}-${volumeKey},target=/workspace/node_modules`,
    '--mount', 'type=volume,source=armstrong-thanksgiving-npm-cache,target=/root/.npm',
    '--workdir', '/workspace',
    '--env', 'BASE_URL=http://wordpress',
    '--env', 'USE_WORKTREE_RUNTIME=0',
    '--env', 'LOCAL_LINUX_QEMU=1',
    ...(process.env.CI ? ['--env', `CI=${process.env.CI}`] : []),
    ...(process.platform === 'linux'
      ? ['--env', `HOST_UID=${process.getuid()}`, '--env', `HOST_GID=${process.getgid()}`]
      : []),
    localImage,
    'sh', '-lc',
    `${process.platform === 'linux' ? `trap 'status=$?; for path in playwright-report test-results; do if [ -e "$path" ]; then chown -R "$HOST_UID:$HOST_GID" "$path" 2>/dev/null || true; fi; done; exit "$status"' EXIT; ` : ''}if [ ! -x node_modules/.bin/playwright ]; then npm ci; fi && npx playwright test --project=visual --workers=1${updateSnapshots ? ' --update-snapshots' : ''}${args.length ? ` ${args.map(shellQuote).join(' ')}` : ''}`,
  ];
  let status = updateWordPressUrl('http://wordpress');
  let restoreStatus = 0;
  try {
    if (status === 0) status = run('docker', dockerArgs);
  } finally {
    restoreStatus = updateWordPressUrl(localOrigin);
  }
  return status || restoreStatus;
}

if (linuxVisualOnly) {
  process.exit(runLinuxVisuals({ args: forwardedArgs }));
}

if (useSharedLinuxVisuals && (visualOnly || updateSnapshots)) {
  process.exit(runLinuxVisuals({ args: forwardedArgs }));
}

if (!visualOnly && !updateSnapshots && canRunLocalLinuxVisuals) {
  // Run screenshots before browser tests mutate users, RSVPs, and forum
  // content. The full reset here also gives the native suite its usual
  // starting state, so Playwright's global setup can be skipped below.
  const prepareStatus = run(process.execPath, [path.join(root, 'scripts/prepare-tests.mjs')]);
  if (prepareStatus !== 0) process.exit(prepareStatus);

  process.env.SKIP_PLAYWRIGHT_GLOBAL_SETUP = '1';
  if (process.platform === 'darwin' && !useSharedLinuxVisuals) {
    const nativeVisualStatus = run('npx', ['playwright', 'test', '--project=visual']);
    if (nativeVisualStatus !== 0) process.exit(nativeVisualStatus);
  }

  const visualStatus = runLinuxVisuals({ reset: false, skipForRemoteTarget: true });
  if (visualStatus !== 0) process.exit(visualStatus);
}

const nativeArgs = process.platform === 'darwin' && !visualOnly && !updateSnapshots && canRunLocalLinuxVisuals
  ? [...playwrightArgs, '--grep-invert=visual']
  : playwrightArgs;
const nativeStatus = run('npx', ['playwright', ...nativeArgs]);
if (nativeStatus !== 0) process.exit(nativeStatus);

if (
  (process.platform === 'darwin' || useSharedLinuxVisuals)
  && (!canRunLocalLinuxVisuals || visualOnly || updateSnapshots)
) {
  process.exit(runLinuxVisuals({ reset: !visualOnly && !updateSnapshots, skipForRemoteTarget: true }));
}
