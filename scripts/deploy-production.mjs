#!/usr/bin/env node

import crypto from 'node:crypto';
import fs from 'node:fs/promises';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { spawnSync } from 'node:child_process';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const productionURL = 'https://www.armstrongthanksgiving.com/';
const components = {
  plugin: {
    slug: 'armstrong-gathering',
    source: 'wp-content/plugins/armstrong-gathering',
    remoteDirectory: 'wp-content/plugins',
    command: 'plugin',
  },
  theme: {
    slug: 'armstrong-thanksgiving',
    source: 'wp-content/themes/armstrong-thanksgiving',
    remoteDirectory: 'wp-content/themes',
    command: 'theme',
  },
};

const args = process.argv.slice(2);
const componentType = args.shift();
const dryRun = args.includes('--dry-run');
const confirm = args.includes('--confirm');
const activate = args.includes('--activate');
const help = componentType === '--help' || componentType === '-h' || args.includes('--help') || args.includes('-h');
const unknownArgs = args.filter(arg => !['--dry-run', '--confirm', '--activate', '--help', '-h'].includes(arg));

function usage() {
  console.log(`Usage:
  npm run deploy:production -- plugin --dry-run
  npm run deploy:production -- plugin --confirm
  npm run deploy:production -- theme --confirm --activate

The deployment requires --confirm because it changes production. The ZIP is
created from the committed HEAD state of the selected component. Existing
activation is preserved; pass --activate to activate the component explicitly.

Optional environment overrides:
  PROD_SSH_HOST       SSH host (default: armstrongthanksgiving.com)
  PROD_SSH_USER       SSH user (default: dh_mbpyvr)
  PROD_WP_PATH        WordPress root (default: /home/dh_mbpyvr/armstrongthanksgiving.com)
  PROD_BACKUP_DIR     Backup directory (default: /home/dh_mbpyvr/armstrongthanksgiving-backups)
`);
}

if (help) {
  usage();
  process.exit(0);
}

if (!components[componentType]) {
  usage();
  throw new Error('Choose exactly one component: plugin or theme.');
}

if (unknownArgs.length) {
  usage();
  throw new Error(`Unknown option(s): ${unknownArgs.join(', ')}`);
}

if (!dryRun && !confirm) {
  usage();
  throw new Error('Refusing to change production without --confirm.');
}

const component = components[componentType];
const sshHost = process.env.PROD_SSH_HOST ?? 'armstrongthanksgiving.com';
const sshUser = process.env.PROD_SSH_USER ?? 'dh_mbpyvr';
const wpPath = process.env.PROD_WP_PATH ?? '/home/dh_mbpyvr/armstrongthanksgiving.com';
const backupDirectory = process.env.PROD_BACKUP_DIR ?? '/home/dh_mbpyvr/armstrongthanksgiving-backups';
const sshTarget = `${sshUser}@${sshHost}`;
const releaseId = `${new Date().toISOString().replaceAll(/[-:.]/g, '').replace('Z', '')}-${crypto.randomBytes(4).toString('hex')}`;
const archiveDirectory = path.join(root, '.deploy');
const archivePath = path.join(archiveDirectory, `${component.slug}-${releaseId}.zip`);
const remoteArchivePath = `/tmp/${component.slug}-${releaseId}.zip`;

function quoteShell(value) {
  return `'${String(value).replaceAll("'", "'\\''")}'`;
}

function run(command, commandArgs, { input, quiet = false } = {}) {
  const result = spawnSync(command, commandArgs, {
    cwd: root,
    encoding: 'utf8',
    input,
    stdio: ['pipe', 'pipe', 'pipe'],
  });
  if (!quiet && result.stdout) process.stdout.write(result.stdout);
  if (result.status !== 0) {
    if (result.stderr) process.stderr.write(result.stderr);
    throw new Error(`${command} exited with status ${result.status ?? 'unknown'}`);
  }
  return result.stdout.trim();
}

function assertComponentIsCommitted() {
  const changes = run('git', ['status', '--porcelain', '--untracked-files=all', '--', component.source], { quiet: true });
  if (changes) {
    throw new Error(`The ${componentType} source has uncommitted changes:\n${changes}`);
  }
}

async function buildArchive() {
  await fs.mkdir(archiveDirectory, { recursive: true });
  run('git', [
    'archive',
    '--format=zip',
    `--prefix=${component.slug}/`,
    '--output',
    archivePath,
    `HEAD:${component.source}`,
  ]);
  const archive = await fs.stat(archivePath);
  if (archive.size === 0) throw new Error(`Created an empty archive: ${archivePath}`);
  const digest = run('shasum', ['-a', '256', archivePath], { quiet: true }).split(/\s+/)[0];
  console.log(`Created ${archivePath} (${archive.size} bytes)`);
  console.log(`SHA-256: ${digest}`);
  return digest;
}

