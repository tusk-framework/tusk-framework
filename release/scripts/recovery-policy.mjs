export function planRecovery({ tag, expectedCommit, actualTagCommit, releaseExists, existingAssets, expectedAssets }) {
  if (!/^v\d+\.\d+\.\d+$/.test(tag ?? '') ||
      !/^[0-9a-f]{40,64}$/.test(expectedCommit ?? '') || actualTagCommit !== expectedCommit) {
    throw new Error('Target tag must resolve to the expected commit.');
  }
  if (typeof releaseExists !== 'boolean' || !Array.isArray(existingAssets) ||
      !Array.isArray(expectedAssets) || expectedAssets.length === 0) {
    throw new Error('Release and asset state must be available.');
  }
  for (const assets of [existingAssets, expectedAssets]) {
    if (new Set(assets.map((asset) => asset.name)).size !== assets.length) {
      throw new Error('Duplicate asset names are unsafe.');
    }
  }
  const missingAssets = [];
  for (const expected of expectedAssets) {
    if (!expected.name || !/^sha256:[0-9a-f]{64}$/.test(expected.digest ?? '')) {
      throw new Error('Expected asset requires a SHA-256 digest.');
    }
    const existing = existingAssets.find((asset) => asset.name === expected.name);
    if (existing && existing.digest !== expected.digest) {
      throw new Error(`Conflicting or unavailable digest for ${expected.name}.`);
    }
    if (!existing) missingAssets.push(expected);
  }
  if (!releaseExists && existingAssets.length) throw new Error('Assets without a release are unsafe.');
  return { action: !releaseExists ? 'create-release' : missingAssets.length ? 'upload-missing' : 'complete', missingAssets };
}
