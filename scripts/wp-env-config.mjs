import path from 'node:path';

export function withWorktreeMappings(config, root) {
  return {
    ...config,
    mappings: {
      ...config.mappings,
      'wp-content/themes/armstrong-thanksgiving': path.join(root, 'wp-content/themes/armstrong-thanksgiving'),
      'wp-content/plugins/armstrong-gathering': path.join(root, 'wp-content/plugins/armstrong-gathering'),
      'wp-content/mu-plugins': path.join(root, 'wp-content/mu-plugins'),
    },
  };
}

/** Normalize the host path reported by Docker Desktop before comparing bind mounts. */
export function normalizeDockerMountSource(source) {
	return path.resolve(String(source).replace(/^\/host_mnt(?=\/)/, ''));
}
