export function validateBootstrap({ version, ref, commit, mainCommit, existingTags }) {
  if (version !== '0.3.2') {
    return { allowed: false, reason: 'Initial release version must be 0.3.2.' };
  }
  if (ref !== 'refs/heads/main') {
    return { allowed: false, reason: 'Initial release must run on main.' };
  }
  if (!/^[0-9a-f]{40,64}$/i.test(commit ?? '') || commit !== mainCommit) {
    return { allowed: false, reason: 'Dispatch commit must equal the current main commit.' };
  }
  if (!Array.isArray(existingTags) || existingTags.length !== 0) {
    return { allowed: false, reason: 'A stable release tag already exists or tag state is unavailable.' };
  }
  return { allowed: true, reason: null };
}
