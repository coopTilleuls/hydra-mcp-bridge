#!/usr/bin/env node

/**
 * Adds `clientToolCalls` to eval results that predate the field, from the raw OpenCode event logs.
 *
 * Usage: node eval/backfill-tool-calls.mjs <results-dir> <raw-dir>
 *
 * Each result is matched to the raw *.jsonl file containing its `sessionId`.
 */

import { existsSync, readFileSync, readdirSync, writeFileSync } from 'node:fs';
import { join, resolve } from 'node:path';

import { extractClientToolCalls, parseEvents } from './adapters/opencode.mjs';

const NON_RESULT_FILES = new Set(['summary.json', 'judgments.json']);

export function indexRawLogs(rawDir) {
  const bySession = new Map();
  for (const file of readdirSync(rawDir).filter(f => f.endsWith('.jsonl')).sort()) {
    const events = parseEvents(readFileSync(join(rawDir, file), 'utf-8'));
    const sessionId = events.find(ev => ev.sessionID)?.sessionID;
    if (sessionId) bySession.set(sessionId, events);
  }
  return bySession;
}

function main() {
  const [resultsArg, rawArg] = process.argv.slice(2);
  const resultsDir = resultsArg && resolve(resultsArg);
  const rawDir = rawArg && resolve(rawArg);
  if (!resultsDir || !rawDir || !existsSync(resultsDir) || !existsSync(rawDir)) {
    console.error('Usage: node eval/backfill-tool-calls.mjs <results-dir> <raw-dir>');
    process.exit(1);
  }

  const bySession = indexRawLogs(rawDir);
  let matched = 0;
  const unmatched = [];
  for (const file of readdirSync(resultsDir).filter(f => f.endsWith('.json') && !NON_RESULT_FILES.has(f)).sort()) {
    const path = join(resultsDir, file);
    const result = JSON.parse(readFileSync(path, 'utf-8'));
    const events = result.sessionId && bySession.get(result.sessionId);
    if (!events) {
      unmatched.push(file);
      continue;
    }
    result.clientToolCalls = extractClientToolCalls(events);
    writeFileSync(path, JSON.stringify(result, null, 2));
    matched++;
  }

  console.log(`${bySession.size} raw log(s) indexed: ${matched} result(s) matched, ${unmatched.length} unmatched`);
  for (const file of unmatched) console.log(`  unmatched: ${file}`);
  if (unmatched.length > 0) process.exitCode = 2;
}

if (import.meta.url === `file://${process.argv[1]}`) main();
