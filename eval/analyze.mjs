#!/usr/bin/env node

/**
 * Eval Metrics Analyzer
 *
 * Reads result files from var/eval/results/ and computes M1–M5 metrics.
 *
 * Usage:
 *   node eval/analyze.mjs                    # Full summary (table)
 *   node eval/analyze.mjs --format csv       # CSV output
 *   node eval/analyze.mjs --cli claude       # Filter by CLI
 *   node eval/analyze.mjs --tier 1           # Filter by tier
 *   node eval/analyze.mjs --scaling var/eval/results,var/eval/scaling --tasks T1.1,T1.3,T2.1,T2.4,T4.1
 *                                            # Prompt tokens per API size (paths: result dirs or roots of them)
 *   node eval/analyze.mjs --overhead var/eval/overhead
 *                                            # First-step prompt of the probe task per API size (M6)
 */

import { existsSync, readFileSync, readdirSync, writeFileSync, mkdirSync } from 'node:fs';
import { join, resolve, dirname } from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';

import { TASKS, PROBE } from './tasks.mjs';
import { gradeResponse } from './grade.mjs';

const __dirname = dirname(fileURLToPath(import.meta.url));
const RESULTS_DIR = process.env.EVAL_RESULTS_DIR ?? resolve(__dirname, '..', 'var/eval/results');

// Build task lookup by ID for re-evaluation
const TASK_BY_ID = Object.fromEntries(TASKS.map(t => [t.id, t]));

function parseArgs() {
  const args = process.argv.slice(2);
  const opts = { format: 'table', model: null, tier: null, condition: null, scaling: null, overhead: null, tasks: null };
  for (let i = 0; i < args.length; i++) {
    switch (args[i]) {
      case '--format': opts.format = args[++i]; break;
      case '--model': opts.model = args[++i]; break;
      // Legacy compat
      case '--cli': opts.model = args[++i]; break;
      case '--tier': opts.tier = parseInt(args[++i], 10); break;
      case '--condition': opts.condition = args[++i]?.toUpperCase(); break;
      case '--scaling': opts.scaling = args[++i]; break;
      case '--overhead': opts.overhead = args[++i]; break;
      case '--tasks': opts.tasks = args[++i]?.split(','); break;
    }
  }
  return opts;
}

// Normalize result: old format uses `cli`, new format uses `modelSlug`.
// We unify on `agent` as the grouping key.
function agentKey(r) {
  return r.modelSlug || r.cli;
}

// API state goals cannot be regraded offline, the DB is reset after each run, so their baked result stays.
function reEvaluateGoal(result) {
  const task = TASK_BY_ID[result.taskId];
  if (!task) return result.goalResult;

  return gradeResponse(task.goal, result.response) ?? result.goalResult;
}

function loadResults(opts) {
  const paths = opts.overhead ?? opts.scaling;
  let results = (paths ? resultDirs(paths) : [RESULTS_DIR]).flatMap(loadDir);
  if (!opts.overhead) results = withoutProbes(results);
  if (opts.tasks) results = results.filter(r => opts.tasks.includes(r.taskId));

  if (opts.model) results = results.filter(r => agentKey(r) === opts.model);
  if (opts.tier) results = results.filter(r => r.tier === opts.tier);
  if (opts.condition) results = results.filter(r => r.condition === opts.condition);

  return results;
}

// Comma-separated paths, each a directory of results or a root whose subdirectories hold them (var/eval/scaling/n10).
export function resultDirs(paths) {
  return paths.split(',').flatMap(path => {
    const entries = readdirSync(path, { withFileTypes: true });
    if (entries.some(e => e.isFile() && e.name.endsWith('.json'))) return [path];
    return entries.filter(e => e.isDirectory()).map(e => join(path, e.name)).sort((x, y) => x.localeCompare(y, 'en', { numeric: true }));
  });
}