function remoteScript(archiveDigest) {
  const remoteComponentPath = `${wpPath}/${component.remoteDirectory}/${component.slug}`;
  const remoteParentPath = `${wpPath}/${component.remoteDirectory}`;
  const componentBackup = `${backupDirectory}/${component.slug}-${releaseId}.tar.gz`;
  const databaseBackup = `${backupDirectory}/database-${releaseId}.sql`;
  const activation = component.command === 'plugin' ? 'plugin' : 'theme';
  const install = `wp ${component.command} install "$remote_archive" --force`;

  return `set -eu
umask 077
remote_archive=${quoteShell(remoteArchivePath)}
backup_directory=${quoteShell(backupDirectory)}
component_path=${quoteShell(remoteComponentPath)}
component_parent=${quoteShell(remoteParentPath)}
component_backup=${quoteShell(componentBackup)}
database_backup=${quoteShell(databaseBackup)}
lock_directory="$backup_directory/.deploy.lock"
cleanup() {
  rm -f "$remote_archive"
  rmdir "$lock_directory" 2>/dev/null || true
}
trap cleanup EXIT
cd ${quoteShell(wpPath)}
wp core is-installed
wp cli has-command super-cache
expected_url='https://www.armstrongthanksgiving.com'
home_url="$(wp option get home)"
site_url="$(wp option get siteurl)"
case "$home_url" in
  "$expected_url"|"$expected_url"/) ;;
  *) printf 'Unexpected WordPress home URL: %s\\n' "$home_url" >&2; exit 1 ;;
esac
case "$site_url" in
  "$expected_url"|"$expected_url"/) ;;
  *) printf 'Unexpected WordPress site URL: %s\\n' "$site_url" >&2; exit 1 ;;
esac
mkdir -p "$backup_directory"
chmod 700 "$backup_directory"
if ! mkdir "$lock_directory"; then
  printf 'Another production deployment is already running.\\n' >&2
  exit 1
fi
expected_digest=${quoteShell(archiveDigest)}
actual_digest="$(sha256sum "$remote_archive" | awk '{print $1}')"
if [ "$actual_digest" != "$expected_digest" ]; then
  printf 'Archive digest mismatch: expected %s, got %s\\n' "$expected_digest" "$actual_digest" >&2
  exit 1
fi
if wp ${activation} is-active ${quoteShell(component.slug)} >/dev/null 2>&1; then
  active_before=yes
else
  active_before=no
fi
printf 'Before: ${component.slug} active=%s\\n' "$active_before"
wp db export "$database_backup" --porcelain
if [ -d "$component_path" ]; then
  tar -czf "$component_backup" -C "$component_parent" ${quoteShell(component.slug)}
fi
${install}
if [ "$active_before" = yes ] || [ ${activate ? 'yes' : 'no'} = yes ]; then
  wp ${activation} activate ${quoteShell(component.slug)}
fi
wp ${activation} is-installed ${quoteShell(component.slug)}
if [ "$active_before" = yes ] || [ ${activate ? 'yes' : 'no'} = yes ]; then
  wp ${activation} is-active ${quoteShell(component.slug)}
fi
# bbPress counts the top-level reply as one depth level, so 3 is needed for
# two visible nested reply levels.
wp option update _bbp_allow_threaded_replies 1
wp option update _bbp_thread_replies_depth 3
wp cache flush
wp super-cache flush
printf 'Deployed ${component.slug}; database backup: %s; component backup: %s\\n' "$database_backup" "$component_backup"
`;
}

async function deployArchive(archiveDigest) {
  console.log(`Uploading ${path.basename(archivePath)} to ${sshTarget}`);
  const archiveContents = await fs.readFile(archivePath);
  run('ssh', [
    '-o', 'BatchMode=yes',
    '-o', 'StrictHostKeyChecking=yes',
    sshTarget,
    `umask 077; cat > ${quoteShell(remoteArchivePath)}`,
  ], { input: archiveContents });
  console.log(`Running WP-CLI deployment in ${wpPath}`);
  run('ssh', [
    '-o', 'BatchMode=yes',
    '-o', 'StrictHostKeyChecking=yes',
    sshTarget,
    remoteScript(archiveDigest),
  ]);
}

assertComponentIsCommitted();
const archiveDigest = await buildArchive();

if (dryRun) {
  console.log(`Dry run only; no files or settings changed on ${sshTarget}.`);
  process.exit(0);
}

await deployArchive(archiveDigest);
console.log('Deployment complete. Object cache and WP Super Cache page cache flushed. Run `npm run verify:production` and the production browser/read-only acceptance checks.');
