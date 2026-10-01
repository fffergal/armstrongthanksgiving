#!/usr/bin/env node

import crypto from 'node:crypto';
import fs from 'node:fs/promises';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { spawnSync } from 'node:child_process';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const contentPath = path.join(root, 'content/pages/home.html');
const productionURL = 'https://www.armstrongthanksgiving.com';
const args = process.argv.slice(2);
const direction = args.shift();
const confirm = args.includes('--confirm');
const help = direction === '--help' || direction === '-h' || args.includes('--help') || args.includes('-h');
const unknownArgs = args.filter(arg => !['--confirm', '--help', '-h'].includes(arg));

const sshHost = process.env.PROD_SSH_HOST ?? 'armstrongthanksgiving.com';
const sshUser = process.env.PROD_SSH_USER ?? 'dh_mbpyvr';
const wpPath = process.env.PROD_WP_PATH ?? '/home/dh_mbpyvr/armstrongthanksgiving.com';
const sshTarget = `${sshUser}@${sshHost}`;
const releaseId = `${Date.now()}-${crypto.randomBytes(4).toString('hex')}`;
const remoteContentPath = `/tmp/armstrong-home-${releaseId}.html`;

function usage() {
  console.log(`Usage:
  npm run sync:block-editor -- pull
  npm run sync:block-editor -- push --confirm

pull reads the published Home page from production into content/pages/home.html.
push updates the published Home page from the committed local content file.
The push command requires --confirm because it changes production.
`);
}

if (help) {
  usage();
  process.exit(0);
}

if (!['pull', 'push'].includes(direction)) {
  usage();
  throw new Error('Choose exactly one direction: pull or push.');
}
if (unknownArgs.length) {
  usage();
  throw new Error(`Unknown option(s): ${unknownArgs.join(', ')}`);
}
if (direction === 'push' && !confirm) {
  usage();
  throw new Error('Refusing to change production without --confirm.');
}

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
  return result.stdout.trimEnd();
}

const sshArgs = ['-o', 'BatchMode=yes', '-o', 'StrictHostKeyChecking=yes', sshTarget];

function remoteScript(body) {
  return `set -eu
cd ${quoteShell(wpPath)}
home_url="$(wp option get home)"
site_url="$(wp option get siteurl)"
case "$home_url" in
  ${quoteShell(productionURL)}|${quoteShell(`${productionURL}/`)}) ;;
  *) printf 'Unexpected WordPress home URL: %s\\n' "$home_url" >&2; exit 1 ;;
esac
case "$site_url" in
  ${quoteShell(productionURL)}|${quoteShell(`${productionURL}/`)}) ;;
  *) printf 'Unexpected WordPress site URL: %s\\n' "$site_url" >&2; exit 1 ;;
esac
${body}
`;
}

function homePageLookup() {
  return `front_type="$(wp option get show_on_front)"
if [ "$front_type" != page ]; then
  printf 'The production front page is not configured as a page.\\n' >&2
  exit 1
fi
home_id="$(wp option get page_on_front)"
if [ -z "$home_id" ] || [ "$home_id" = 0 ]; then
  printf 'Could not find the configured production front page.\\n' >&2
  exit 1
fi
home_post_type="$(wp post get "$home_id" --field=post_type)"
home_status="$(wp post get "$home_id" --field=post_status)"
if [ "$home_post_type" != page ] || [ "$home_status" != publish ]; then
  printf 'The configured production front page is not a published page.\\n' >&2
  exit 1
fi`;
}

async function pull() {
  const content = run('ssh', [...sshArgs, remoteScript(`${homePageLookup()}
wp post get "$home_id" --field=post_content --format=plaintext`)], { quiet: true });
  await fs.writeFile(contentPath, `${content.trimEnd()}\n`);
  console.log(`Pulled production Home page into ${path.relative(root, contentPath)}.`);
}

async function push() {
  const changes = run('git', ['status', '--porcelain', '--untracked-files=all', '--', 'content/pages/home.html'], { quiet: true });
  if (changes) throw new Error(`The block-editor content has uncommitted changes:\n${changes}`);
  const content = await fs.readFile(contentPath, 'utf8');
  if (!content.trim()) throw new Error(`${contentPath} is empty.`);

  run('ssh', [...sshArgs, `umask 077; cat > ${quoteShell(remoteContentPath)}`], { input: content, quiet: true });
  try {
    run('ssh', [...sshArgs, remoteScript(`${homePageLookup()}
AT_HOME_ID="$home_id" wp eval '[$home_id, $content] = [(int) getenv("AT_HOME_ID"), file_get_contents(${JSON.stringify(remoteContentPath)})]; if (!$content) { fwrite(STDERR, "Empty block-editor content\\n"); exit(1); } wp_update_post(["ID" => $home_id, "post_content" => $content, "post_status" => "publish"]);'
wp cache flush
wp super-cache flush`)], {
      quiet: true,
    });
  } finally {
    run('ssh', [...sshArgs, `rm -f ${quoteShell(remoteContentPath)}`], { quiet: true });
  }
  console.log('Published the committed Home page content and flushed the WordPress object and page caches. Run `npm run verify:production` after the deployment workflow completes.');
}

if (direction === 'pull') await pull();
else await push();
