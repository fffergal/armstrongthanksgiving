import { spawnSync } from 'node:child_process';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const wrapper = path.join(root, 'scripts/wp-env.mjs');

function wp(args, { allowFailure = false } = {}) {
  const result = spawnSync(process.execPath, [wrapper, 'run', 'cli', 'wp', ...args], {
    cwd: root,
    encoding: 'utf8',
    stdio: allowFailure ? 'pipe' : 'inherit'
  });
  if (!allowFailure && result.status !== 0) process.exit(result.status ?? 1);
  return result;
}

function existingId(args) {
  const result = wp(args, { allowFailure: true });
  return result.stdout?.match(/(\d+)\s*$/)?.[1] ?? '';
}

function ensureCliPackage(packageName) {
  const installed = wp(['package', 'list', '--fields=name', '--format=csv'], { allowFailure: true });
  const packages = installed.stdout?.split(/\r?\n/).map(value => value.trim()).filter(Boolean) ?? [];
  if (!packages.includes(packageName)) wp(['package', 'install', packageName]);
}

function ensurePlugin(pluginSlug) {
  if (wp(['plugin', 'is-installed', pluginSlug], { allowFailure: true }).status !== 0) {
    wp(['plugin', 'install', pluginSlug]);
  }
  wp(['plugin', 'activate', pluginSlug]);
}

function ensurePage(slug, title, content) {
  const id = existingId(['post', 'list', '--post_type=page', `--name=${slug}`, '--field=ID', '--format=ids']);
  if (id) {
    wp(['post', 'update', id, `--post_title=${title}`, `--post_content=${content}`, '--post_status=publish']);
    return id;
  }
  wp(['post', 'create', '--post_type=page', `--post_title=${title}`, `--post_name=${slug}`, '--post_status=publish', `--post_content=${content}`, '--porcelain']);
  return existingId(['post', 'list', '--post_type=page', `--name=${slug}`, '--field=ID', '--format=ids']);
}

function removePage(slug) {
  const id = existingId(['post', 'list', '--post_type=page', `--name=${slug}`, '--field=ID', '--format=ids']);
  if (id) wp(['post', 'delete', id, '--force']);
}

ensureCliPackage('wp-cli/wp-super-cache-cli');
ensurePlugin('wp-super-cache');
wp(['theme', 'activate', 'armstrong-thanksgiving']);
wp(['plugin', 'activate', 'bbpress.latest-stable', 'wp-photo-album-plus.latest-stable', 'jonradio-private-site.latest-stable']);
wp(['plugin', 'activate', 'armstrong-gathering']);
wp(['plugin', 'deactivate', 'magic-login.latest-stable'], { allowFailure: true });
wp(['option', 'update', 'blogname', 'Armstrong Thanksgiving']);
wp(['option', 'update', 'users_can_register', '0']);
wp(['option', 'update', '_bbp_root_slug', 'community']);
wp(['option', 'update', '_bbp_topic_slug', 'topic']);
wp(['rewrite', 'structure', '/%postname%/']);
wp([
  'eval',
  "$settings = get_option('jr_ps_settings', array()); $settings['private_site'] = false; $settings['private_api'] = true; $settings['compatibility_mode'] = 'ELEMENTOR'; update_option('jr_ps_settings', $settings);"
]);

if (wp(['user', 'get', 'guest', '--field=ID'], { allowFailure: true }).status !== 0) {
  wp(['user', 'create', 'guest', 'guest@example.test', '--role=subscriber', '--user_pass=password']);
}

const homeId = ensurePage('home', 'Home', '');
removePage('food');
ensurePage('albums', 'Shared Albums', '<p>After dinner, come back to share your photos and see the day through everyone else’s eyes.</p>[wppa type="generic"]<p>[wppa type="upload" album="1"]</p>');
const forumPageId = ensurePage('forum', 'The Gathering', '<p>Use this forum for hellos, small plans, and anything that does not belong on the RSVP.</p>[bbp-forum-index]');
ensurePage('rsvp', 'RSVP', '[at_rsvp]');
ensurePage('rsvp-confirmation', 'RSVP confirmation', '[at_rsvp_confirmation]');
ensurePage('signup', 'Sign up', '[at_signup]');
if (homeId) {
  wp(['option', 'update', 'show_on_front', 'page']);
  wp(['option', 'update', 'page_on_front', homeId]);
}

let forumId = existingId(['post', 'list', '--post_type=forum', '--name=gathering', '--field=ID', '--format=ids']);
if (!forumId) {
	wp(['post', 'create', '--post_type=forum', '--post_title=The Gathering', '--post_name=gathering', '--post_status=publish', '--porcelain']);
	forumId = existingId(['post', 'list', '--post_type=forum', '--name=gathering', '--field=ID', '--format=ids']);
}
if (forumPageId && forumId) {
  wp(['post', 'update', forumPageId, '--post_title=The Gathering', `--post_content=<p>Use this forum for hellos, small plans, and anything that does not belong on the RSVP.</p><p>[bbp-single-forum id="${forumId}"]</p>`, '--post_status=publish']);
}
let topicId = existingId(['post', 'list', '--post_type=topic', '--name=say-hello', '--field=ID', '--format=ids']);
if (forumId && !topicId) {
	wp(['post', 'create', '--post_type=topic', '--post_title=Say hello', '--post_name=say-hello', `--post_parent=${forumId}`, '--post_status=publish', '--post_author=1', '--post_content=Share a hello, a photo, or a small plan for the day. RSVP and food choices live on the RSVP page.']);
	topicId = existingId(['post', 'list', '--post_type=topic', '--name=say-hello', '--field=ID', '--format=ids']);
}
if (forumId && topicId) {
  // Seeded topics need bbPress's own metadata so the forum counts and topic
  // loop include them (a plain wp post is not enough for bbPress).
  wp([
    'eval',
    `$topic_id = ${topicId}; wp_update_post(array('ID' => $topic_id, 'post_parent' => ${forumId}, 'post_author' => 1)); update_post_meta($topic_id, '_bbp_forum_id', ${forumId}); update_post_meta($topic_id, '_bbp_topic_id', $topic_id); update_post_meta($topic_id, '_bbp_voice_count', 1); update_post_meta($topic_id, '_bbp_reply_count', 0); update_post_meta($topic_id, '_bbp_reply_count_hidden', 0); update_post_meta($topic_id, '_bbp_last_reply_id', 0); update_post_meta($topic_id, '_bbp_last_active_id', $topic_id); update_post_meta($topic_id, '_bbp_last_active_time', get_post_field('post_date', $topic_id, 'db')); bbp_update_forum_topic_count(${forumId}); bbp_update_forum_topic_count_hidden(${forumId});`,
  ]);
}

wp(['rewrite', 'flush']);
