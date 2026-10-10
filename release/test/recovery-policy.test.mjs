import assert from 'node:assert/strict';
import test from 'node:test';

// Losing the tag guard or overwriting an existing digest must fail these tests.
const commit = 'a'.repeat(40);
const archive = { name: 'tusk-framework-0.3.2.tar.gz', digest: `sha256:${'1'.repeat(64)}` };
const checksum = { name: `${archive.name}.sha256`, digest: `sha256:${'2'.repeat(64)}` };
const state = {
  tag: 'v0.3.2', expectedCommit: commit, actualTagCommit: commit,
  releaseExists: false, existingAssets: [], expectedAssets: [archive, checksum],
};

// Dynamic import allows RED to identify the missing production policy explicitly.
async function policy(input) {
  const { planRecovery } = await import('../scripts/recovery-policy.mjs');
  return planRecovery(input);
}

test('missing release on a trusted existing tag can be created', async () => {
  assert.deepEqual(await policy(state), { action: 'create-release', missingAssets: [archive, checksum] });
});
test('complete same-SHA retry is a no-op', async () => {
  assert.deepEqual(await policy({ ...state, releaseExists: true, existingAssets: [archive, checksum] }),
    { action: 'complete', missingAssets: [] });
});
test('interrupted upload includes only absent assets', async () => {
  assert.deepEqual(await policy({ ...state, releaseExists: true, existingAssets: [archive] }),
    { action: 'upload-missing', missingAssets: [checksum] });
});
test('an empty existing release needs both immutable assets', async () => {
  assert.deepEqual(await policy({ ...state, releaseExists: true }),
    { action: 'upload-missing', missingAssets: [archive, checksum] });
});
test('no existing tag is not recovery or permission to create one', async () => {
  await assert.rejects(() => policy({ ...state, actualTagCommit: null }), /tag.*commit/i);
});
test('conflicting tag SHA fails closed', async () => {
  await assert.rejects(() => policy({ ...state, actualTagCommit: 'b'.repeat(40) }), /tag.*commit/i);
});
test('same-name conflicting digest fails closed', async () => {
  await assert.rejects(() => policy({ ...state, releaseExists: true,
    existingAssets: [{ ...archive, digest: checksum.digest }] }), /digest/i);
});
test('an unverifiable existing asset fails closed', async () => {
  await assert.rejects(() => policy({ ...state, releaseExists: true,
    existingAssets: [{ name: archive.name }] }), /digest/i);
});
test('duplicate expected or existing names fail closed', async () => {
  await assert.rejects(() => policy({ ...state, expectedAssets: [archive, archive] }), /duplicate/i);
  await assert.rejects(() => policy({ ...state, releaseExists: true, existingAssets: [archive, archive] }), /duplicate/i);
});
test('unrelated assets are retained without uploading or replacing them', async () => {
  assert.deepEqual(await policy({ ...state, releaseExists: true,
    existingAssets: [archive, checksum, { name: 'notes.txt' }] }),
    { action: 'complete', missingAssets: [] });
});

async function orchestration() { return import('../scripts/publish-release.mjs'); }

// API writes and provenance lookup are external boundaries; the state machine is real.
function api({ release = null, tagCommit = commit, provenance = true } = {}) {
  const writes = [];
  let checks = 0;
  return {
    writes,
    async guard() { checks++; },
    async state() { return { actualTagCommit: tagCommit, release }; },
    async verifyProvenance() { if (!provenance) throw new Error('Missing provenance'); },
    async createRelease() { writes.push('create'); release = { id: 1, draft: true, assets: [] }; },
    async uploadAsset(candidate, asset) { writes.push(`upload:${asset.name}`); release.assets.push(asset); },
    async publishDraft() { writes.push('publish'); release.draft = false; },
    get checks() { return checks; },
  };
}
const candidate = { tag: 'v0.3.2', version: '0.3.2', commit, assets: [archive, checksum] };
test('publisher resumes a missing release and a completed retry makes no writes', async () => {
  const io = api();
  const { finishRelease } = await orchestration();
  await finishRelease(candidate, io);
  assert.deepEqual(io.writes, ['create', `upload:${archive.name}`, `upload:${checksum.name}`, 'publish']);
  io.writes.length = 0;
  await finishRelease(candidate, io);
  assert.deepEqual(io.writes, []);
  assert.ok(io.checks >= 6);
});
test('publisher completes an interrupted draft without duplicating its archive', async () => {
  const io = api({ release: { id: 1, draft: true, assets: [archive] } });
  await (await orchestration()).finishRelease(candidate, io);
  assert.deepEqual(io.writes, [`upload:${checksum.name}`, 'publish']);
});
test('no release or upload writes are allowed without verifiable provenance', async () => {
  const io = api({ provenance: false });
  await assert.rejects(() => orchestration().then(({ finishRelease }) => finishRelease(candidate, io)), /provenance/i);
  assert.deepEqual(io.writes, []);
});
test('completed assets still require provenance before claiming completion', async () => {
  const io = api({ release: { id: 1, draft: false, assets: [archive, checksum] }, provenance: false });
  await assert.rejects(() => orchestration().then(({ finishRelease }) => finishRelease(candidate, io)), /provenance/i);
  assert.deepEqual(io.writes, []);
});
test('conflicting tag or asset never reaches a release write', async () => {
  for (const io of [api({ tagCommit: 'b'.repeat(40) }),
    api({ release: { id: 1, assets: [{ ...archive, digest: checksum.digest }] } })]) {
    await assert.rejects(() => orchestration().then(({ finishRelease }) => finishRelease(candidate, io)), /commit|digest/i);
    assert.deepEqual(io.writes, []);
  }
});
test('state is rechecked between writes so a concurrent conflict stops upload', async () => {
  const io = api();
  const getState = io.state;
  let reads = 0;
  io.state = async () => ++reads > 1 ? { actualTagCommit: 'b'.repeat(40), release: null } : getState();
  await assert.rejects(() => orchestration().then(({ finishRelease }) => finishRelease(candidate, io)), /commit/i);
  assert.deepEqual(io.writes, ['create']);
});

