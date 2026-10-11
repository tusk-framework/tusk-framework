import { execFileSync } from 'node:child_process';
import { createHash } from 'node:crypto';
import { appendFileSync, readFileSync, writeFileSync } from 'node:fs';
import { basename, join, resolve } from 'node:path';
import { pathToFileURL } from 'node:url';
import { createSourceArchive } from './create-source-archive.mjs';
import { latestStable, selectRecovery, selectRelease, publishCandidate } from './publish-release.mjs';

const stable = /^v(?:0|[1-9]\d*)\.(?:0|[1-9]\d*)\.(?:0|[1-9]\d*)$/;
const runCommand = (command, args, options = {}) => execFileSync(command, args,
  { encoding: 'utf8', stdio: ['pipe', 'pipe', 'pipe'], ...options }).trim();

export function parseRemoteTags(text) {
  const refs = new Map(text.trim().split('\n').filter(Boolean).map((line) => line.trim().split(/\s+/).reverse()));
  return [...refs].filter(([ref]) => stable.test(ref.replace('refs/tags/', '')))
    .map(([ref, sha]) => ({ tag: ref.replace('refs/tags/', ''), commit: refs.get(`${ref}^{}`) ?? sha }));
}

export function createGithubIO({ env = process.env, run = runCommand } = {}) {
  if (env.GITHUB_ACTIONS !== 'true' || !['push', 'workflow_dispatch'].includes(env.GITHUB_EVENT_NAME) ||
      env.GITHUB_REF !== 'refs/heads/main' || !/^[0-9a-f]{40,64}$/.test(env.GITHUB_SHA ?? '') ||
      !/^[\w-]+\/[\w-][\w.-]*$/.test(env.GITHUB_REPOSITORY ?? '') || !env.GH_TOKEN) {
    throw new Error('Invalid release environment; only an authenticated main push or dispatch is supported.');
  }
  const repo = env.GITHUB_REPOSITORY;
  const endpoint = `repos/${repo}/releases`;
  const git = (...args) => run('git', args);
  const gh = (...args) => run('gh', args);
  const pages = (path) => JSON.parse(gh('api', path, '--method', 'GET', '--paginate', '--slurp')).flat();
  const request = (path, method, body) => run('gh', ['api', path, '--method', method, '--input', '-'],
    { input: JSON.stringify(body) });
  const tags = () => parseRemoteTags(git('ls-remote', '--tags', 'origin'));
  return {
    async input() {
      return { event: env.GITHUB_EVENT_NAME, ref: env.GITHUB_REF, version: env.RELEASE_VERSION,
        commit: env.GITHUB_SHA, mainCommit: git('ls-remote', 'origin', 'refs/heads/main').split(/\s+/)[0], tags: tags() };
    },
    async guard(candidate) {
      const input = await this.input();
      if (input.commit !== input.mainCommit || git('rev-parse', 'HEAD') !== input.commit) {
        throw new Error('The workflow commit is no longer the current main commit.');
      }
      if (input.event === 'workflow_dispatch' && input.version !== '0.3.2') throw new Error('Initial version must be 0.3.2.');
      if (!stable.test(candidate.tag) || candidate.tag !== `v${candidate.version}` ||
          !/^[0-9a-f]{40,64}$/.test(candidate.commit ?? '')) throw new Error('Invalid candidate tag or commit.');
      // Older recovery commits are trusted only when reachable from tested main.
      git('merge-base', '--is-ancestor', candidate.commit, input.commit);
      if (candidate.mode !== 'recovery' && candidate.commit !== input.commit) throw new Error('Candidate commit differs from tested main.');
      if (input.event === 'workflow_dispatch' && (candidate.tag !== 'v0.3.2' || candidate.commit !== input.commit)) {
        throw new Error('Bootstrap recovery requires the same trusted commit.');
      }
      const target = input.tags.find(({ tag }) => tag === candidate.tag);
      if (target && target.commit !== candidate.commit) throw new Error('Target tag points to a conflicting commit.');
      if (candidate.mode === 'recovery' && !target) throw new Error('Recovery tag commit is unavailable.');
      if (!target && candidate.mode === 'bootstrap' && input.tags.length) throw new Error('Stable tags prevent bootstrap.');
      if (!target && candidate.mode === 'automatic' && (!input.tags.some(({ tag }) => tag === 'v0.3.2') ||
          latestStable(input.tags)?.tag !== candidate.baselineTag)) throw new Error('Stable tag state changed after planning.');
    },
    async state(candidate) {
      const actualTagCommit = tags().find(({ tag }) => tag === candidate.tag)?.commit ?? null;
      const matches = pages(`${endpoint}?per_page=100`).filter((release) => release.tag_name === candidate.tag);
      if (matches.length > 1) throw new Error('Duplicate releases for the target tag are unsafe.');
      const release = matches[0] ?? null;
      if (release) {
        if (release.prerelease) throw new Error('Stable tag has a conflicting prerelease.');
        release.assets = pages(`${endpoint}/${release.id}/assets?per_page=100`);
        for (const asset of release.assets) if (asset.state !== 'uploaded') asset.digest = null;
      }
      return { actualTagCommit, release };
    },
    async verifyProvenance(candidate) {
      for (const asset of candidate.assets) {
        gh('attestation', 'verify', asset.path, '--repo', repo, '--signer-workflow', `${repo}/.github/workflows/ci.yml`,
          '--source-ref', 'refs/heads/main', '--source-digest', candidate.commit, '--deny-self-hosted-runners');
      }
    },
    async createTag(candidate) {
      const local = git('tag', '--list', candidate.tag);
      if (local) {
        if (git('rev-parse', `${candidate.tag}^{commit}`) !== candidate.commit) throw new Error('Local tag has a conflicting commit.');
      } else git('tag', candidate.tag, candidate.commit);
      git('push', 'origin', `refs/tags/${candidate.tag}:refs/tags/${candidate.tag}`);
    },
    async createRelease(candidate) {
      return JSON.parse(request(endpoint, 'POST', { tag_name: candidate.tag, target_commitish: candidate.commit,
        name: candidate.tag, body: candidate.notes ?? `Framework ${candidate.version}\n\nSource commit: ${candidate.commit}`,
        draft: true, prerelease: false }));
    },
    async uploadAsset(candidate, asset, release) {
      // gh release upload uses GitHub's dedicated uploads host and rejects an
      // existing name unless --clobber is explicitly requested (never used here).
      gh('release', 'upload', candidate.tag, asset.path, '--repo', repo);
    },
    async publishDraft(candidate, release) {
      request(`${endpoint}/${release.id}`, 'PATCH', { draft: false, make_latest: 'true' });
    },
  };
}

