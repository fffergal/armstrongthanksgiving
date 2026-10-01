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
const help = direction === '--help' || direction === '-h' || args.includes('--help') || args.includes('-h');
let confirm = false;
let baselineSha;
const unknownArgs = [];
for (let index = 0; index < args.length; index += 1) {
  if (args[index] === '--confirm') confirm = true;
  else if (args[index] === '--baseline-sha' && args[index + 1]) {
    baselineSha = args[index + 1];
    index += 1;
  } else unknownArgs.push(args[index]);
}

const sshHost = process.env.PROD_SSH_HOST ?? 'armstrongthanksgiving.com';
const sshUser = process.env.PROD_SSH_USER ?? 'dh_mbpyvr';
const wpPath = process.env.PROD_WP_PATH ?? '/home/dh_mbpyvr/armstrongthanksgiving.com';
const sshTarget = `${sshUser}@${sshHost}`;
const releaseId = `${Date.now()}-${crypto.randomBytes(4).toString('hex')}`;
const remoteContentPath = `/tmp/armstrong-home-${releaseId}.html`;
const remoteBaselinePath = `/tmp/armstrong-home-baseline-${releaseId}.html`;

function usage() {
  console.log(`Usage:
  npm run sync:block-editor -- pull
  npm run sync:block-editor -- push --confirm [--baseline-sha <main-before-sha>]

pull reads the published Home page from production into content/pages/home.html.
push updates the published Home page from the committed local content file.
The push command requires --confirm because it changes production. When a
baseline SHA is provided, it updates only if production still matches that
baseline or the incoming content, checked under a database row lock.
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
if (baselineSha && direction !== 'push') {
  usage();
  throw new Error('--baseline-sha is only supported by the push command.');
}
if (baselineSha && !/^[0-9a-f]{40}$/i.test(baselineSha)) {
  usage();
  throw new Error('--baseline-sha must be a valid commit SHA.');
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
wp post get "$home_id" --field=post_content`)], { quiet: true });
  await fs.writeFile(contentPath, `${content.trimEnd()}\n`);
  console.log(`Pulled production Home page into ${path.relative(root, contentPath)}.`);
}

async function push() {
  const changes = run('git', ['status', '--porcelain', '--untracked-files=all', '--', 'content/pages/home.html'], { quiet: true });
  if (changes) throw new Error(`The block-editor content has uncommitted changes:\n${changes}`);
  const content = await fs.readFile(contentPath, 'utf8');
  if (!content.trim()) throw new Error(`${contentPath} is empty.`);
  const baseline = baselineSha
    ? run('git', ['show', `${baselineSha}:content/pages/home.html`], { quiet: true })
    : undefined;

  try {
    run('ssh', [...sshArgs, `umask 077; cat > ${quoteShell(remoteContentPath)}`], { input: content, quiet: true });
    if (baseline !== undefined) {
      run('ssh', [...sshArgs, `umask 077; cat > ${quoteShell(remoteBaselinePath)}`], { input: baseline, quiet: true });
    }
    const baselineCheck = baseline !== undefined
      ? `\$baseline = file_get_contents(${JSON.stringify(remoteBaselinePath)}); if (rtrim(\$current) !== rtrim(\$baseline) && rtrim(\$current) !== rtrim(\$content)) { throw new RuntimeException("Production Home page has unpublished editor changes. Refusing to overwrite it; sync and reconcile those changes before deploying new Home page content."); }`
      : '';
    const php = [
      `\$home_id = (int) getenv("AT_HOME_ID")`,
      `\$content = file_get_contents(${JSON.stringify(remoteContentPath)})`,
      `if (!\$content) { throw new RuntimeException("Empty block-editor content"); }`,
      `global \$wpdb`,
      `try { if (\$wpdb->query("START TRANSACTION") === false) { throw new RuntimeException("Could not start the production content transaction."); }`,
      `\$engine = \$wpdb->get_var(\$wpdb->prepare("SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s", \$wpdb->posts)); if (strtoupper((string) \$engine) !== "INNODB") { throw new RuntimeException("The production posts table does not support a safe conditional publish."); }`,
      `\$row = \$wpdb->get_row(\$wpdb->prepare("SELECT post_content FROM {\$wpdb->posts} WHERE ID = %d FOR UPDATE", \$home_id));`,
      `if (!\$row) { throw new RuntimeException("The configured front page no longer exists."); }`,
      `\$current = \$row->post_content`,
      baselineCheck,
      `\$result = wp_update_post(["ID" => \$home_id, "post_content" => wp_slash(\$content), "post_status" => "publish"], true)`,
      `if (is_wp_error(\$result)) { throw new RuntimeException(\$result->get_error_message()); } if (\$result === false || (\$result === 0 && \$current !== \$content)) { throw new RuntimeException("WordPress did not update the production Home page."); }`,
      `\$stored = \$wpdb->get_var(\$wpdb->prepare("SELECT post_content FROM {\$wpdb->posts} WHERE ID = %d", \$home_id)); if (\$stored !== \$content) { throw new RuntimeException("The saved production Home page does not match the incoming content."); }`,
      `if (\$wpdb->query("COMMIT") === false) { throw new RuntimeException("Could not commit the production content update."); } } catch (Throwable \$error) { \$wpdb->query("ROLLBACK"); clean_post_cache(\$home_id); wp_cache_flush(); fwrite(STDERR, \$error->getMessage() . "\\n"); exit(1); }`,
    ].join('; ');
    run('ssh', [...sshArgs, remoteScript(`${homePageLookup()}
if ! AT_HOME_ID="$home_id" wp eval ${quoteShell(php)}; then
  wp cache flush
  wp super-cache flush
  exit 1
fi
wp cache flush
wp super-cache flush`)], {
      quiet: true,
    });
  } finally {
    run('ssh', [...sshArgs, `rm -f ${quoteShell(remoteContentPath)} ${quoteShell(remoteBaselinePath)}`], { quiet: true });
  }
  console.log(`Published the committed Home page content${baselineSha ? ' after confirming production still matched the accepted baseline under a database row lock' : ''} and flushed the WordPress object and page caches. Run \`npm run verify:production\` after the deployment workflow completes.`);
}

if (direction === 'pull') await pull();
else await push();
