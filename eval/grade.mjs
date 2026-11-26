/**
 * Goal grading shared by run.mjs (live, right after a run) and analyze.mjs (offline regrade).
 * Response goals can be regraded from a stored response; API state goals only live, the DB is reset after each run.
 */

import { execFileSync } from 'node:child_process';

import { DEMO_BASE_URL } from './config.mjs';

// Start boundary only, so "no date" still matches "no dates" but "id" no longer matches "did".
function mentions(text, keyword) {
  const escaped = keyword.toLowerCase().replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
  return new RegExp(`(?<![a-z0-9])${escaped}`).test(text);
}

/**
 * @returns {{pass: boolean|null, detail: string}|null} null when the goal needs the live API state
 */
export function gradeResponse(goal, response) {
  const text = (response || '').toLowerCase();

  switch (goal.type) {
    case 'response_contains': {
      const missing = goal.values.filter(v => !text.includes(v.toLowerCase()));
      return { pass: missing.length === 0, detail: missing.length === 0 ? 'all keywords found' : `missing: ${missing.join(', ')}` };
    }

    case 'response_contains_any': {
      const anyFound = goal.values.some(v => text.includes(v.toLowerCase()));
      return { pass: anyFound, detail: anyFound ? 'keyword found' : 'no keywords found' };
    }

    case 'response_indicates_limitation': {
      const anyFound = goal.keywords.some(kw => mentions(text, kw));
      return { pass: anyFound, detail: anyFound ? 'limitation acknowledged' : 'no limitation reported' };
    }

    case 'response_regex': {
      const missing = goal.patterns.filter(p => !new RegExp(p, 'i').test(response || ''));
      return { pass: missing.length === 0, detail: missing.length === 0 ? 'all patterns matched' : `no match for: ${missing.join(' ; ')}` };
    }

    case 'all': {
      const results = goal.goals.map(g => gradeResponse(g, response));
      if (results.some(r => r === null)) return null;
      const failed = results.find(r => !r.pass);
      return failed ?? { pass: true, detail: 'all goals met' };
    }

    case 'response_match':
      return { pass: null, detail: 'manual review required' };

    default:
      return null;
  }
}

export function evaluateGoal(goal, response) {
  switch (goal.type) {
    case 'api_state':
      return verifyApiState(goal);
    case 'api_state_chained':
      return verifyApiStateChained(goal);
    case 'all': {
      for (const g of goal.goals) {
        const result = evaluateGoal(g, response);
        if (!result.pass) return result;
      }
      return { pass: true, detail: 'all goals met' };
    }
    default:
      return gradeResponse(goal, response) ?? { pass: null, detail: `unknown goal type: ${goal.type}` };
  }
}

function fetchJsonLd(url) {
  const raw = execFileSync('curl', ['-sk', `${DEMO_BASE_URL}${url}`, '-H', 'Accept: application/ld+json'], { timeout: 10_000, encoding: 'utf-8' });
  return JSON.parse(raw);
}

// Members of every page, so a check does not depend on where the new member lands.
function allMembers(data) {
  const members = [...(data['hydra:member'] || [])];
  let next = data['hydra:view']?.['hydra:next'];
  while (next) {
    const page = fetchJsonLd(next);
    members.push(...(page['hydra:member'] || []));
    next = page['hydra:view']?.['hydra:next'];
  }
  return members;
}

function field(member, path) {
  return path.split('.').reduce((value, key) => value?.[key], member);
}

// Strings match case-insensitively as substrings, other values strictly.
function memberMatches(member, where) {
  return Object.entries(where).every(([path, value]) =>
    typeof value === 'string'
      ? String(field(member, path) ?? '').toLowerCase().includes(value.toLowerCase())
      : field(member, path) === value
  );
}

export function verifyApiState(goal) {
  try {
    const data = fetchJsonLd(goal.url);
    let members;
    const getMembers = () => (members ??= allMembers(data));

    for (const [key, expected] of Object.entries(goal.expect)) {
      if (key === 'hydra:totalItems') {
        const actual = data['hydra:totalItems'];
        if (actual !== expected) {
          return { pass: false, detail: `hydra:totalItems: expected ${expected}, got ${actual}` };
        }
      } else if (key === 'hydra:totalItems_gte') {
        const actual = data['hydra:totalItems'];
        if (actual < expected) {
          return { pass: false, detail: `hydra:totalItems: expected >= ${expected}, got ${actual}` };
        }
      } else if (key === 'member_matches') {
        if (!getMembers().some(m => memberMatches(m, expected))) {
          return { pass: false, detail: `no member matches ${JSON.stringify(expected)}` };
        }
      } else if (key === 'members_matching_gte') {
        const count = getMembers().filter(m => memberMatches(m, expected.where)).length;
        if (count < expected.count) {
          return { pass: false, detail: `${count} members match ${JSON.stringify(expected.where)}, expected >= ${expected.count}` };
        }
      } else if (key === 'member_condition_contains') {
        if (!getMembers().some(m => memberMatches(m, { condition: expected }))) {
          return { pass: false, detail: `no member has condition containing "${expected}"` };
        }
      } else if (key === 'all_members_condition_contains') {
        if (getMembers().length === 0) {
          return { pass: false, detail: 'no members found' };
        }
        if (!getMembers().every(m => memberMatches(m, { condition: expected }))) {
          return { pass: false, detail: `not all members have condition containing "${expected}"` };
        }
      } else if (key === 'member_body_contains') {
        if (!getMembers().some(m => memberMatches(m, { body: expected }))) {
          return { pass: false, detail: `no member has body containing "${expected}"` };
        }
      } else {
        return { pass: null, detail: `unknown expectation: ${key}` };
      }
    }

    return { pass: true, detail: 'API state verified' };
  } catch (err) {
    return { pass: false, detail: `API check failed: ${err.message}` };
  }
}

export function verifyApiStateChained(goal) {
  try {
    let baseUrl = '';
    for (const step of goal.steps) {
      const url = step.url || (baseUrl + step.urlSuffix);

      if (step.extract === 'first_member_id') {
        const members = fetchJsonLd(url)['hydra:member'] || [];
        if (members.length === 0) {
          return { pass: false, detail: `no members found at ${url}` };
        }
        baseUrl = members[0]['@id'];
      }

      if (step.expect) {
        const result = verifyApiState({ url, expect: step.expect });
        if (!result.pass) return result;
      }
    }
    return { pass: true, detail: 'chained API state verified' };
  } catch (err) {
    return { pass: false, detail: `chained API check failed: ${err.message}` };
  }
}
