import assert from 'node:assert/strict';
import test from 'node:test';

import { validateBootstrap } from '../scripts/bootstrap-policy.mjs';

const valid = {
  version: '0.3.2',
  ref: 'refs/heads/main',
  commit: 'a'.repeat(40),
  mainCommit: 'a'.repeat(40),
  existingTags: [],
};

test('allows the first 0.3.2 dispatch on current main', () => {
  assert.deepEqual(validateBootstrap(valid), { allowed: true, reason: null });
});

for (const [name, override] of [
  ['wrong version', { version: '0.3.3' }],
  ['non-main ref', { ref: 'refs/heads/feature/release' }],
  ['stale commit', { commit: 'b'.repeat(40) }],
  ['existing stable tag at another SHA', { existingTags: [{ name: 'v0.3.2', commit: 'b'.repeat(40) }] }],
  ['repeated bootstrap at the same SHA', { existingTags: [{ name: 'v0.3.2', commit: 'a'.repeat(40) }] }],
  ['any earlier stable release', { existingTags: ['v0.3.1'] }],
]) {
  test(`rejects ${name}`, () => {
    const result = validateBootstrap({ ...valid, ...override });
    assert.equal(result.allowed, false);
    assert.equal(typeof result.reason, 'string');
    assert.ok(result.reason.length > 0);
  });
}
