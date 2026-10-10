import assert from 'node:assert/strict';
import { createHash } from 'node:crypto';
import { mkdtempSync, mkdirSync, rmSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join, resolve } from 'node:path';
import test from 'node:test';
import { resolveReleaseAssets } from '../scripts/release-cli.mjs';

test('release asset patterns resolve files from their configured directory', (t) => {
  const root = mkdtempSync(join(tmpdir(), 'tusk-release-assets-'));
  t.after(() => rmSync(root, { recursive: true, force: true }));
  mkdirSync(join(root, 'release', 'dist'), { recursive: true });
  writeFileSync(join(root, 'release', 'dist', 'tusk-framework-0.3.2.tar.gz'), 'archive bytes');
  writeFileSync(join(root, 'release', 'dist', 'tusk-framework-0.3.2.tar.gz.sha256'), 'checksum bytes');

  const assets = resolveReleaseAssets({
    plugins: [[
      '@semantic-release/github',
      { assets: [
        { path: 'release/dist/tusk-framework-*.tar.gz', name: 'tusk-framework-${nextRelease.version}.tar.gz' },
        { path: 'release/dist/tusk-framework-*.tar.gz.sha256', name: 'tusk-framework-${nextRelease.version}.tar.gz.sha256' },
      ] },
    ]],
  }, '0.3.2', root);

  assert.deepEqual(assets, [
    {
      path: resolve(root, 'release/dist/tusk-framework-0.3.2.tar.gz'),
      name: 'tusk-framework-0.3.2.tar.gz',
      digest: `sha256:${createHash('sha256').update('archive bytes').digest('hex')}`,
    },
    {
      path: resolve(root, 'release/dist/tusk-framework-0.3.2.tar.gz.sha256'),
      name: 'tusk-framework-0.3.2.tar.gz.sha256',
      digest: `sha256:${createHash('sha256').update('checksum bytes').digest('hex')}`,
    },
  ]);
});
