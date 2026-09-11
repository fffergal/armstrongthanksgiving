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

function ensurePage(slug, title, content) {
  const id = existingId(['post', 'list', '--post_type=page', `--name=${slug}`, '--field=ID', '--format=ids']);
  if (id) {
    wp(['post', 'update', id, `--post_title=${title}`, `--post_content=${content}`, '--post_status=publish']);
    return id;
  }
  return wp(['post', 'create', '--post_type=page', `--post_title=${title}`, `--post_name=${slug}`, '--post_status=publish', `--post_content=${content}`, '--porcelain']).stdout?.match(/(\d+)\s*$/)?.[1] ?? '';
}

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
  "$settings = get_option('jr_ps_settings', array()); $settings['private_site'] = true; $settings['private_api'] = true; $settings['compatibility_mode'] = 'ELEMENTOR'; update_option('jr_ps_settings', $settings);"
]);

if (wp(['user', 'get', 'guest', '--field=ID'], { allowFailure: true }).status !== 0) {
  wp(['user', 'create', 'guest', 'guest@example.test', '--role=subscriber', '--user_pass=password']);
}

const homeId = ensurePage('home', 'Home', '');
ensurePage('food', 'Food & Friends', '<h2>Plan the table</h2><p>Choose what you can bring when you RSVP; the live counts stay with the host.</p><p><a class="at-button" href="/rsvp/">Open the RSVP</a></p>');
ensurePage('albums', 'Shared Albums', '<p>Photos from this dinner and the people around the table.</p><p class="at-form-note"><strong>Signed-in friends:</strong> add a photo below.</p>[wppa type="generic"]<p>[wppa type="upload" album="1"]</p>');
const forumPageId = ensurePage('forum', 'The Gathering', '<p>Use this forum for hellos, small plans, and anything that does not belong on the RSVP.</p>[bbp-forum-index]');
ensurePage('rsvp', 'RSVP', '<p class="at-form-note"><strong>One quick form</strong><br>Tell us who is coming, choose something for the table, and add any notes for the host.</p>[at_rsvp]');
const memoriesId = existingId(['post', 'list', '--post_type=page', '--name=memories', '--field=ID', '--format=ids']);
if (memoriesId) wp(['post', 'update', memoriesId, '--post_status=draft']);
if (homeId) {
  wp(['option', 'update', 'show_on_front', 'page']);
  wp(['option', 'update', 'page_on_front', homeId]);
}

const forumId = existingId(['post', 'list', '--post_type=forum', '--name=gathering', '--field=ID', '--format=ids']) || wp(['post', 'create', '--post_type=forum', '--post_title=The Gathering', '--post_name=gathering', '--post_status=publish', '--porcelain']).stdout?.match(/(\d+)\s*$/)?.[1];
if (forumPageId && forumId) {
  wp(['post', 'update', forumPageId, '--post_title=The Gathering', `--post_content=<p>Use this forum for hellos, small plans, and anything that does not belong on the RSVP.</p><p>[bbp-single-forum id="${forumId}"]</p>`, '--post_status=publish']);
}
if (forumId && !existingId(['post', 'list', '--post_type=topic', '--name=say-hello', '--field=ID', '--format=ids'])) {
	wp(['post', 'create', '--post_type=topic', '--post_title=Say hello', '--post_name=say-hello', `--post_parent=${forumId}`, '--post_status=publish', '--post_author=1', '--post_content=Share a hello, a photo, or a small plan for the day. RSVP and food choices live on the RSVP page.']);
}

wp(['rewrite', 'flush']);
