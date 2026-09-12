import crypto from 'node:crypto';
import fs from 'node:fs/promises';
import os from 'node:os';
import path from 'node:path';
import { spawn, spawnSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const worktreeId = crypto.createHash('sha256').update(root).digest('hex').slice(0, 10);
const sourceTheme = path.join(root, 'wp-content/themes/armstrong-thanksgiving');
const sourceGatheringPlugin = path.join(root, 'wp-content/plugins/armstrong-gathering');
const sourceMuPlugins = path.join(root, 'wp-content/mu-plugins');
// Docker Desktop shares /private/tmp by default on macOS, while the system
// temporary directory can resolve into a per-user /var/folders path that is
// not exposed to the VM. Keep the stage in the shared temporary area.
const stagingBase = process.platform === 'darwin' ? '/private/tmp' : os.tmpdir();
const stagingRoot = path.join(stagingBase, `armstrong-thanksgiving-wp-env-${worktreeId}`);
const stagedTheme = path.join(stagingRoot, 'armstrong-thanksgiving');
const stagedGatheringPlugin = path.join(stagingRoot, 'armstrong-gathering');
const stagedMuPlugins = path.join(stagingRoot, 'mu-plugins');
const stagedConfig = path.join(stagingRoot, 'wp-env.json');
const runtimeDirectory = path.join(root, '.worktree');
const runtimeFile = path.join(runtimeDirectory, 'runtime.json');
const command = process.argv.slice(2);

await fs.mkdir(stagingRoot, { recursive: true });
if (command[0] === 'start' || !(await fs.stat(stagedTheme).catch(() => false))) {
  await fs.rm(stagedTheme, { recursive: true, force: true });
  await fs.cp(sourceTheme, stagedTheme, { recursive: true });
}
if (command[0] === 'start' || !(await fs.stat(stagedGatheringPlugin).catch(() => false))) {
  await fs.rm(stagedGatheringPlugin, { recursive: true, force: true });
  await fs.cp(sourceGatheringPlugin, stagedGatheringPlugin, { recursive: true });
}
if (command[0] === 'start' || !(await fs.stat(stagedMuPlugins).catch(() => false))) {
  await fs.rm(stagedMuPlugins, { recursive: true, force: true });
  await fs.cp(sourceMuPlugins, stagedMuPlugins, { recursive: true });
}

const config = JSON.parse(await fs.readFile(path.join(root, '.wp-env.json'), 'utf8'));
config.autoPort = true;
// The WordPress image initialiser can clear child bind mounts under
// /var/www/html. Start wp-env with its normal core mount, then copy our
// project files into that mount once the containers are ready.
const remotePlugins = config.plugins ?? [];
const pluginSlugs = remotePlugins
  .map(value => String(value).split('/').pop()?.replace(/\.zip$/, ''))
  .filter(Boolean);
const cacheEntries = await fs.readdir(path.join(os.homedir(), '.wp-env'), { withFileTypes: true }).catch(() => []);
const cacheRoots = cacheEntries
  .filter(entry => entry.isDirectory())
  .map(entry => path.join(os.homedir(), '.wp-env', entry.name));
const cachedPlugins = [];
for (const slug of pluginSlugs) {
  let found;
  for (const cacheRoot of cacheRoots) {
    const candidate = path.join(cacheRoot, slug);
    if (await fs.stat(candidate).catch(() => false)) {
      found = candidate;
      break;
    }
  }
  if (found) cachedPlugins.push({ slug, source: found });
}
config.themes = [];
config.plugins = [];
config.mappings = {};
await fs.writeFile(stagedConfig, `${JSON.stringify(config, null, 2)}\n`);

async function hasGeneratedEnvironment() {
  const cacheDirectory = path.join(os.homedir(), '.wp-env');
  const configHash = crypto.createHash('md5').update(stagedConfig).digest('hex');
  const entries = await fs.readdir(cacheDirectory, { withFileTypes: true }).catch(() => []);
  const prefix = `wp-env-${path.basename(stagingRoot)}-`;
  for (const entry of entries) {
    if (!entry.isDirectory() || (entry.name !== configHash && !entry.name.startsWith(prefix))) continue;
    if (await fs.stat(path.join(cacheDirectory, entry.name, 'docker-compose.yml')).catch(() => false)) return true;
  }
  return false;
}

if (['cleanup', 'destroy'].includes(command[0]) && !(await hasGeneratedEnvironment())) {
  console.log('No generated WordPress environment found; removed worktree runtime state.');
  await fs.rm(stagingRoot, { recursive: true, force: true });
  await fs.rm(runtimeDirectory, { recursive: true, force: true });
  process.exit(0);
}

const wpEnvBin = path.join(root, 'node_modules/.bin/wp-env');
const childArgs = [...command, '--config', stagedConfig];
if (['cleanup', 'destroy'].includes(command[0]) && !command.includes('--force')) childArgs.push('--force');
const child = spawn(wpEnvBin, childArgs, {
  cwd: root,
  stdio: 'inherit'
});

function run(commandName, args) {
  const result = spawnSync(commandName, args, { cwd: root, encoding: 'utf8' });
  if (result.status !== 0) {
    throw new Error(result.stderr || `${commandName} ${args.join(' ')} failed`);
  }
  return result.stdout.trim();
}

function runOutput(commandName, args) {
  const result = spawnSync(commandName, args, { cwd: root, encoding: 'utf8' });
  if (result.status !== 0) {
    throw new Error(result.stderr || `${commandName} ${args.join(' ')} failed`);
  }
  return result.stdout.trim();
}

function environmentStatus() {
  return JSON.parse(runOutput(wpEnvBin, ['status', '--config', stagedConfig, '--json']));
}

async function copyProjectIntoContainer() {
  if (cachedPlugins.length !== pluginSlugs.length) {
    throw new Error('The wp-env plugin cache is incomplete; run `npm run wp:start` once with network access to refill it.');
  }
  const projectDir = environmentStatus().installPath;
  if (!projectDir) throw new Error('Could not locate the generated wp-env project.');
  const composeFile = path.join(projectDir, 'docker-compose.yml');
  const container = run('docker', ['compose', '-f', composeFile, 'ps', '-q', 'wordpress']);
  if (!container) throw new Error('The WordPress container is not running.');

  const targets = [
    { source: stagedTheme, target: '/var/www/html/wp-content/themes/armstrong-thanksgiving' },
    { source: stagedGatheringPlugin, target: '/var/www/html/wp-content/plugins/armstrong-gathering' },
    { source: stagedMuPlugins, target: '/var/www/html/wp-content/mu-plugins' },
    ...cachedPlugins.map(({ slug, source }) => ({ source, target: `/var/www/html/wp-content/plugins/${slug}` }))
  ];
  for (const { source, target } of targets) {
    run('docker', ['exec', container, 'rm', '-rf', target]);
    run('docker', ['cp', source, `${container}:${target}`]);
  }
}

async function writeRuntimeMetadata() {
  const status = environmentStatus();
  if (status.status !== 'running' || !status.urls?.development) {
    throw new Error('wp-env started but did not report a running development URL.');
  }
  // wp-env's status URL is built from the configured port, while its published
  // Docker port may have been auto-assigned. Always prefer the port Docker is
  // actually exposing so browser tests cannot follow another worktree.
  const port = Number(status.ports?.development);
  const protocol = new URL(status.urls.development).protocol;
  const developmentURL = port ? `${protocol}//localhost:${port}` : status.urls.development;
  await fs.mkdir(runtimeDirectory, { recursive: true });
  await fs.writeFile(runtimeFile, `${JSON.stringify({
    worktree: root,
    config: stagedConfig,
    installPath: status.installPath,
    url: developmentURL,
    port: port || null,
    updatedAt: new Date().toISOString(),
  }, null, 2)}\n`);
  console.log(`Worktree WordPress URL: ${developmentURL}`);
}

async function removeGeneratedState() {
  await fs.rm(stagingRoot, { recursive: true, force: true });
  await fs.rm(runtimeDirectory, { recursive: true, force: true });
}

child.on('close', async code => {
  if (code === 0 && command[0] === 'start') {
    try {
      await copyProjectIntoContainer();
      await writeRuntimeMetadata();
    } catch (error) {
      console.error(error instanceof Error ? error.message : error);
      process.exit(1);
    }
  }
  if (code === 0 && ['cleanup', 'destroy'].includes(command[0])) {
    await removeGeneratedState();
  }
  process.exit(code ?? 1);
});
