import { validateBootstrap } from './bootstrap-policy.mjs';
import { planRecovery } from './recovery-policy.mjs';

const stable = /^v(0|[1-9]\d*)\.(0|[1-9]\d*)\.(0|[1-9]\d*)$/;

export function latestStable(tags) {
  return tags.filter(({ tag }) => stable.test(tag)).sort((a, b) => {
    const left = a.tag.slice(1).split('.').map(BigInt);
    const right = b.tag.slice(1).split('.').map(BigInt);
    for (let i = 0; i < 3; i++) if (left[i] !== right[i]) return left[i] > right[i] ? -1 : 1;
    return 0;
  })[0];
}

function validateInput(input) {
  if (!['push', 'workflow_dispatch'].includes(input.event) || input.ref !== 'refs/heads/main' ||
      !/^[0-9a-f]{40,64}$/.test(input.commit ?? '') || input.commit !== input.mainCommit ||
      !Array.isArray(input.tags)) throw new Error('Release requires the current main commit and trusted tag state.');
  if (input.event === 'workflow_dispatch' && input.version !== '0.3.2') {
    throw new Error('Initial release accepts only 0.3.2.');
  }
}

export function selectRecovery(input) {
  validateInput(input);
  const bootstrap = input.tags.find(({ tag }) => tag === 'v0.3.2');
  if (input.event === 'workflow_dispatch' && bootstrap && bootstrap.commit !== input.commit) {
    throw new Error('Bootstrap tag points to a different commit.');
  }
  const target = input.event === 'workflow_dispatch' ? bootstrap : latestStable(input.tags);
  return target ? { mode: 'recovery', tag: target.tag, version: target.tag.slice(1), commit: target.commit } : null;
}

export async function selectRelease(input, { plan }) {
  validateInput(input);
  const bootstrap = input.tags.find(({ tag }) => tag === 'v0.3.2');
  if (input.event === 'workflow_dispatch') {
    if (bootstrap) return selectRecovery(input);
    const decision = validateBootstrap({ ...input, existingTags: input.tags.map(({ tag }) => tag) });
    if (!decision.allowed) throw new Error(decision.reason);
    return { mode: 'bootstrap', tag: 'v0.3.2', version: '0.3.2', commit: input.commit };
  }
  if (!bootstrap) return null;
  const next = await plan();
  if (!next) return null;
  if (!stable.test(next.tag) || next.tag !== `v${next.version}` ||
      !['major', 'minor', 'patch'].includes(next.type) || input.tags.some(({ tag }) => tag === next.tag) ||
      latestStable([...input.tags, { tag: next.tag }]).tag !== next.tag) {
    throw new Error('Invalid or colliding release candidate tag.');
  }
  return { mode: 'automatic', ...next, commit: input.commit };
}

function recovery(candidate, state) {
  return planRecovery({ tag: candidate.tag, expectedCommit: candidate.commit,
    actualTagCommit: state.actualTagCommit, releaseExists: Boolean(state.release),
    existingAssets: state.release?.assets ?? [], expectedAssets: candidate.assets });
}

// Each loop reads fresh state. Writes never replace an existing asset or tag.
export async function finishRelease(candidate, io) {
  await io.guard(candidate);
  await io.verifyProvenance(candidate);
  let createdRelease = null;
  for (let attempt = 0; attempt < candidate.assets.length + 3; attempt++) {
    await io.guard(candidate);
    const observedState = await io.state(candidate);
    const state = observedState.release || !createdRelease
      ? observedState
      : { ...observedState, release: createdRelease };
    const decision = recovery(candidate, state);
    if (decision.action === 'create-release') {
      const release = await io.createRelease(candidate);
      if (!Number.isInteger(release?.id) || release.tag_name !== candidate.tag || release.draft !== true) {
        throw new Error('GitHub did not return the expected draft release resource.');
      }
      createdRelease = { ...release, assets: Array.isArray(release.assets) ? release.assets : [] };
    } else if (decision.missingAssets.length) await io.uploadAsset(candidate, decision.missingAssets[0], state.release);
    else if (state.release.draft) await io.publishDraft(candidate, state.release);
    else return { action: 'complete' };
  }
  throw new Error('Release did not converge; retry after inspecting remote state.');
}

export async function publishCandidate(candidate, io) {
  await io.guard(candidate);
  await io.verifyProvenance(candidate);
  const state = await io.state(candidate);
  if (state.actualTagCommit === null) {
    if (candidate.mode === 'recovery' || state.release) throw new Error('Recovery requires an existing trusted tag commit.');
    await io.guard(candidate);
    // Recheck the target immediately before tag writing, not just at selection time.
    const latest = await io.state(candidate);
    if (latest.actualTagCommit === null && !latest.release) await io.createTag(candidate);
    else recovery(candidate, latest);
  } else recovery(candidate, state);
  return finishRelease(candidate, io);
}