const initial = { event: 'workflow_dispatch', ref: 'refs/heads/main', version: '0.3.2',
  commit, mainCommit: commit, tags: [] };
test('bootstrap is fixed and never calls the automatic version planner', async () => {
  const { selectRelease } = await orchestration();
  const result = await selectRelease(initial, { plan: () => { throw new Error('Untagged analysis'); } });
  assert.deepEqual(result, { mode: 'bootstrap', tag: 'v0.3.2', version: '0.3.2', commit });
});
test('push with untagged history skips without calling semantic-release', async () => {
  assert.equal(await (await orchestration()).selectRelease({ ...initial, event: 'push' },
    { plan: () => { throw new Error('Untagged analysis'); } }), null);
});
test('PR, wrong ref, stale SHA and wrong dispatch version fail before planning', async () => {
  for (const change of [{ event: 'pull_request' }, { ref: 'refs/heads/develop' },
    { mainCommit: 'b'.repeat(40) }, { version: '1.0.0' }]) {
    await assert.rejects(() => orchestration().then(({ selectRelease }) => selectRelease({ ...initial, ...change }, {})));
  }
});
test('same-SHA bootstrap retry selects recovery without weakening new-tag bootstrap', async () => {
  const { selectRelease } = await orchestration();
  assert.deepEqual(await selectRelease({ ...initial, tags: [{ tag: 'v0.3.2', commit }] }, {}),
    { mode: 'recovery', tag: 'v0.3.2', version: '0.3.2', commit });
  await assert.rejects(() => selectRelease({ ...initial, tags: [{ tag: 'v0.3.2', commit: 'b'.repeat(40) }] }, {}), /commit/i);
});
test('automatic calculation requires bootstrap, and null plans do not publish', async () => {
  const { selectRelease } = await orchestration();
  const input = { ...initial, event: 'push', tags: [{ tag: 'v0.3.2', commit }] };
  assert.equal(await selectRelease(input, { plan: async () => null }), null);
  assert.deepEqual(await selectRelease(input, { plan: async () => ({ version: '0.3.3', type: 'patch', tag: 'v0.3.3' }) }),
    { mode: 'automatic', version: '0.3.3', type: 'patch', tag: 'v0.3.3', commit });
});

test('latest stable recovery uses numeric version order and the tagged SHA', async () => {
  const { selectRecovery } = await orchestration();
  const newer = 'b'.repeat(40);
  assert.deepEqual(selectRecovery({ ...initial, event: 'push', tags: [
    { tag: 'v0.3.2', commit }, { tag: 'v0.9.0', commit }, { tag: 'v0.10.0', commit: newer },
  ] }), { mode: 'recovery', tag: 'v0.10.0', version: '0.10.0', commit: newer });
  assert.equal(selectRecovery({ ...initial, event: 'push' }), null);
});
test('malformed plans and colliding target tags fail before publication', async () => {
  const { selectRelease } = await orchestration();
  const input = { ...initial, event: 'push', tags: [{ tag: 'v0.3.2', commit }] };
  for (const plan of [{ version: '0.3.3', type: 'patch', tag: 'v9.9.9' },
    { version: '0.3.2', type: 'patch', tag: 'v0.3.2' },
    { version: '0.3.3', type: 'bogus', tag: 'v0.3.3' }]) {
    await assert.rejects(() => selectRelease(input, { plan: async () => plan }), /candidate|tag/i);
  }
});
test('a complete release does not write when main has become stale', async () => {
  const io = api({ release: { id: 1, draft: false, assets: [archive, checksum] } });
  io.guard = async () => { throw new Error('Stale main'); };
  await assert.rejects(() => orchestration().then(({ finishRelease }) => finishRelease(candidate, io)), /main/i);
  assert.deepEqual(io.writes, []);
});
test('tag creation rechecks state and provenance before the one immutable push', async () => {
  const { publishCandidate } = await orchestration();
  const io = api({ tagCommit: null });
  const getState = io.state;
  let tagged = false;
  io.createTag = async () => { io.writes.push('tag'); tagged = true; };
  io.state = async () => ({ ...(await getState()), actualTagCommit: tagged ? commit : null });
  await publishCandidate({ ...candidate, mode: 'bootstrap' }, io);
  assert.deepEqual(io.writes, ['tag', 'create', `upload:${archive.name}`, `upload:${checksum.name}`, 'publish']);
});
test('provenance failure prevents tag creation as well as release writes', async () => {
  const io = api({ tagCommit: null, provenance: false });
  io.createTag = async () => { io.writes.push('tag'); };
  await assert.rejects(() => orchestration().then(({ publishCandidate }) => publishCandidate({ ...candidate, mode: 'bootstrap' }, io)), /provenance/i);
  assert.deepEqual(io.writes, []);
});
