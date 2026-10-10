import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { mkdtempSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import test from 'node:test';

import { planNextRelease } from '../scripts/plan-release.mjs';

function git(cwd, ...args) {
  return execFileSync('git', ['-C', cwd, ...args], { encoding: 'utf8', stdio: 'pipe' }).trim();
}

function fixture(commitMessage, { revert = false } = {}) {
  const directory = mkdtempSync(join(tmpdir(), 'tusk-release-test-'));
  const remote = join(directory, 'remote.git');
  const repositoryRoot = join(directory, 'repo');

  git(directory, 'init', '--bare', remote);
  git(directory, 'init', '-b', 'main', repositoryRoot);
  git(repositoryRoot, 'config', 'user.name', 'Release Test');
  git(repositoryRoot, 'config', 'user.email', 'release-test@example.invalid');
  git(repositoryRoot, 'commit', '--allow-empty', '-m', 'chore: baseline');
  let revertedCommit = null;
  if (revert) {
    git(repositoryRoot, 'commit', '--allow-empty', '-m', 'feat: routing behavior');
    revertedCommit = git(repositoryRoot, 'rev-parse', 'HEAD');
  }
  git(repositoryRoot, 'tag', 'v1.2.3');
  const commitArgs = ['commit', '--allow-empty', '-m', commitMessage];
  if (revertedCommit) commitArgs.push('-m', `This reverts commit ${revertedCommit}.`);
  git(repositoryRoot, ...commitArgs);
  git(repositoryRoot, 'remote', 'add', 'origin', remote);
  git(repositoryRoot, 'push', 'origin', 'main', '--tags');

  return { directory, repositoryRoot };
}

const cases = [
  ['fix: repair parsing', { version: '1.2.4', type: 'patch', tag: 'v1.2.4' }],
  ['feat: add routing', { version: '1.3.0', type: 'minor', tag: 'v1.3.0' }],
  ['feat!: replace routing contract', { version: '2.0.0', type: 'major', tag: 'v2.0.0' }],
  ['fix: update client\n\nBREAKING CHANGE: remove old client method', { version: '2.0.0', type: 'major', tag: 'v2.0.0' }],
  ['docs: clarify installation', null],
  ['chore: update repository settings', null],
  ['perf: speed up routing', null],
  ['revert: restore old routing behavior', null, { revert: true }],
];

for (const [message, expected, options] of cases) {
  test(`plans ${JSON.stringify(message)} as ${expected?.type ?? 'no release'}`, async () => {
    const { directory, repositoryRoot } = fixture(message, options);

    try {
      // These are local main fixtures, even when validate runs on a PR. Do not
      // let the enclosing Actions event choose the fixture's release branch.
      const env = Object.fromEntries(Object.entries(process.env)
        .filter(([key]) => key !== 'CI' && !key.startsWith('GITHUB_') && key !== 'GH_TOKEN'));
      const planned = await planNextRelease({ repositoryRoot, env });
      if (expected === null) assert.equal(planned, null);
      else {
        assert.deepEqual({ version: planned.version, type: planned.type, tag: planned.tag }, expected);
        assert.equal(typeof planned.notes, 'string');
        assert.ok(planned.notes.length > 0);
      }
      assert.deepEqual(git(repositoryRoot, 'tag', '--list'), 'v1.2.3');
    } finally {
      rmSync(directory, { recursive: true, force: true });
    }
  });
}
