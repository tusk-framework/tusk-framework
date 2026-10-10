import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { createHash } from 'node:crypto';
import { mkdtempSync, mkdirSync, readFileSync, rmSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import test from 'node:test';

import { createSourceArchive } from '../scripts/create-source-archive.mjs';

function git(repository, ...args) {
  return execFileSync('git', ['-C', repository, ...args], { encoding: 'utf8' }).trim();
}

function fixture() {
  const repository = mkdtempSync(join(tmpdir(), 'tusk-release-archive-'));
  git(repository, 'init', '-q');
  git(repository, 'config', 'core.autocrlf', 'false');
  writeFileSync(join(repository, 'marker.txt'), 'requested commit\n');
  writeFileSync(join(repository, 'composer.json'), '{"name":"tusk-framework/framework"}\n');
  git(repository, 'add', 'marker.txt', 'composer.json');
  git(repository, '-c', 'user.name=Test', '-c', 'user.email=test@example.invalid', 'commit', '-qm', 'first');
  const requestedCommit = git(repository, 'rev-parse', 'HEAD');
  writeFileSync(join(repository, 'marker.txt'), 'newer commit\n');
  git(repository, 'add', 'marker.txt');
  git(repository, '-c', 'user.name=Test', '-c', 'user.email=test@example.invalid', 'commit', '-qm', 'second');
  const outputDir = join(repository, 'release', 'dist');
  mkdirSync(outputDir, { recursive: true });
  return { repository, requestedCommit, outputDir };
}

async function withFixture(run) {
  const originalDirectory = process.cwd();
  const context = fixture();
  try {
    process.chdir(context.repository);
    await run(context);
  } finally {
    process.chdir(originalDirectory);
    rmSync(context.repository, { recursive: true, force: true });
  }
}

test('archives tracked files from the requested commit, even after a newer commit', async () => {
  await withFixture(async ({ requestedCommit, outputDir }) => {
    const { archivePath } = await createSourceArchive({ version: '0.3.2', commit: requestedCommit, outputDir });
    const marker = execFileSync('tar', ['-xOzf', archivePath, 'marker.txt'], { encoding: 'utf8' });
    const manifest = execFileSync('tar', ['-xOzf', archivePath, 'composer.json'], { encoding: 'utf8' });
    assert.equal(marker, 'requested commit\n');
    assert.equal(manifest, '{"name":"tusk-framework/framework"}\n');
  });
});

test('writes a SHA-256 checksum of the archive bytes', async () => {
  await withFixture(async ({ requestedCommit, outputDir }) => {
    const { archivePath, checksumPath } = await createSourceArchive({ version: '0.3.2', commit: requestedCommit, outputDir });
    const digest = createHash('sha256').update(readFileSync(archivePath)).digest('hex');
    assert.equal(readFileSync(checksumPath, 'utf8'), `${digest}  tusk-framework-0.3.2.tar.gz\n`);
  });
});

test('generates identical archive and checksum bytes on retry', async () => {
  await withFixture(async ({ requestedCommit, outputDir }) => {
    const first = await createSourceArchive({ version: '0.3.2', commit: requestedCommit, outputDir });
    const firstArchive = readFileSync(first.archivePath);
    const firstChecksum = readFileSync(first.checksumPath);
    const second = await createSourceArchive({ version: '0.3.2', commit: requestedCommit, outputDir });
    assert.deepEqual(readFileSync(second.archivePath), firstArchive);
    assert.deepEqual(readFileSync(second.checksumPath), firstChecksum);
  });
});
