'use strict';

const { execFileSync } = require('node:child_process');

function isDocumentationPath(filename) {
  return typeof filename === 'string'
    && (filename.startsWith('docs/') || filename.toLowerCase().endsWith('.md'));
}

function isDocsOnlyPaths(paths) {
  return Array.isArray(paths) && paths.length > 0 && paths.every(isDocumentationPath);
}

function isDocsOnlyPullRequestFiles(files, expectedCount) {
  return Array.isArray(files)
    && files.length > 0
    && files.length === expectedCount
    && files.every(({ filename, previous_filename }) =>
      isDocumentationPath(filename)
      && (!previous_filename || isDocumentationPath(previous_filename))
    );
}

function getPushChangedPaths(beforeSha, afterSha, cwd = process.cwd()) {
  if (!beforeSha || !afterSha || /^0+$/.test(beforeSha)) {
    return [];
  }

  return execFileSync('git', [
    'diff',
    '--name-only',
    '--no-renames',
    '-z',
    beforeSha,
    afterSha,
  ], { cwd, encoding: 'utf8' })
    .split('\0')
    .filter(Boolean);
}

if (require.main === module) {
  const [, , beforeSha, afterSha] = process.argv;
  const changedPaths = getPushChangedPaths(beforeSha, afterSha);
  const runTests = !isDocsOnlyPaths(changedPaths);
  process.stdout.write(`run_tests=${runTests}\n`);
}

module.exports = {
  getPushChangedPaths,
  isDocumentationPath,
  isDocsOnlyPaths,
  isDocsOnlyPullRequestFiles,
};
