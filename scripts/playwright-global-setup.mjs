import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { spawnSync } from 'node:child_process';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const runtimeFile = path.join(root, '.worktree/runtime.json');
let runtime = {};
try {
  runtime = JSON.parse(fs.readFileSync(runtimeFile, 'utf8'));
} catch {
  // A non-worktree setup can still use the default wp-env URL.
}

const configuredBaseURL = process.env.BASE_URL?.trim();
const explicitNonDefaultBaseURL = configuredBaseURL && configuredBaseURL !== 'http://localhost:8888';
const baseURL = process.env.USE_WORKTREE_RUNTIME === '0' || explicitNonDefaultBaseURL
  ? configuredBaseURL || 'http://localhost:8888'
  : runtime.url || 'http://localhost:8888';
const target = new URL(baseURL);
const isLoopback = ['localhost', '127.0.0.1', '[::1]', '::1'].includes(target.hostname);
const isWorktreeRuntime = Boolean(runtime.url) && new URL(runtime.url).origin === target.origin;
const isDefaultWpEnv = !runtime.url && target.origin === 'http://localhost:8888';

export default async function globalSetup() {
  if (!isLoopback || (!isWorktreeRuntime && !isDefaultWpEnv)) {
    console.log(`Skipping local test-data reset for ${target.origin}.`);
    return;
  }

  console.log(`Resetting disposable WordPress test data at ${target.origin}...`);
  const result = spawnSync(process.execPath, [path.join(root, 'scripts/prepare-tests.mjs')], {
    cwd: root,
    stdio: 'inherit',
    env: process.env,
  });
  if (result.error) throw result.error;
  if (result.status !== 0) throw new Error(`Local test-data reset failed with exit code ${result.status ?? 1}.`);
}
