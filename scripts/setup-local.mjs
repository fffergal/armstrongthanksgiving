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

wp(['theme', 'activate', 'armstrong-thanksgiving']);
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

if (!wp(['post', 'list', '--post_type=page', '--name=albums', '--format=ids'], { allowFailure: true }).stdout?.trim()) {
  wp(['post', 'create', '--post_type=page', '--post_title=Albums', '--post_name=albums', '--post_status=publish', '--post_content=[wppa type="generic"]']);
}

if (!wp(['post', 'list', '--post_type=forum', '--name=gathering', '--format=ids'], { allowFailure: true }).stdout?.trim()) {
  wp(['post', 'create', '--post_type=forum', '--post_title=The Gathering', '--post_name=gathering', '--post_status=publish']);
}

wp(['rewrite', 'flush']);
