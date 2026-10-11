import assert from 'node:assert/strict';
import test from 'node:test';
import { execFileSync } from 'node:child_process';
import { mkdtempSync, rmSync } from 'node:fs';
import { readFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';

const sha = 'a'.repeat(40);
const env = { GITHUB_ACTIONS: 'true', GITHUB_EVENT_NAME: 'push', GITHUB_REF: 'refs/heads/main',
  GITHUB_SHA: sha, GITHUB_REPOSITORY: 'tusk-framework/tusk-framework', RELEASE_VERSION: '', GH_TOKEN: 'test-token' };
const cli = () => import('../scripts/release-cli.mjs');

test('remote tag parsing dereferences annotated stable tags and ignores prereleases', async () => {
  const { parseRemoteTags } = await cli();
  assert.deepEqual(parseRemoteTags(`${'b'.repeat(40)}\trefs/tags/v0.3.2\n${sha}\trefs/tags/v0.3.2^{}\n${sha}\trefs/tags/v0.4.0-beta.1\n`),
    [{ tag: 'v0.3.2', commit: sha }]);
});
test('CLI rejects unauthorized environments before executing commands', async () => {
  const { createGithubIO } = await cli();
  for (const change of [{ GITHUB_ACTIONS: 'false' }, { GITHUB_EVENT_NAME: 'pull_request' },
    { GITHUB_REF: 'refs/heads/develop' }, { GH_TOKEN: '' }, { GITHUB_REPOSITORY: '../evil' }]) {
    assert.throws(() => createGithubIO({ env: { ...env, ...change }, run() { throw new Error('Executed command'); } }),
      /environment/i);
  }
});
test('provenance verification binds both files to repository, workflow, main and exact source SHA', async () => {
  const { createGithubIO } = await cli();
  const calls = [];
  const io = createGithubIO({ env, run(command, args) { calls.push([command, args]); return ''; } });
  await io.verifyProvenance({ commit: sha, assets: [{ path: 'archive.tar.gz' }, { path: 'archive.tar.gz.sha256' }] });
  assert.equal(calls.length, 2);
  for (const [command, args] of calls) {
    assert.equal(command, 'gh');
    assert.deepEqual(args.slice(0, 2), ['attestation', 'verify']);
    assert.equal(args[args.indexOf('--source-digest') + 1], sha);
    assert.equal(args[args.indexOf('--source-ref') + 1], 'refs/heads/main');
    assert.equal(args[args.indexOf('--signer-workflow') + 1], 'tusk-framework/tusk-framework/.github/workflows/ci.yml');
    assert.equal(args[args.indexOf('--repo') + 1], 'tusk-framework/tusk-framework');
    assert.ok(args.includes('--deny-self-hosted-runners'));
  }
});
test('release lookup includes drafts and all pages; duplicate releases fail closed', async () => {
  const { createGithubIO } = await cli();
  const calls = [];
  let duplicate = false;
  const release = { id: 42, tag_name: 'v0.3.2', draft: true, prerelease: false };
  const io = createGithubIO({ env, run(command, args) {
    calls.push([command, args]);
    if (command === 'git') return `${sha}\trefs/tags/v0.3.2`;
    if (args[1].includes('/42/assets')) return JSON.stringify([[{ name: 'archive', digest: `sha256:${'1'.repeat(64)}`, state: 'uploaded' }]]);
    return JSON.stringify(duplicate ? [[release], [release]] : [[], [release]]);
  } });
  assert.equal((await io.state({ tag: 'v0.3.2' })).release.assets[0].name, 'archive');
  assert.ok(calls.filter(([cmd]) => cmd === 'gh').every(([, args]) => args.includes('--paginate') && args.includes('--slurp')));
  duplicate = true;
  await assert.rejects(() => io.state({ tag: 'v0.3.2' }), /duplicate/i);
});
test('release creation returns the GitHub draft resource for read-after-write recovery', async () => {
  const { createGithubIO } = await cli();
  const created = { id: 99, tag_name: 'v1.0.0', target_commitish: sha, draft: true, prerelease: false };
  let requestBody;
  const io = createGithubIO({ env, run(command, args, options = {}) {
    if (command === 'gh' && args[0] === 'api') {
      requestBody = JSON.parse(options.input);
      return JSON.stringify(created);
    }
    return '';
  } });
  const result = await io.createRelease({ tag: 'v1.0.0', version: '1.0.0', commit: sha, notes: 'release notes' });
  assert.deepEqual(result, created);
  assert.deepEqual(requestBody, { tag_name: 'v1.0.0', target_commitish: sha, name: 'v1.0.0',
    body: 'release notes', draft: true, prerelease: false });
});
test('tag writing pushes only the exact tag, without force or main updates', async () => {
  const { createGithubIO } = await cli();
  const calls = [];
  const io = createGithubIO({ env, run(command, args) {
    calls.push([command, args]);
    if (args[0] === 'show-ref') return '';
    return '';
  } });
  await io.createTag({ tag: 'v0.3.2', commit: sha });
  assert.deepEqual(calls.filter(([, args]) => args[0] === 'push'),
    [['git', ['push', 'origin', 'refs/tags/v0.3.2:refs/tags/v0.3.2']]]);
});
test('main guard rejects stale main and an unrelated recovery commit', async () => {
  const { createGithubIO } = await cli();
  const io = createGithubIO({ env, run(command, args) {
    if (args[0] === 'ls-remote' && args.includes('refs/heads/main')) return `${'b'.repeat(40)}\trefs/heads/main`;
    return sha;
  } });
  await assert.rejects(() => io.guard({ tag: 'v0.3.2', mode: 'recovery', commit: sha }), /main/i);
});

test('asset upload uses gh release upload without the destructive clobber flag', async () => {
  const { createGithubIO } = await cli();
  const calls = [];
  const io = createGithubIO({ env, run(command, args) { calls.push([command, args]); return ''; } });
  await io.uploadAsset({ tag: 'v0.3.2' }, { name: 'archive.tar.gz', path: 'release/dist/archive.tar.gz' }, { id: 42 });
  assert.deepEqual(calls, [['gh', ['release', 'upload', 'v0.3.2', 'release/dist/archive.tar.gz', '--repo', 'tusk-framework/tusk-framework']]]);
  assert.ok(!calls[0][1].some((arg) => /clobber|DELETE/i.test(arg)));
});

test('release API and gh commands target the framework GitHub repository slug', async () => {
  const { createGithubIO } = await cli();
  const calls = [];
  const io = createGithubIO({ env, run(command, args) { calls.push([command, args]);
    if (args[0] === 'api') return '[]';
    return '';
  } });
  await io.state({ tag: 'v0.3.2' });
  await io.uploadAsset({ tag: 'v0.3.2' }, { name: 'archive.tar.gz', path: 'release/dist/archive.tar.gz' }, { id: 42 });
  assert.ok(calls.some(([, args]) => args[0] === 'api' && args[1].startsWith('repos/tusk-framework/tusk-framework/releases')));
  assert.ok(calls.some(([, args]) => args[0] === 'release' && args.at(-1) === 'tusk-framework/tusk-framework'));
});

test('semantic-release asset globs and version-templated names use the supported fields', async () => {
  const config = JSON.parse(readFileSync(new URL('../../.releaserc.json', import.meta.url), 'utf8'));
  const plugin = config.plugins.find((entry) => Array.isArray(entry) && entry[0] === '@semantic-release/github')[1];
  assert.deepEqual(plugin.assets, [
    { path: 'release/dist/tusk-framework-*.tar.gz', name: 'tusk-framework-${nextRelease.version}.tar.gz', label: 'Framework source archive' },
    { path: 'release/dist/tusk-framework-*.tar.gz.sha256', name: 'tusk-framework-${nextRelease.version}.tar.gz.sha256', label: 'SHA-256 checksum' },
  ]);
});

test('first bootstrap can push a tag in a real untagged local fixture', async () => {
  const { createGithubIO } = await cli();
  const directory = mkdtempSync(join(tmpdir(), 'tusk-bootstrap-push-'));
  const remote = join(directory, 'remote.git');
  const repository = join(directory, 'repo');
  const git = (...args) => execFileSync('git', args, { encoding: 'utf8', stdio: 'pipe' }).trim();
  try {
    git('init', '--bare', remote);
    git('init', '-b', 'main', repository);
    git('-C', repository, 'config', 'user.name', 'Test');
    git('-C', repository, 'config', 'user.email', 'test@example.invalid');
    git('-C', repository, 'commit', '--allow-empty', '-m', 'chore: baseline');
    git('-C', repository, 'remote', 'add', 'origin', remote);
    git('-C', repository, 'push', 'origin', 'main');
    const commit = git('-C', repository, 'rev-parse', 'HEAD');
    const io = createGithubIO({ env: { ...env, GITHUB_SHA: commit, GITHUB_EVENT_NAME: 'workflow_dispatch', RELEASE_VERSION: '0.3.2' },
      run(command, args) { assert.equal(command, 'git'); return git('-C', repository, ...args); } });
    const candidate = { mode: 'bootstrap', tag: 'v0.3.2', version: '0.3.2', commit };
    await io.guard(candidate);
    await io.createTag(candidate);
    assert.equal(git('--git-dir', remote, 'rev-parse', 'refs/tags/v0.3.2'), commit);
    assert.equal(git('--git-dir', remote, 'rev-parse', 'refs/heads/main'), commit);
    git('-C', repository, 'commit', '--allow-empty', '-m', 'fix: later main');
    const later = git('-C', repository, 'rev-parse', 'HEAD');
    git('-C', repository, 'push', 'origin', 'main');
    const recoveryIO = createGithubIO({ env: { ...env, GITHUB_SHA: later },
      run(command, args) { assert.equal(command, 'git'); return git('-C', repository, ...args); } });
    await recoveryIO.guard({ ...candidate, mode: 'recovery' });
    await assert.rejects(() => recoveryIO.guard({ ...candidate, mode: 'recovery', commit: later }), /conflicting commit/i);
  } finally { rmSync(directory, { recursive: true, force: true }); }
});
