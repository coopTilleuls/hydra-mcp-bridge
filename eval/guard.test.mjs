import assert from 'node:assert/strict';
import { test } from 'node:test';

import { Guard } from './guard.mjs';

const ok = { response: 'done', exitCode: 0, timedOut: false, clientToolCalls: [{ tool: 'read_api_resource', status: 'completed' }] };

test('a clean run passes', () => {
  assert.deepEqual(new Guard().check(ok), []);
});

test('a built-in tool aborts', () => {
  const reasons = new Guard().check({ ...ok, clientToolCalls: [{ tool: 'bash', status: 'completed' }] });
  assert.match(reasons[0], /built-in tool bash/);
});

test('an infrastructure error aborts', () => {
  const reasons = new Guard().check({ ...ok, clientToolCalls: [{ tool: 'post_book', status: 'error', error: 'Failed to connect to openlibrary.org port 443' }] });
  assert.match(reasons[0], /infrastructure/);
});

test('an expected API error does not abort', () => {
  assert.deepEqual(new Guard().check({ ...ok, clientToolCalls: [{ tool: 'post_bookmark', status: 'error', error: 'An exception occurred while executing a query: SQLSTATE[23502]' }] }), []);
});

test('three timeouts in a row abort', () => {
  const guard = new Guard();
  assert.deepEqual(guard.check({ ...ok, timedOut: true }), []);
  assert.deepEqual(guard.check({ ...ok, timedOut: true }), []);
  assert.match(guard.check({ ...ok, timedOut: true })[0], /3 timeouts in a row/);
});

test('a crash without response aborts', () => {
  assert.match(new Guard().check({ ...ok, response: '', exitCode: 1 })[0], /no response/);
});

test('the same-origin guard refusing a foreign URL does not abort', () => {
  assert.deepEqual(new Guard().check({ ...ok, clientToolCalls: [{ tool: 'read_api_resource', status: 'error', error: 'Error fetching https://openlibrary.org/books/OL2055137M.json: Refusing to request "https://openlibrary.org/books/OL2055137M.json": not on the upstream origin' }] }), []);
});
