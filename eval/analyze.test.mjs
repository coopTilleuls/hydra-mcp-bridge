import assert from 'node:assert/strict';
import { test } from 'node:test';

import { promptTokens, computeM2, computeM3, computeComparison, computeScaling, resultDirs } from './analyze.mjs';
import { mkdtempSync, mkdirSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';

const run = (condition, prompt, { pass = true, calls = [], extraResources = 0, taskId = 'T2.1' } = {}) => ({
  taskId,
  condition,
  modelSlug: 'openrouter/openai/gpt-6-luna',
  extraResources,
  goalResult: { pass },
  usage: { inputTokens: prompt, cacheReadTokens: 0, cacheCreationTokens: 0, outputTokens: 100, promptTokens: prompt },
  clientToolCalls: calls.map(([tool, status = 'completed']) => ({ tool, status })),
});

test('prompt tokens come from usage.promptTokens', () => {
  assert.equal(promptTokens({ inputTokens: 1, cacheReadTokens: 2, cacheCreationTokens: 3, promptTokens: 10 }), 10);
});

test('prompt tokens are rebuilt from input and cache for results written before promptTokens', () => {
  assert.equal(promptTokens({ inputTokens: 1, cacheReadTokens: 2, cacheCreationTokens: 3 }), 6);
  assert.equal(promptTokens(undefined), 0);
});

test('tool calls are counted per condition from the client side', () => {
  const rows = computeM2([
    run('A', 1, { calls: [['read_api_resource'], ['post_book']] }),
    run('B', 1, { calls: [['post_book']] }),
  ]);

  assert.deepEqual(rows.map(r => [r.condition, r.median]), [['A', 2], ['B', 1]]);
});

test('errors are counted per condition from the client side', () => {
  const rows = computeM3([
    run('A', 1, { calls: [['read_api_resource'], ['post_book', 'error']] }),
    run('B', 1, { calls: [['post_book']] }),
  ]);

  assert.deepEqual(rows.map(r => [r.condition, r.totalErrors, r.totalToolCalls]), [['A', 1, 2], ['B', 0, 1]]);
});

test('comparison lists every condition with mean prompt tokens and the ratio to B', () => {
  const rows = computeComparison([
    run('A', 200), run('A', 400, { pass: false }),
    run('B', 100), run('B', 100),
    run('GS', 600),
  ]);

  assert.deepEqual(rows, [
    { agent: 'openrouter/openai/gpt-6-luna', condition: 'A', pass: '1/2', meanPromptTokens: 300, vsB: '3.00x' },
    { agent: 'openrouter/openai/gpt-6-luna', condition: 'B', pass: '2/2', meanPromptTokens: 100, vsB: '1.00x' },
    { agent: 'openrouter/openai/gpt-6-luna', condition: 'GS', pass: '1/1', meanPromptTokens: 600, vsB: '6.00x' },
  ]);
});

test('scaling has one row per size with the mean prompt tokens of each condition', () => {
  const rows = computeScaling([
    run('A', 100), run('A', 300),
    run('G', 500),
    run('A', 400, { extraResources: 10 }),
    run('G', 900, { extraResources: 10, pass: false }),
  ]);

  assert.deepEqual(rows, [
    { agent: 'openrouter/openai/gpt-6-luna', extraResources: 0, operations: 19, A: 200, A_pass: '2/2', G: 500, G_pass: '1/1' },
    { agent: 'openrouter/openai/gpt-6-luna', extraResources: 10, operations: 69, A: 400, A_pass: '1/1', G: 900, G_pass: '0/1' },
  ]);
});

test('scaling columns follow the condition order whatever the file order', () => {
  const [row] = computeScaling([run('GS', 1), run('B', 1), run('A', 1)]);

  assert.deepEqual(Object.keys(row), ['agent', 'extraResources', 'operations', 'A', 'A_pass', 'B', 'B_pass', 'GS', 'GS_pass']);
});

test('a scaling path is a result directory or a root of result directories', () => {
  const root = mkdtempSync(join(tmpdir(), 'analyze-'));
  mkdirSync(join(root, 'grid'));
  writeFileSync(join(root, 'grid', 'T1.1_a_x_1.json'), '{}');
  mkdirSync(join(root, 'scaling', 'n0'), { recursive: true });
  mkdirSync(join(root, 'scaling', 'n10'));

  assert.deepEqual(resultDirs(`${join(root, 'grid')},${join(root, 'scaling')}`), [
    join(root, 'grid'), join(root, 'scaling', 'n0'), join(root, 'scaling', 'n10'),
  ]);
});
