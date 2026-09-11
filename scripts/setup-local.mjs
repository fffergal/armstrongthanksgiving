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
wp(['plugin', 'deactivate', 'magic-login.latest-stable'], { allowFailure: true });
wp(['option', 'update', 'blogname', 'Armstrong Thanksgiving']);
wp(['option', 'update', 'users_can_register', '0']);
wp(['rewrite', 'structure', '/%postname%/']);
wp([
  'eval',
  "$settings = get_option('jr_ps_settings', array()); $settings['private_site'] = true; $settings['private_api'] = true; $settings['compatibility_mode'] = 'ELEMENTOR'; update_option('jr_ps_settings', $settings);"
]);

if (wp(['user', 'get', 'guest', '--field=ID'], { allowFailure: true }).status !== 0) {
  wp(['user', 'create', 'guest', 'guest@example.test', '--role=subscriber', '--user_pass=password']);
}

const homeId = ensurePage('home', 'Home', '');
ensurePage('rsvp', 'RSVP', '<div class="at-form-note"><strong>Nov 21 · 16:00</strong><br>52 Priestfield Crescent, EH16 5JG<br><strong>RSVP by Nov 14</strong></div><h2>Count me in</h2><p>Use the <a href="/forum/">gathering forum</a> to say hello, share your headcount, and add any dietary notes.</p><p><a class="at-button" href="/forum/">Open the RSVP conversation</a></p>');
ensurePage('food', 'Food & Friends', '<h2>What shall we bring?</h2><p>Use the forum to say what you are bringing so we can plan the table.</p><ul><li>Savoury food for the middle of the table</li><li>A vegetable side</li><li>Something sweet or fizzy</li></ul><p class="at-form-note"><strong>Not sure?</strong> Ask in the forum and we will decide together.</p>');
ensurePage('albums', 'Shared Albums', '<p>Photos from this dinner and previous Thanksgivings go here.</p><p class="at-form-note"><strong>Signed-in friends:</strong> add a photo below.</p>[wppa type="generic"]<p>[wppa type="upload" album="1"]</p>');
ensurePage('memories', 'Memories', '<h2>Thanksgivings past</h2><p>Share a favourite dish, tradition, or story in the forum.</p><p><a class="at-button" href="/forum/">Share a memory</a></p>');
const forumPageId = ensurePage('forum', 'The Gathering', '<p>Use this forum for RSVPs, food, plans, and photos.</p>[bbp-forum-index]');
if (homeId) {
  wp(['option', 'update', 'show_on_front', 'page']);
  wp(['option', 'update', 'page_on_front', homeId]);
}

const forumId = existingId(['post', 'list', '--post_type=forum', '--name=gathering', '--field=ID', '--format=ids']) || wp(['post', 'create', '--post_type=forum', '--post_title=The Gathering', '--post_name=gathering', '--post_status=publish', '--porcelain']).stdout?.match(/(\d+)\s*$/)?.[1];
if (forumPageId && forumId) {
  wp(['post', 'update', forumPageId, '--post_title=The Gathering', `--post_content=<p>Use this forum for RSVPs, food, plans, and photos.</p><p>[bbp-single-forum id="${forumId}"]</p>`, '--post_status=publish']);
}
if (forumId && !existingId(['post', 'list', '--post_type=topic', '--name=rsvp-roll-call', '--field=ID', '--format=ids'])) {
  wp(['post', 'create', '--post_type=topic', '--post_title=RSVP roll call: who is coming?', '--post_name=rsvp-roll-call', `--post_parent=${forumId}`, '--post_status=publish', '--post_author=1', '--post_content=Say hello, tell us who is coming, and add any food notes we should know about.']);
}

wp(['rewrite', 'flush']);