function loadDir(dir) {
  const files = readdirSync(dir).filter(f => f.endsWith('.json') && f !== 'summary.json' && f !== 'judgments.json');
  const judgmentsPath = join(dir, 'judgments.json');
  const judgments = existsSync(judgmentsPath) ? JSON.parse(readFileSync(judgmentsPath, 'utf-8')) : {};
  const results = files.map(f => ({ ...JSON.parse(readFileSync(join(dir, f), 'utf-8')), file: f }));

  // Re-evaluate response-based goals against current task definitions
  for (const r of results) {
    r.goalResult = reEvaluateGoal(r);
    // The judge verdict (eval/judge.mjs) replaces the keyword verdict for the M1 pass rate, the keyword one is kept to report both.
    const judgment = judgments[r.file];
    if (judgment) {
      r.keywordPass = r.goalResult?.pass ?? null;
      r.judgePass = judgment.verdict === 'PASS';
      r.goalResult = { pass: r.judgePass, detail: `judge: ${judgment.reason}` };
    }
  }

  return results;
}

function median(arr) {
  if (arr.length === 0) return 0;
  const sorted = [...arr].sort((a, b) => a - b);
  const mid = Math.floor(sorted.length / 2);
  return sorted.length % 2 ? sorted[mid] : (sorted[mid - 1] + sorted[mid]) / 2;
}

function mean(arr) {
  return arr.length === 0 ? 0 : arr.reduce((s, v) => s + v, 0) / arr.length;
}

function iqr(arr) {
  if (arr.length < 4) return { q1: 0, q3: 0, iqr: 0 };
  const sorted = [...arr].sort((a, b) => a - b);
  const q1 = sorted[Math.floor(sorted.length * 0.25)];
  const q3 = sorted[Math.floor(sorted.length * 0.75)];
  return { q1, q3, iqr: q3 - q1 };
}

// ─── M1: Task Completion ────────────────────────────────────────────────────

function computeM1(results) {
  const groups = {};
  for (const r of results) {
    const key = `${r.taskId}_${r.condition || 'A'}_${agentKey(r)}`;
    if (!groups[key]) groups[key] = [];
    groups[key].push(r);
  }

  const rows = [];
  for (const [key, runs] of Object.entries(groups)) {
    const autoEval = runs.filter(r => r.goalResult?.pass !== null);
    const passes = autoEval.filter(r => r.goalResult?.pass === true);
    const passRate = autoEval.length > 0 ? passes.length / autoEval.length : null;
    const allPass = autoEval.length > 0 && autoEval.every(r => r.goalResult?.pass === true);
    const judged = runs.filter(r => r.judgePass !== undefined);

    rows.push({
      taskId: runs[0].taskId,
      tier: runs[0].tier,
      condition: runs[0].condition || 'A',
      agent: agentKey(runs[0]),
      runs: runs.length,
      autoEvaluated: autoEval.length,
      passes: passes.length,
      passRate,
      passK: allPass,
      isNegativeCapability: runs[0].isNegativeCapability,
      keywordPassRate: judged.length > 0 ? judged.filter(r => r.keywordPass === true).length / judged.length : null,
      judgePassRate: judged.length > 0 ? judged.filter(r => r.judgePass).length / judged.length : null,
    });
  }

  return rows;
}

// ─── M2: Tool Call Count ────────────────────────────────────────────────────

export function computeM2(results) {
  const groups = {};
  for (const r of results) {
    const key = `${r.taskId}_${r.condition || 'A'}_${agentKey(r)}`;
    groups[key] ??= { taskId: r.taskId, condition: r.condition || 'A', agent: agentKey(r), counts: [] };
    groups[key].counts.push(clientCalls(r).length);
  }

  return Object.values(groups).map(({ taskId, condition, agent, counts }) => ({
    taskId,
    condition,
    agent,
    median: median(counts),
    mean: mean(counts).toFixed(1),
    min: Math.min(...counts),
    max: Math.max(...counts),
    ...iqr(counts),
  }));
}

// ─── M3: Error Rate ─────────────────────────────────────────────────────────

