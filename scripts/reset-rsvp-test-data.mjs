import { spawnSync } from 'node:child_process';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const wrapper = path.join(root, 'scripts/wp-env.mjs');
const cleanup = [
	'global $wpdb;',
	"$test_user = getenv('WP_TEST_USER') ?: 'guest';",
	"foreach (get_users(array('fields' => 'all')) as $user) { if ($user->user_login !== $test_user && ! in_array('administrator', (array) $user->roles, true)) { wp_delete_user($user->ID); } }",
	"$table = $wpdb->prefix . 'at_rsvps'; if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table) { $wpdb->query('TRUNCATE TABLE ' . $table); }",
	"delete_transient('at_gathering_last_test_mail');",
	`$wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_at_gathering_form_%' OR option_name LIKE '_transient_timeout_at_gathering_form_%'");`,
].join(' ');

const result = spawnSync(process.execPath, [wrapper, 'run', 'cli', 'wp', 'eval', cleanup], {
	cwd: root,
	encoding: 'utf8',
	stdio: 'pipe',
});

if (result.status !== 0) {
	process.stderr.write(result.stderr || result.stdout || 'Could not reset local RSVP test data.\n');
	process.exit(result.status ?? 1);
}
