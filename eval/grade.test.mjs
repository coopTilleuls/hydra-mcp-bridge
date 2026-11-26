import assert from 'node:assert/strict';
import { test } from 'node:test';

import { gradeResponse } from './grade.mjs';
import { TASKS } from './tasks.mjs';

test('limitation keywords match typographic apostrophes', () => {
  const goal = { type: 'response_indicates_limitation', keywords: ["can't change"] };

  assert.equal(gradeResponse(goal, 'so I can’t change its author through the available API.').pass, true);
});

test('contains keywords match typographic quotes', () => {
  const goal = { type: 'response_contains', values: ["Hyperion's"] };

  assert.equal(gradeResponse(goal, 'Hyperion’s rating is 2').pass, true);
});

test('T2.6 accepts a past-tense refusal', () => {
  const { goal } = TASKS.find(t => 'T2.6' === t.id);

  assert.equal(gradeResponse(goal, 'I couldn’t change the author. The update tool only accepts `book` and `condition`.').pass, true);
});

test('T1.2 accepts the bare rating as the whole answer', () => {
  const { goal } = TASKS.find(t => 'T1.2' === t.id);

  assert.equal(gradeResponse(goal, '2').pass, true);
  assert.equal(gradeResponse(goal, 'Hyperion has 2 reviews').pass, false);
});