// The client's view counts for every condition: B never talks to the gateway, so gatewayMetrics is empty there.
function clientCalls(r) {
  return r.clientToolCalls || [];
}

export function computeM3(results) {
  const groups = {};
  for (const r of results) {
    const key = `${r.condition || 'A'}_${agentKey(r)}`;
    groups[key] ??= { condition: r.condition || 'A', agent: agentKey(r), errors: 0, toolCalls: 0, runs: 0 };
    const calls = clientCalls(r);
    groups[key].errors += calls.filter(c => c.status === 'error').length;
    groups[key].toolCalls += calls.length;
    groups[key].runs++;
  }

  return Object.values(groups).map(g => ({
    condition: g.condition,
    agent: g.agent,
    totalErrors: g.errors,
    totalToolCalls: g.toolCalls,
    errorRate: g.toolCalls > 0 ? (g.errors / g.toolCalls * 100).toFixed(1) + '%' : 'N/A',
    errorsPerRun: (g.errors / g.runs).toFixed(2),
  }));
}

// ─── M5: Token / Context Cost ───────────────────────────────────────────────
//
// Token totals include cache tokens (cacheReadTokens, cacheCreationTokens)
// because they represent real context processed by the model, even if billed
// at a discount. Without them, Claude's numbers look artificially low.

// Results written before promptTokens existed are rebuilt the same way (eval/adapters/opencode.mjs sumUsage).
export function promptTokens(usage) {
  if (!usage) return 0;
  return usage.promptTokens ?? (usage.inputTokens || 0) + (usage.cacheReadTokens || 0) + (usage.cacheCreationTokens || 0);
}

function effectiveTokens(usage) {
  const input = promptTokens(usage);
  const output = usage?.outputTokens || 0;
  return { input, output, total: input + output };
}

function computeM5(results) {
  const groups = {};
  for (const r of results) {
    const agent = agentKey(r);
    const key = `${r.taskId}_${r.condition || 'A'}_${agent}`;
    if (!groups[key]) groups[key] = { agent, condition: r.condition || 'A', taskId: r.taskId, runs: [] };

    const snapshots = r.gatewayMetrics?.toolListSnapshots || [];
    const cumulativeTools = snapshots.reduce((s, v) => s + v, 0);
    const eff = effectiveTokens(r.usage);

    groups[key].runs.push({
      cumulativeToolListSize: cumulativeTools,
      inputTokens: eff.input,
      outputTokens: eff.output,
      totalTokens: eff.total,
    });
  }

  return Object.entries(groups).map(([key, { agent, condition, taskId, runs }]) => {
    return {
      taskId,
      condition,
      agent,
      medianCumulativeTools: median(runs.map(r => r.cumulativeToolListSize)),
      medianInputTokens: median(runs.map(r => r.inputTokens)),
      medianOutputTokens: median(runs.map(r => r.outputTokens)),
      medianTotalTokens: median(runs.map(r => r.totalTokens)),
    };
  });
}

// ─── M6: Tool Schema Overhead ──────────────────────────────────────────────
//
// Measured, not estimated: the probe task (eval/tasks.mjs PROBE) asks for a reply without tools, so its first step's
// prompt is the system prompt, the tool definitions and the user message, counted by the model's own tokenizer.

export const withoutProbes = results => results.filter(r => r.taskId !== PROBE.id);

export function computeOverhead(results) {
  const probes = results.filter(r => r.taskId === PROBE.id && r.usage?.stepPromptTokens?.length);

  return computeScaling(probes, runs => Math.round(mean(runs.map(r => r.usage.stepPromptTokens[0]))), false);
}

// ─── M7: Conditions compared (per model) ──────────────────────────────────
//
// B, the OpenAPI baseline, is the reference: vsB is a condition's mean prompt tokens over B's.

const byAgentThenCondition = (x, y) => x.agent.localeCompare(y.agent) || x.condition.localeCompare(y.condition);