export function resolveReleaseAssets(config, version, repositoryRoot = process.cwd()) {
  const github = config.plugins.find((plugin) => Array.isArray(plugin) && plugin[0] === '@semantic-release/github');
  return github[1].assets.map((asset) => {
    const path = resolve(repositoryRoot, asset.path
      .replaceAll('${nextRelease.version}', version)
      .replaceAll('*', version));
    const bytes = readFileSync(path);
    const name = asset.name.replaceAll('${nextRelease.version}', version);
    return { path, name, digest: `sha256:${createHash('sha256').update(bytes).digest('hex')}` };
  });
}

function configuredAssets(version) {
  const config = JSON.parse(readFileSync(new URL('../../.releaserc.json', import.meta.url), 'utf8'));
  return resolveReleaseAssets(config, version);
}

export async function main(command, env = process.env) {
  if (!['prepare-recovery', 'publish-recovery', 'prepare', 'publish'].includes(command)) throw new Error('Unknown release command.');
  const io = createGithubIO({ env });
  const recovering = command.endsWith('recovery');
  const manifest = join(env.RUNNER_TEMP, `framework-${recovering ? 'recovery' : 'candidate'}.json`);
  if (command.startsWith('prepare')) {
    const input = await io.input();
    // Fetch without force: locally conflicting refs must fail, never move.
    runCommand('git', ['fetch', '--tags', 'origin']);
    const candidate = recovering ? selectRecovery(input) : await selectRelease(input, {
      plan: async () => {
        const { planNextRelease } = await import('./plan-release.mjs');
        return planNextRelease({ repositoryRoot: process.cwd(), env });
      },
    });
    if (!candidate) {
      appendFileSync(env.GITHUB_OUTPUT, 'ready=false\n');
      return;
    }
    candidate.baselineTag = latestStable(input.tags)?.tag ?? null;
    await io.guard(candidate);
    await createSourceArchive({ version: candidate.version, commit: candidate.commit });
    candidate.assets = configuredAssets(candidate.version);
    if (candidate.mode === 'recovery') {
      // Recovery must verify the original source-SHA attestation; a newer workflow
      // must not attest old bytes as if they came from its newer source SHA.
      await io.verifyProvenance(candidate);
    }
    writeFileSync(manifest, `${JSON.stringify(candidate, null, 2)}\n`);
    appendFileSync(env.GITHUB_OUTPUT, `ready=true\nmode=${candidate.mode}\narchive=${candidate.assets[0].path}\nchecksum=${candidate.assets[1].path}\n`);
    return;
  }
  const candidate = JSON.parse(readFileSync(manifest, 'utf8'));
  // Bind publication to the bytes built in prepare, including checksum bytes.
  const assets = configuredAssets(candidate.version);
  if (JSON.stringify(assets) !== JSON.stringify(candidate.assets)) throw new Error('Candidate asset digest changed after preparation.');
  await publishCandidate(candidate, io);
}

if (process.argv[1] && import.meta.url === pathToFileURL(resolve(process.argv[1])).href) {
  main(process.argv[2]).catch((error) => { console.error(error.message); process.exitCode = 1; });
}
