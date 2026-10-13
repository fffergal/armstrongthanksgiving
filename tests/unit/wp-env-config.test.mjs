import assert from 'node:assert/strict';
import path from 'node:path';
import test from 'node:test';
import { normalizeDockerMountSource, withWorktreeMappings } from '../../scripts/wp-env-config.mjs';

test('mounts the worktree theme, plugin, and mu-plugins while preserving other mappings', () => {
  const root = path.resolve('/tmp/armstrong-worktree');
  const config = {
    mappings: {
      'wp-content/uploads': './uploads',
      'wp-content/themes/armstrong-thanksgiving': './old-theme-copy',
    },
  };

  const mapped = withWorktreeMappings(config, root);

  assert.deepEqual(mapped.mappings, {
    'wp-content/uploads': './uploads',
    'wp-content/themes/armstrong-thanksgiving': path.join(root, 'wp-content/themes/armstrong-thanksgiving'),
    'wp-content/plugins/armstrong-gathering': path.join(root, 'wp-content/plugins/armstrong-gathering'),
    'wp-content/mu-plugins': path.join(root, 'wp-content/mu-plugins'),
  });
  assert.deepEqual(config.mappings, {
    'wp-content/uploads': './uploads',
    'wp-content/themes/armstrong-thanksgiving': './old-theme-copy',
  });
});

test('normalizes Docker Desktop host mount paths before comparing sources', () => {
  assert.equal(
    normalizeDockerMountSource('/host_mnt/private/tmp/armstrong-worktree/theme'),
    path.resolve('/private/tmp/armstrong-worktree/theme'),
  );
});
