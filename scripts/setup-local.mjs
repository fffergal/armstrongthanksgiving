import { spawnSync } from 'node:child_process';
import { readFileSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const wrapper = path.join(root, 'scripts/wp-env.mjs');
const homepageContent = readFileSync(path.join(root, 'content/pages/home.html'), 'utf8').trim();

function wp(args, { allowFailure = false } = {}) {
  const result = spawnSync(process.execPath, [wrapper, 'run', 'cli', 'wp', ...args], {
    cwd: root,
    encoding: 'utf8',
    stdio: allowFailure ? 'pipe' : 'inherit'
  });
  if (!allowFailure && result.status !== 0) process.exit(result.status ?? 1);
  return result;
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

// The GitHub Actions CLI image does not include git, which the package's VCS
// install needs. Browser tests only need the plugin itself; production
// deployment checks install and verify the WP-CLI package on the server.
if (!process.env.CI && !process.env.SKIP_WP_CLI_PACKAGE) ensureCliPackage('wp-cli/wp-super-cache-cli');
ensurePlugin('wp-super-cache');
wp(['theme', 'activate', 'armstrong-thanksgiving']);
wp(['plugin', 'activate', 'bbpress.latest-stable', 'wp-photo-album-plus.latest-stable', 'jonradio-private-site.latest-stable', 'armstrong-gathering']);
wp(['plugin', 'deactivate', 'magic-login.latest-stable'], { allowFailure: true });

// Keep fixture creation in one WP-CLI request. Starting a CLI container
// process dominates the individual option and post updates in CI.
const homepage = Buffer.from(homepageContent).toString('base64');
const code = `
  $settings = get_option('jr_ps_settings', array());
  $settings['private_site'] = false;
  $settings['private_api'] = true;
  $settings['compatibility_mode'] = 'ELEMENTOR';
  update_option('jr_ps_settings', $settings);
  update_option('blogname', 'Armstrong Thanksgiving');
  update_option('users_can_register', 0);
  update_option('_bbp_root_slug', 'community');
  update_option('_bbp_topic_slug', 'topic');
  update_option('_bbp_allow_threaded_replies', 1);
  // bbPress counts the top-level reply as one depth level, so 3 is needed for
  // two visible nested reply levels.
  update_option('_bbp_thread_replies_depth', 3);
  update_option('permalink_structure', '/%postname%/');

  // bbPress registered its post types and rewrite structures during the
  // initial WordPress bootstrap, before these slug options were updated.
  // Re-register them so the fixture routes use /community/topic/ immediately.
  if (function_exists('bbp_register_post_types')) bbp_register_post_types();
  if (function_exists('bbp_add_rewrite_tags')) bbp_add_rewrite_tags();
  if (function_exists('bbp_add_rewrite_rules')) bbp_add_rewrite_rules();
  if (function_exists('bbp_add_permastructs')) bbp_add_permastructs();

  delete_option('at_gathering_event_details');
  update_option('at_gathering_db_version', '0.5.2');
  at_gathering_maybe_upgrade();
  $event = get_option('at_gathering_event_details', false);
  if (!is_array($event) || !empty($event['date']) || !empty($event['time']) || !empty($event['address'])) {
    WP_CLI::error('Event details should be empty for admin setup.');
  }
  update_option('at_gathering_event_details', array(
    'date' => '12 December 2026',
    'time' => '6:42 pm',
    'address' => '123 Example Lane, Testville',
  ));

  if (!get_user_by('login', 'guest')) {
    $user_id = wp_create_user('guest', 'password', 'guest@example.test');
    if (is_wp_error($user_id)) WP_CLI::error($user_id->get_error_message());
    (new WP_User($user_id))->set_role('subscriber');
  }

  $ensure_page = function ($slug, $title, $content) {
    $page = get_page_by_path($slug, OBJECT, 'page');
    $post = array(
      'post_type' => 'page',
      'post_title' => $title,
      'post_name' => $slug,
      'post_status' => 'publish',
      'post_content' => $content,
    );
    if ($page) $post['ID'] = $page->ID;
    $id = $page ? wp_update_post($post, true) : wp_insert_post($post, true);
    if (is_wp_error($id)) WP_CLI::error($id->get_error_message());
    return (int) $id;
  };

  $home_id = $ensure_page('home', 'Home', base64_decode('${homepage}'));
  $food = get_page_by_path('food', OBJECT, 'page');
  if ($food) wp_delete_post($food->ID, true);

  global $wpdb;
  $album_table = $wpdb->wppa_albums ?? '';
  $album_exists = $album_table && $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $album_table));
  if (!$album_exists && function_exists('wppa_setup')) wppa_setup(true);
  $album_id = function_exists('wppa_get_album_id') ? wppa_get_album_id('Shared Photos') : 0;
  if (!$album_id && function_exists('wppa_create_album_entry')) {
    $album_id = wppa_create_album_entry(array(
      'name' => 'Shared Photos',
      'description' => 'Photos shared by the gathering.',
      'owner' => '--- public ---',
    ));
  }
  if (!$album_id) WP_CLI::error('Could not create the local Shared Photos album.');
  update_option('wppa_user_upload_on', 'yes');

  $ensure_page('albums', 'Shared Albums', '<p>After dinner, come back to share your photos and see the day through everyone else’s eyes.</p>[wppa type="generic"]');
  $forum_page_id = $ensure_page('forum', 'The Gathering', '<p>Use this forum for hellos, small plans, and anything that does not belong on the RSVP.</p>[bbp-forum-index]');
  $ensure_page('rsvp', 'RSVP', '[at_rsvp]');
  $ensure_page('rsvp-confirmation', 'RSVP confirmation', '[at_rsvp_confirmation]');
  $ensure_page('signup', 'Sign up', '[at_signup]');
  update_option('show_on_front', 'page');
  update_option('page_on_front', $home_id);

  $forum = get_page_by_path('gathering', OBJECT, 'forum');
  if (!$forum) {
    $forum_id = wp_insert_post(array(
      'post_type' => 'forum',
      'post_title' => 'The Gathering',
      'post_name' => 'gathering',
      'post_status' => 'publish',
    ), true);
    if (is_wp_error($forum_id)) WP_CLI::error($forum_id->get_error_message());
  } else {
    $forum_id = (int) $forum->ID;
  }
  if ($forum_page_id && $forum_id) {
    wp_update_post(array(
      'ID' => $forum_page_id,
      'post_title' => 'The Gathering',
      'post_content' => '<p>Use this forum for hellos, small plans, and anything that does not belong on the RSVP.</p><p>[bbp-single-forum id="' . $forum_id . '"]</p>',
      'post_status' => 'publish',
    ));
  }

  $topic = get_page_by_path('say-hello', OBJECT, 'topic');
  if ($forum_id && !$topic) {
    $topic_id = wp_insert_post(array(
      'post_type' => 'topic',
      'post_title' => 'Say hello',
      'post_name' => 'say-hello',
      'post_parent' => $forum_id,
      'post_status' => 'publish',
      'post_author' => 1,
      'post_content' => 'Share a hello, a photo, or a small plan for the day. RSVP and food choices live on the RSVP page.',
    ), true);
    if (is_wp_error($topic_id)) WP_CLI::error($topic_id->get_error_message());
  } else {
    $topic_id = $topic ? (int) $topic->ID : 0;
  }
  if ($forum_id && $topic_id) {
    // Seeded topics need bbPress's own metadata so the forum counts and topic
    // loop include them (a plain wp post is not enough for bbPress).
    wp_update_post(array('ID' => $topic_id, 'post_parent' => $forum_id, 'post_author' => 1));
    update_post_meta($topic_id, '_bbp_forum_id', $forum_id);
    update_post_meta($topic_id, '_bbp_topic_id', $topic_id);
    update_post_meta($topic_id, '_bbp_voice_count', 1);
    update_post_meta($topic_id, '_bbp_reply_count', 0);
    update_post_meta($topic_id, '_bbp_reply_count_hidden', 0);
    update_post_meta($topic_id, '_bbp_last_reply_id', 0);
    update_post_meta($topic_id, '_bbp_last_active_id', $topic_id);
    update_post_meta($topic_id, '_bbp_last_active_time', get_post_field('post_date', $topic_id, 'db'));
    bbp_update_forum_topic_count($forum_id);
    bbp_update_forum_topic_count_hidden($forum_id);
  }
  flush_rewrite_rules();
`;
wp(['eval', code]);
