import { execFileSync } from 'node:child_process';
import { createHash } from 'node:crypto';
import { createReadStream, createWriteStream, mkdirSync, mkdtempSync, renameSync, rmSync, writeFileSync } from 'node:fs';
import { basename, join, resolve } from 'node:path';
import { pipeline } from 'node:stream/promises';
import { createGzip } from 'node:zlib';

export async function createSourceArchive({ version, commit, outputDir = 'release/dist' }) {
  if (!/^\d+\.\d+\.\d+$/.test(version ?? '')) {
    throw new Error('Release version must be a stable semantic version.');
  }
  if (!/^[0-9a-f]{40,64}$/i.test(commit ?? '')) {
    throw new Error('Source commit must be a full Git SHA.');
  }
  const type = execFileSync('git', ['cat-file', '-t', commit], { encoding: 'utf8' }).trim();
  if (type !== 'commit') {
    throw new Error('Source SHA must identify a Git commit.');
  }

  const directory = resolve(outputDir);
  mkdirSync(directory, { recursive: true });
  const name = `tusk-framework-${version}.tar.gz`;
  const archivePath = join(directory, name);
  const checksumPath = `${archivePath}.sha256`;
  const temporaryDirectory = mkdtempSync(join(directory, '.archive-'));

  try {
    const tarPath = join(temporaryDirectory, 'source.tar');
    const temporaryArchive = join(temporaryDirectory, name);
    const temporaryChecksum = `${temporaryArchive}.sha256`;
    execFileSync('git', ['archive', '--format=tar', `--output=${tarPath}`, commit]);
    await pipeline(createReadStream(tarPath), createGzip({ mtime: 0 }), createWriteStream(temporaryArchive));

    const digest = createHash('sha256');
    for await (const chunk of createReadStream(temporaryArchive)) {
      digest.update(chunk);
    }
    writeFileSync(temporaryChecksum, `${digest.digest('hex')}  ${basename(archivePath)}\n`);
    renameSync(temporaryArchive, archivePath);
    renameSync(temporaryChecksum, checksumPath);
    return { archivePath, checksumPath };
  } finally {
    rmSync(temporaryDirectory, { recursive: true, force: true });
  }
}
