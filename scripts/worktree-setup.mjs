import crypto from 'node:crypto';
import fs from 'node:fs/promises';
import path from 'node:path';
import { spawnSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const stateDirectory = path.join(root, '.worktree');
const dependencyStateFile = path.join(stateDirectory, 'dependencies.json');
const manifestFiles = ['package.json', 'package-lock.json'];
const dependencyFields = [
  'dependencies',
  'devDependencies',
  'optionalDependencies',
  'peerDependencies',
  'overrides',
  'resolutions',
  'bundleDependencies',
  'engines',
];

function git(args) {
  const result = spawnSync('git', args, { cwd: root, encoding: 'utf8' });
  if (result.status !== 0) {
    throw new Error(result.stderr?.trim() || `git ${args.join(' ')} failed`);
  }
  return result.stdout.trim();
}

function worktreeRoots() {
  const output = git(['worktree', 'list', '--porcelain']);
  return output
    .split(/\n\n+/)
    .map(block => block.match(/^worktree (.+)$/m)?.[1])
    .filter(Boolean)
    .map(directory => path.resolve(directory));
}

async function manifestSignature(directory) {
  const hash = crypto.createHash('sha256');
  const packageManifest = JSON.parse(await fs.readFile(path.join(directory, manifestFiles[0]), 'utf8'));
  hash.update(JSON.stringify(Object.fromEntries(
    dependencyFields
      .filter(field => packageManifest[field] !== undefined)
      .map(field => [field, packageManifest[field]])
  )));
  hash.update(await fs.readFile(path.join(directory, manifestFiles[1])));
  return hash.digest('hex');
}

async function pathKind(file) {
  return fs.lstat(file).catch(() => null);
}

function installLocally() {
  console.log('Installing worktree dependencies with npm ci.');
  const result = spawnSync('npm', ['ci'], { cwd: root, stdio: 'inherit' });
  if (result.status !== 0) process.exit(result.status ?? 1);
}

const [primaryRoot = root] = worktreeRoots();
const primaryNodeModules = path.join(primaryRoot, 'node_modules');
const currentNodeModules = path.join(root, 'node_modules');
const currentSignature = await manifestSignature(root);
const primarySignature = await manifestSignature(primaryRoot);
const currentKind = await pathKind(currentNodeModules);
const primaryKind = await pathKind(primaryNodeModules);
const isPrimary = path.resolve(primaryRoot) === root;
const previousState = JSON.parse(await fs.readFile(dependencyStateFile, 'utf8').catch(() => '{}'));

await fs.mkdir(stateDirectory, { recursive: true });

if (isPrimary) {
  if (!currentKind) installLocally();
  await fs.writeFile(
    dependencyStateFile,
    `${JSON.stringify({ mode: 'primary', signature: currentSignature }, null, 2)}\n`
  );
  console.log(`Primary worktree dependencies are at ${currentNodeModules}.`);
  process.exit(0);
}

const currentIsSymlink = currentKind?.isSymbolicLink() ?? false;
const currentTarget = currentIsSymlink
  ? await fs.realpath(currentNodeModules).catch(() => '')
  : '';
const primaryTarget = primaryKind ? await fs.realpath(primaryNodeModules).catch(() => '') : '';
const canShare = currentSignature === primarySignature && primaryKind?.isDirectory();

if (canShare) {
  if (currentIsSymlink && currentTarget === primaryTarget) {
    console.log(`Reusing ${primaryNodeModules} through the existing node_modules link.`);
  } else if (!currentKind) {
    await fs.symlink(primaryNodeModules, currentNodeModules, 'dir');
    console.log(`Linked node_modules to ${primaryNodeModules}.`);
  } else if (currentIsSymlink) {
    await fs.unlink(currentNodeModules);
    await fs.symlink(primaryNodeModules, currentNodeModules, 'dir');
    console.log(`Updated node_modules link to ${primaryNodeModules}.`);
  } else if (previousState.mode === 'local' && previousState.primaryRoot === primaryRoot) {
    await fs.rm(currentNodeModules, { recursive: true, force: true });
    await fs.symlink(primaryNodeModules, currentNodeModules, 'dir');
    console.log(`Replaced the setup-managed local copy with a link to ${primaryNodeModules}.`);
  } else {
    console.log('Keeping the existing worktree-local node_modules directory.');
  }
} else {
  if (currentIsSymlink) {
    await fs.unlink(currentNodeModules);
    console.log('Detached node_modules because the dependency manifests differ.');
  }
  const state = JSON.parse(await fs.readFile(dependencyStateFile, 'utf8').catch(() => '{}'));
  if (state.mode !== 'local' || state.signature !== currentSignature) installLocally();
}

await fs.writeFile(
  dependencyStateFile,
  `${JSON.stringify({
    mode: canShare && (await pathKind(currentNodeModules))?.isSymbolicLink() ? 'shared' : 'local',
    primaryRoot,
    signature: currentSignature,
    managed: true,
  }, null, 2)}\n`
);
