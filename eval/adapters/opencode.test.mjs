import assert from 'node:assert/strict';
import { test } from 'node:test';

import { extractClientToolCalls, parseEvents } from './opencode.mjs';

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
