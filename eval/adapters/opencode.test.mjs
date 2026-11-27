import assert from 'node:assert/strict';
import { test } from 'node:test';

import { extractClientToolCalls, parseEvents, sumUsage } from './opencode.mjs';

const toolUse = (tool, state) => ({ type: 'tool_use', sessionID: 'ses_1', part: { type: 'tool', tool, callID: 'c', state } });

test('strips the MCP server prefix and keeps input and status', () => {
  const calls = extractClientToolCalls([
    toolUse('book-api_read_api_resource', { status: 'completed', input: { uri: '/books' }, output: 'big' }),
    toolUse('bash', { status: 'completed', input: { command: 'ls' } }),
  ]);

  assert.deepEqual(calls, [
    { tool: 'read_api_resource', input: { uri: '/books' }, status: 'completed' },
    { tool: 'bash', input: { command: 'ls' }, status: 'completed' },
  ]);
});

test('records the error text when a call fails', () => {
  const [call] = extractClientToolCalls([
    toolUse('book-api_delete_book', { status: 'error', input: { id: 1 }, error: 'HTTP 404' }),
  ]);

  assert.deepEqual(call, { tool: 'delete_book', input: { id: 1 }, status: 'error', error: 'HTTP 404' });
});

test('ignores non-tool events and non-JSON lines', () => {
  const events = parseEvents([
    '{"type":"step_start","sessionID":"ses_1"}',
    'not json',
    JSON.stringify(toolUse('book-api_get_book', { status: 'completed', input: {} })),
    '{"type":"text","part":{"text":"hi"}}',
    '{"type":"step_finish","part":{"tokens":{}}}',
  ].join('\n'));

  assert.deepEqual(extractClientToolCalls(events).map(c => c.tool), ['get_book']);
});

test('counts cache writes in the prompt tokens of each step', () => {
  const step = (input, output, reasoning, write, read) => ({ type: 'step_finish', part: { tokens: { input, output, reasoning, cache: { write, read } } } });

  assert.deepEqual(sumUsage([step(3, 21, 0, 1462, 0), step(3, 22, 0, 83, 1462), { type: 'text' }]), {
    inputTokens: 6,
    outputTokens: 43,
    reasoningTokens: 0,
    totalTokens: 49,
    cacheReadTokens: 1462,
    cacheCreationTokens: 1545,
    promptTokens: 3013,
    stepPromptTokens: [1465, 1548],
  });
});
