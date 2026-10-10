import { readFile } from 'node:fs/promises';

import semanticRelease from 'semantic-release';

const configUrl = new URL('../../.releaserc.json', import.meta.url);

export async function planNextRelease({ repositoryRoot, env }) {
  const config = JSON.parse(await readFile(configUrl, 'utf8'));
  const result = await semanticRelease(
    {
      ...config,
      plugins: config.plugins.filter((plugin) => (Array.isArray(plugin) ? plugin[0] : plugin) !== '@semantic-release/github'),
      dryRun: true,
      ci: false,
    },
    { cwd: repositoryRoot, env },
  );

  if (!result) return null;

  const { version, type, gitTag: tag, notes } = result.nextRelease;
  return { version, type, tag, notes };
}
