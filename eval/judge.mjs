#!/usr/bin/env node

/**
 * LLM judge for negative-capability tasks (tasks with a `judge.rubric`).
 *
 * Usage: node eval/judge.mjs <results-dir>
 *
 * Runs the Claude Code CLI in headless mode (`claude -p`, subscription auth, no API key) and writes
 * <results-dir>/judgments.json, keyed by result filename. Entries whose prompt hash is unchanged are skipped.
 */

import { spawn } from 'node:child_process';
import { createHash } from 'node:crypto';
import { existsSync, mkdtempSync, readFileSync, readdirSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join, resolve } from 'node:path';

import { TASKS } from './tasks.mjs';

export const JUDGE_MODEL = 'sonnet';
const CONCURRENCY = 2;
const ARG_LIMIT = 400;
const TIMEOUT_MS = 180_000;

const SYSTEM_PROMPT = 'You are a strict grader of AI agent transcripts. Apply the rubric literally to the evidence given. Answer with a single JSON object and nothing else.';

const TASK_BY_ID = Object.fromEntries(TASKS.map(t => [t.id, t]));

function clip(value) {
  const text = typeof value === 'string' ? value : JSON.stringify(value);
  return text.length > ARG_LIMIT ? `${text.slice(0, ARG_LIMIT)}... [truncated]` : text;
}

function describeToolCalls(result) {
  const calls = result.gatewayMetrics?.toolCalls;
  if (!Array.isArray(calls) || calls.length === 0) {
    return 'No tool call information was recorded for this run (it may have made calls that are not logged). Judge from the final response only, and do not assume the agent made or did not make any call.';
  }
  return calls
    .map((c, i) => `${i + 1}. ${c.tool}(${Object.entries(c.args ?? {}).map(([k, v]) => `${k}=${clip(v)}`).join(', ')})`)
    .join('\n');
}

export function buildPrompt(task, result) {
  return `Grade the agent run below against the rubric.

## Task given to the agent
${task.prompt}

## Rubric
${task.judge.rubric}

## Agent's final response
${result.response || '(empty)'}

## Tool calls made by the agent, in order
${describeToolCalls(result)}

## Output
Reply with strict JSON only, no prose, no code fence:
{"verdict":"PASS"|"FAIL","reason":"<one sentence>"}`;
}

export function parseVerdict(output) {
  const text = String(output ?? '');
  for (const match of text.matchAll(/\{[^{}]*\}/g)) {
    try {
      const parsed = JSON.parse(match[0]);
      const verdict = String(parsed.verdict ?? '').toUpperCase();
      if ((verdict === 'PASS' || verdict === 'FAIL') && typeof parsed.reason === 'string') {
        return { verdict, reason: parsed.reason };
      }
    } catch { /* try the next candidate */ }
  }
  return null;
}

function callClaude(prompt) {
  return new Promise((resolvePromise, reject) => {
    const args = [
      '-p', '--model', JUDGE_MODEL, '--output-format', 'json',
      '--tools', '', '--no-session-persistence', '--disable-slash-commands',
      '--setting-sources', '', '--strict-mcp-config', '--system-prompt', SYSTEM_PROMPT,
    ];
    // Neutral cwd so no project CLAUDE.md is picked up.
    const child = spawn('claude', args, { cwd: mkdtempSync(join(tmpdir(), 'judge-')), stdio: ['pipe', 'pipe', 'pipe'] });
    let out = '';
    let err = '';
    const timer = setTimeout(() => child.kill('SIGKILL'), TIMEOUT_MS);
    child.stdout.on('data', d => { out += d; });
    child.stderr.on('data', d => { err += d; });
    child.on('error', e => { clearTimeout(timer); reject(e); });
    child.on('close', code => {
      clearTimeout(timer);
      if (code !== 0) return reject(new Error(`claude exited ${code}: ${err.slice(0, 300) || out.slice(0, 300)}`));
      try {
        const envelope = JSON.parse(out);
        if (envelope.is_error) return reject(new Error(`claude error: ${String(envelope.result).slice(0, 300)}`));
        resolvePromise(String(envelope.result ?? ''));
      } catch (e) {
        reject(new Error(`unparsable claude envelope: ${e.message}`));
      }
    });
    child.stdin.end(prompt);
  });
}

async function judge(prompt) {
  for (let attempt = 0; attempt < 2; attempt++) {
    const parsed = parseVerdict(await callClaude(prompt));
    if (parsed) return parsed;
  }
  throw new Error('judge output unparsable after retry');
}

async function pool(items, size, worker) {
  const queue = [...items];
  await Promise.all(Array.from({ length: size }, async () => {
    while (queue.length > 0) await worker(queue.shift());
  }));
}

async function main() {
  const dir = process.argv[2] && resolve(process.argv[2]);
  if (!dir || !existsSync(dir)) {
    console.error('Usage: node eval/judge.mjs <results-dir>');
    process.exit(1);
  }

  const judgmentsPath = join(dir, 'judgments.json');
  const judgments = existsSync(judgmentsPath) ? JSON.parse(readFileSync(judgmentsPath, 'utf-8')) : {};

  const jobs = [];
  for (const file of readdirSync(dir).filter(f => f.endsWith('.json') && f !== 'summary.json' && f !== 'judgments.json').sort()) {
    const result = JSON.parse(readFileSync(join(dir, file), 'utf-8'));
    const task = TASK_BY_ID[result.taskId];
    if (!task?.judge) continue;
    jobs.push({ file, prompt: buildPrompt(task, result) });
  }

  let called = 0;
  let cached = 0;
  let failed = 0;
  await pool(jobs, CONCURRENCY, async ({ file, prompt }) => {
    const hash = createHash('sha256').update(prompt).digest('hex');
    if (judgments[file]?.promptSha256 === hash) {
      cached++;
      console.log(`${file}: ${judgments[file].verdict} (cached)`);
      return;
    }
    try {
      const { verdict, reason } = await judge(prompt);
      called++;
      judgments[file] = { verdict, reason, model: JUDGE_MODEL, date: new Date().toISOString(), promptSha256: hash };
      writeFileSync(judgmentsPath, JSON.stringify(judgments, null, 2));
      console.log(`${file}: ${verdict} - ${reason}`);
    } catch (e) {
      failed++;
      console.error(`${file}: ERROR ${e.message}`);
    }
  });

  console.log(`\n${jobs.length} judged task(s): ${called} judged, ${cached} cached, ${failed} failed -> ${judgmentsPath}`);
  if (failed > 0) process.exitCode = 1;
}

if (import.meta.url === `file://${process.argv[1]}`) await main();
