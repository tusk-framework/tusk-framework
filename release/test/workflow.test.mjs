import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import yaml from 'js-yaml';

test('workflow preserves required checks and confines release authority to successful main events', () => {
  const workflow = yaml.load(readFileSync(new URL('../../.github/workflows/ci.yml', import.meta.url), 'utf8'));
  assert.equal(workflow.name, 'CI');
  assert.deepEqual(workflow.on.push.branches, ['main', 'develop']);
  assert.deepEqual(workflow.on.pull_request.branches, ['main', 'develop']);
  assert.deepEqual(workflow.on.workflow_dispatch.inputs.version.options, ['0.3.2']);
  const { validate, test: php, release } = workflow.jobs;
  assert.ok(validate && php);
  assert.equal(validate.name, undefined);
  assert.equal(php.name, undefined);
  assert.deepEqual(php.strategy.matrix['php-version'], ['8.2', '8.3', '8.4']);
  assert.deepEqual(workflow.permissions, { contents: 'read' });
  assert.deepEqual(release.needs, ['validate', 'test']);
  assert.match(release.if, /success\(\)/);
  assert.match(release.if, /github.ref == 'refs\/heads\/main'/);
  assert.match(release.if, /github.event_name == 'push'/);
  assert.match(release.if, /github.event_name == 'workflow_dispatch'/);
  assert.deepEqual(release.permissions, { contents: 'write', 'id-token': 'write', attestations: 'write', 'artifact-metadata': 'write' });
  assert.equal(release.concurrency['cancel-in-progress'], false);
  for (const job of [validate, php]) {
    assert.equal(job.permissions, undefined);
    assert.equal(job.env?.GH_TOKEN, undefined);
    assert.equal(job.steps.find(({ uses }) => uses?.startsWith('actions/checkout@')).with['persist-credentials'], false);
  }
  assert.equal(release.steps.find(({ uses }) => uses?.startsWith('actions/checkout@')).with['persist-credentials'], true);
  assert.ok(validate.steps.some(({ run }) => run?.includes('npm test')));
  for (const job of Object.values(workflow.jobs)) for (const step of job.steps) {
    if (step.uses) assert.match(step.uses, /@[0-9a-f]{40}$/);
    if (step.uses?.startsWith('actions/setup-node@')) assert.equal(step.uses, 'actions/setup-node@949feb2413d6458794dcd2491c4babbbce0c15c1');
  }
  const steps = release.steps;
  const recovery = steps.findIndex(({ run }) => run?.endsWith(' publish-recovery'));
  const prepare = steps.findIndex(({ run }) => run?.endsWith(' prepare'));
  const attest = steps.findIndex(({ uses }) => uses?.startsWith('actions/attest@'));
  const publish = steps.findIndex(({ run }) => run?.endsWith(' publish'));
  assert.ok(recovery >= 0 && recovery < prepare && prepare < attest && attest < publish);
  assert.equal(steps[attest].uses, 'actions/attest@1e69f48acb82d1966a394da916b4c1698aa569d6');
  assert.match(steps[attest].with['subject-path'], /outputs.archive/);
  assert.match(steps[attest].with['subject-path'], /outputs.checksum/);
  assert.match(steps[attest].if, /mode != 'recovery'/);
  assert.match(steps[publish].if, /ready == 'true'/);
});
