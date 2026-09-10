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
wp(['plugin', 'activate', 'bbpress.latest-stable', 'wp-photo-album-plus.latest-stable', 'magic-login.latest-stable', 'jonradio-private-site.latest-stable']);
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
ensurePage('food', 'Food & Friends', '<h2>What shall we bring?</h2><p>This is a collaborative menu board, not a test. Add a reply in the forum when you know what you would like to bring.</p><ul><li>Something savoury for the middle of the table</li><li>A vegetable that deserves a little attention</li><li>Something sweet, crisp, fizzy, or unexpectedly excellent</li><li>Maybe it will be a fun midwest salad?</li></ul><p class="at-form-note"><strong>Tip:</strong> If you are unsure, claim a category and we will coordinate the details together.</p>');
ensurePage('albums', 'Shared Albums', '<p>Photos from this Thanksgiving, previous Thanksgivings, and anything that helps prove we were all there.</p><p class="at-form-note"><strong>Signed-in friends:</strong> add a favourite photo below when you are ready.</p>[wppa type="generic"]<p>[wppa type="upload" album="1"]</p>');
ensurePage('memories', 'Memories', '<h2>Reminisce on Thanksgivings past</h2><p>Use this page for the stories that do not fit in a group chat: favourite dishes, accidental traditions, legendary leftovers, and the year someone brought the wrong pie.</p><p><a class="at-button" href="/forum/">Share a memory in the forum</a></p>');
const forumPageId = ensurePage('forum', 'The Gathering', '<p>Plans, questions, logistics, and the gentle business of deciding who is bringing what.</p>[bbp-forum-index]');
if (homeId) {
  wp(['option', 'update', 'show_on_front', 'page']);
  wp(['option', 'update', 'page_on_front', homeId]);
}

const forumId = existingId(['post', 'list', '--post_type=forum', '--name=gathering', '--field=ID', '--format=ids']) || wp(['post', 'create', '--post_type=forum', '--post_title=The Gathering', '--post_name=gathering', '--post_status=publish', '--porcelain']).stdout?.match(/(\d+)\s*$/)?.[1];
if (forumPageId && forumId) {
  wp(['post', 'update', forumPageId, '--post_title=The Gathering', `--post_content=<p>Our friends-only conversation space.</p><h2>Thanksgiving planning</h2><p>Share plans, questions, recipes, and the post-dinner debrief here.</p><p>[bbp-single-forum id="${forumId}"]</p>`, '--post_status=publish']);
}
if (forumId && !existingId(['post', 'list', '--post_type=topic', '--name=rsvp-roll-call', '--field=ID', '--format=ids'])) {
  wp(['post', 'create', '--post_type=topic', '--post_title=RSVP roll call: who is coming?', '--post_name=rsvp-roll-call', `--post_parent=${forumId}`, '--post_status=publish', '--post_author=1', '--post_content=Say hello, tell us who is coming, and add any food notes we should know about.']);
}

wp(['rewrite', 'flush']);