function passCount(runs) {
  const graded = runs.filter(r => r.goalResult?.pass === true || r.goalResult?.pass === false);
  return `${graded.filter(r => r.goalResult.pass).length}/${graded.length}`;
}

const meanPrompt = runs => Math.round(mean(runs.map(r => promptTokens(r.usage))));

export function computeComparison(results) {
  const groups = {};
  for (const r of results) {
    const key = `${r.condition || 'A'}_${agentKey(r)}`;
    groups[key] ??= { condition: r.condition || 'A', agent: agentKey(r), runs: [] };
    groups[key].runs.push(r);
  }

  return Object.values(groups).sort(byAgentThenCondition).map(({ agent, condition, runs }) => {
    const b = groups[`B_${agent}`];
    const baseline = b ? meanPrompt(b.runs) : 0;
    return {
      agent,
      condition,
      pass: passCount(runs),
      meanPromptTokens: meanPrompt(runs),
      vsB: baseline > 0 ? (meanPrompt(runs) / baseline).toFixed(2) + 'x' : 'N/A',
    };
  });
}

// ─── M8: Scaling (prompt tokens per API size) ──────────────────────────────
//
// The demo has 19 operations; each extra resource adds 5 (GetCollection, Post, Get, Patch, Delete),
// see ~/forks/demo api/src/EvalScaling.

const BASE_OPERATIONS = 19;
const OPERATIONS_PER_EXTRA_RESOURCE = 5;

export function computeScaling(results, value = meanPrompt, withPass = true) {
  const rows = {};
  const runs = {};
  for (const r of results) {
    const n = r.extraResources ?? 0;
    const agent = agentKey(r);
    const condition = r.condition || 'A';
    rows[`${agent}_${n}`] ??= { agent, extraResources: n, operations: BASE_OPERATIONS + OPERATIONS_PER_EXTRA_RESOURCE * n };
    (runs[`${agent}_${n}_${condition}`] ??= { row: rows[`${agent}_${n}`], condition, runs: [] }).runs.push(r);
  }

  // Every row gets every condition, in a fixed order, so table columns line up whatever the file order.
  const conditions = [...new Set(Object.values(runs).map(g => g.condition))].sort();
  for (const row of Object.values(rows)) for (const c of conditions) Object.assign(row, { [c]: '', ...(withPass && { [`${c}_pass`]: '' }) });
  for (const { row, condition, runs: group } of Object.values(runs)) {
    row[condition] = value(group);
    if (withPass) row[`${condition}_pass`] = passCount(group);
  }

  return Object.values(rows).sort((x, y) => x.agent.localeCompare(y.agent) || x.extraResources - y.extraResources);
}

// ─── Output formatting ──────────────────────────────────────────────────────

function printTable(title, rows) {
  console.log(`\n=== ${title} ===`);
  if (rows.length === 0) { console.log('  (no data)'); return; }
  console.table(rows);
}

function toCsv(rows) {
  if (rows.length === 0) return '';
  const headers = Object.keys(rows[0]);
  const lines = [headers.join(',')];
  for (const row of rows) {
    lines.push(headers.map(h => {
      const v = row[h];
      return typeof v === 'string' && v.includes(',') ? `"${v}"` : v;
    }).join(','));
  }
  return lines.join('\n');
}

// ─── Per-tier summary ───────────────────────────────────────────────────────

function tierSummary(m1Rows) {
  const tiers = {};
  for (const row of m1Rows) {
    const key = `Tier ${row.tier} — ${row.condition} — ${row.agent}`;
    if (!tiers[key]) tiers[key] = { tier: row.tier, condition: row.condition, agent: row.agent, tasks: 0, passes: 0, autoEval: 0, allPassK: 0 };
    tiers[key].tasks++;
    tiers[key].passes += row.passes;
    tiers[key].autoEval += row.autoEvaluated;
    if (row.passK) tiers[key].allPassK++;
  }

  return Object.values(tiers).map(t => ({
    tier: t.tier,
    condition: t.condition,
    agent: t.agent,
    tasks: t.tasks,
    passRate: t.autoEval > 0 ? (t.passes / t.autoEval * 100).toFixed(1) + '%' : 'N/A',
    passK: `${t.allPassK}/${t.tasks}`,
  }));
}

