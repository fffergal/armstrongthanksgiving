import fs from 'node:fs/promises';
import os from 'node:os';
import path from 'node:path';
import { spawn } from 'node:child_process';
import { fileURLToPath } from 'node:url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const sourceTheme = path.join(root, 'wp-content/themes/armstrong-thanksgiving');
const stagingRoot = path.join(os.tmpdir(), 'armstrong-thanksgiving-wp-env');
const stagedTheme = path.join(stagingRoot, 'armstrong-thanksgiving');
const stagedConfig = path.join(stagingRoot, 'wp-env.json');
const command = process.argv.slice(2);

await fs.mkdir(stagingRoot, { recursive: true });
if (command[0] === 'start' || !(await fs.stat(stagedTheme).catch(() => false))) {
  await fs.rm(stagedTheme, { recursive: true, force: true });
  await fs.cp(sourceTheme, stagedTheme, { recursive: true });
}

const config = JSON.parse(await fs.readFile(path.join(root, '.wp-env.json'), 'utf8'));
config.themes = [stagedTheme];
await fs.writeFile(stagedConfig, `${JSON.stringify(config, null, 2)}\n`);

const child = spawn(path.join(root, 'node_modules/.bin/wp-env'), [...command, '--config', stagedConfig], {
  cwd: root,
  stdio: 'inherit'
});
child.on('exit', code => process.exit(code ?? 1));
