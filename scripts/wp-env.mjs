import fs from 'node:fs/promises';
import os from 'node:os';
import path from 'node:path';
import { spawn, spawnSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const sourceTheme = path.join(root, 'wp-content/themes/armstrong-thanksgiving');
const sourceGatheringPlugin = path.join(root, 'wp-content/plugins/armstrong-gathering');
const sourceMuPlugins = path.join(root, 'wp-content/mu-plugins');
// Docker Desktop shares /private/tmp by default on macOS, while the system
// temporary directory can resolve into a per-user /var/folders path that is
// not exposed to the VM. Keep the stage in the shared temporary area.
const stagingBase = process.platform === 'darwin' ? '/private/tmp' : os.tmpdir();
const stagingRoot = path.join(stagingBase, 'armstrong-thanksgiving-wp-env-v2');
const stagedTheme = path.join(stagingRoot, 'armstrong-thanksgiving');
const stagedGatheringPlugin = path.join(stagingRoot, 'armstrong-gathering');
const stagedMuPlugins = path.join(stagingRoot, 'mu-plugins');
const stagedConfig = path.join(stagingRoot, 'wp-env.json');
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

const child = spawn(path.join(root, 'node_modules/.bin/wp-env'), [...command, '--config', stagedConfig], {
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

async function newestProjectDir() {
  const entries = await fs.readdir(path.join(os.homedir(), '.wp-env'), { withFileTypes: true }).catch(() => []);
  const candidates = [];
  for (const entry of entries) {
    if (!entry.isDirectory() || !entry.name.startsWith('wp-env-armstrong-thanksgiving-wp-env-')) continue;
    const dir = path.join(os.homedir(), '.wp-env', entry.name);
    if (await fs.stat(path.join(dir, 'docker-compose.yml')).catch(() => false)) {
      candidates.push({ dir, mtime: (await fs.stat(dir)).mtimeMs });
    }
  }
  return candidates.sort((a, b) => b.mtime - a.mtime)[0]?.dir;
}

async function copyProjectIntoContainer() {
  if (cachedPlugins.length !== pluginSlugs.length) {
    throw new Error('The wp-env plugin cache is incomplete; run `npm run wp:start` once with network access to refill it.');
  }
  const projectDir = await newestProjectDir();
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

child.on('close', async code => {
  if (code === 0 && command[0] === 'start') {
    try {
      await copyProjectIntoContainer();
    } catch (error) {
      console.error(error instanceof Error ? error.message : error);
      process.exit(1);
    }
  }
  process.exit(code ?? 1);
});
