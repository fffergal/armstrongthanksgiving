'use strict';

const assert = require('node:assert/strict');
const { execFileSync } = require('node:child_process');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const test = require('node:test');

const {
  getPushChangedPaths,
  isDocsOnlyPaths,
  isDocsOnlyPullRequestFiles,
} = require('../scripts/ci-change-scope.cjs');

test('recognizes documentation directories and Markdown files', () => {
  assert.equal(isDocsOnlyPaths([
    'docs/release-notes.txt',
    'README.md',
    'nested/CONTRIBUTING.MD',
  ]), true);
});

test('runs CI when a change set includes a non-documentation file', () => {
  assert.equal(isDocsOnlyPaths(['docs/guide.md', 'wp-content/themes/site/functions.php']), false);
});

test('runs CI when a rename moves code into a documentation path', () => {
  assert.equal(isDocsOnlyPullRequestFiles([{
    filename: 'docs/functions.php',
    previous_filename: 'wp-content/themes/site/functions.php',
  }], 1), false);
  assert.equal(isDocsOnlyPaths([
    'wp-content/themes/site/functions.php',
    'docs/functions.php',
  ]), false);
});

test('runs CI when GitHub truncates the pull request file listing', () => {
  const returnedFiles = Array.from({ length: 3000 }, (_, index) => ({
    filename: `docs/guide-${index}.md`,
  }));
  assert.equal(isDocsOnlyPullRequestFiles(returnedFiles, 3001), false);
});

test('classifies actual push diffs, including code-to-docs renames and empty diffs', (t) => {
  const repository = fs.mkdtempSync(path.join(os.tmpdir(), 'ci-change-scope-'));
  t.after(() => fs.rmSync(repository, { recursive: true, force: true }));

  const git = (...args) => execFileSync('git', args, {
    cwd: repository,
    encoding: 'utf8',
  }).trim();
  git('init', '--quiet');
  git('config', 'user.name', 'CI Scope Test');
  git('config', 'user.email', 'ci-scope-test@example.invalid');
  git('config', 'commit.gpgsign', 'false');

  fs.writeFileSync(path.join(repository, 'README.md'), 'Initial docs\n');
  fs.writeFileSync(path.join(repository, 'application.js'), 'console.log("code");\n');
  git('add', '.');
  git('commit', '--quiet', '-m', 'initial');
  const beforeRename = git('rev-parse', 'HEAD');

  fs.renameSync(path.join(repository, 'application.js'), path.join(repository, 'docs-application.md'));
  git('add', '-A');
  git('commit', '--quiet', '-m', 'rename code to docs');
  const afterRename = git('rev-parse', 'HEAD');

  const renamedPaths = getPushChangedPaths(beforeRename, afterRename, repository);
  assert.deepEqual(renamedPaths.sort(), ['application.js', 'docs-application.md']);
  assert.equal(isDocsOnlyPaths(renamedPaths), false);
  assert.equal(execFileSync(process.execPath, [
    path.resolve(__dirname, '../scripts/ci-change-scope.cjs'),
    beforeRename,
    afterRename,
  ], { cwd: repository, encoding: 'utf8' }), 'run_tests=true\n');

  fs.mkdirSync(path.join(repository, 'docs'));
  fs.writeFileSync(path.join(repository, 'docs', 'guide.txt'), 'Guide\n');
  git('add', '.');
  git('commit', '--quiet', '-m', 'add docs');
  const afterDocs = git('rev-parse', 'HEAD');
  assert.equal(isDocsOnlyPaths(getPushChangedPaths(afterRename, afterDocs, repository)), true);
  assert.equal(execFileSync(process.execPath, [
    path.resolve(__dirname, '../scripts/ci-change-scope.cjs'),
    afterRename,
    afterDocs,
  ], { cwd: repository, encoding: 'utf8' }), 'run_tests=false\n');
  assert.deepEqual(getPushChangedPaths(afterDocs, afterDocs, repository), []);
  assert.equal(isDocsOnlyPaths(getPushChangedPaths(afterDocs, afterDocs, repository)), false);
});