// ─── Main ───────────────────────────────────────────────────────────────────

function main() {
  const opts = parseArgs();

  let results;
  try {
    results = loadResults(opts);
  } catch (err) {
    console.error(`Cannot load results from ${RESULTS_DIR}: ${err.message}`);
    process.exit(1);
  }

  if (results.length === 0) {
    console.error('No results found. Run eval/run.mjs first.');
    process.exit(1);
  }

  console.log(`Loaded ${results.length} result(s)`);

  if (opts.overhead) {
    const overhead = computeOverhead(results);
    if (opts.format === 'csv') console.log(toCsv(overhead));
    else printTable('M6: First-step prompt tokens (system prompt + tool definitions + user message), by API size', overhead);
    return;
  }

  if (opts.scaling) {
    const scaling = computeScaling(results);
    if (opts.format === 'csv') console.log(toCsv(scaling));
    else printTable('M8: Mean prompt tokens per run, by API size', scaling);
    return;
  }

  const m1 = computeM1(results);
  const m2 = computeM2(results);
  const m3 = computeM3(results);
  const m5 = computeM5(results);
  const comparison = computeComparison(results);
  const tiers = tierSummary(m1);

  if (opts.format === 'csv') {
    console.log('\n--- M1: Task Completion ---');
    console.log(toCsv(m1));
    console.log('\n--- M2: Tool Call Count ---');
    console.log(toCsv(m2));
    console.log('\n--- M3: Error Rate ---');
    console.log(toCsv(m3));
    console.log('\n--- M5: Token/Context Cost (includes cache tokens) ---');
    console.log(toCsv(m5));
    console.log('\n--- M7: Conditions compared ---');
    console.log(toCsv(comparison));
    console.log('\n--- Tier Summary ---');
    console.log(toCsv(tiers));

    // Also write CSV files
    mkdirSync(RESULTS_DIR, { recursive: true });
    writeFileSync(join(RESULTS_DIR, 'summary_m1.csv'), toCsv(m1));
    writeFileSync(join(RESULTS_DIR, 'summary_m2.csv'), toCsv(m2));
    writeFileSync(join(RESULTS_DIR, 'summary_m3.csv'), toCsv(m3));
    writeFileSync(join(RESULTS_DIR, 'summary_m5.csv'), toCsv(m5));
    writeFileSync(join(RESULTS_DIR, 'summary_comparison.csv'), toCsv(comparison));
    writeFileSync(join(RESULTS_DIR, 'summary_tiers.csv'), toCsv(tiers));
    console.log(`\nCSV files written to ${RESULTS_DIR}/summary_*.csv`);
  } else {
    printTable('M1: Task Completion', m1);
    printTable('M2: Tool Call Count (per task × cli)', m2);
    printTable('M3: Error Rate (aggregate per CLI)', m3);
    printTable('M5: Token / Context Cost (includes cache tokens)', m5);
    printTable('M7: Conditions compared (mean prompt tokens per run, vs B)', comparison);
    printTable('Tier Summary', tiers);

    // Negative capability breakdown
    const negCap = m1.filter(r => r.isNegativeCapability);
    if (negCap.length > 0) {
      printTable('Negative Capability Tests (T1.5, T2.5, T2.6, T4.4)', negCap);
    }
  }

  // Write JSON summary
  const summary = { m1, m2, m3, m5, comparison, tiers, resultCount: results.length };
  mkdirSync(RESULTS_DIR, { recursive: true });
  writeFileSync(join(RESULTS_DIR, 'summary.json'), JSON.stringify(summary, null, 2));
  console.log(`\nJSON summary: ${RESULTS_DIR}/summary.json`);
}

if (import.meta.url === pathToFileURL(process.argv[1]).href) main();
