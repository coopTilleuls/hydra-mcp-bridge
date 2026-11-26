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
 */

import { existsSync, readFileSync, readdirSync, writeFileSync, mkdirSync } from 'node:fs';
import { join, resolve, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

import { TASKS } from './tasks.mjs';
import { gradeResponse } from './grade.mjs';

const __dirname = dirname(fileURLToPath(import.meta.url));
const RESULTS_DIR = process.env.EVAL_RESULTS_DIR ?? resolve(__dirname, '..', 'var/eval/results');

// Build task lookup by ID for re-evaluation
const TASK_BY_ID = Object.fromEntries(TASKS.map(t => [t.id, t]));

function parseArgs() {
  const args = process.argv.slice(2);
  const opts = { format: 'table', model: null, tier: null, condition: null };
  for (let i = 0; i < args.length; i++) {
    switch (args[i]) {
      case '--format': opts.format = args[++i]; break;
      case '--model': opts.model = args[++i]; break;
      // Legacy compat
      case '--cli': opts.model = args[++i]; break;
      case '--tier': opts.tier = parseInt(args[++i], 10); break;
      case '--condition': opts.condition = args[++i]?.toUpperCase(); break;
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
  const files = readdirSync(RESULTS_DIR).filter(f => f.endsWith('.json') && f !== 'summary.json' && f !== 'judgments.json');
  const judgmentsPath = join(RESULTS_DIR, 'judgments.json');
  const judgments = existsSync(judgmentsPath) ? JSON.parse(readFileSync(judgmentsPath, 'utf-8')) : {};
  let results = files.map(f => ({ ...JSON.parse(readFileSync(join(RESULTS_DIR, f), 'utf-8')), file: f }));

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

  if (opts.model) results = results.filter(r => agentKey(r) === opts.model);
  if (opts.tier) results = results.filter(r => r.tier === opts.tier);
  if (opts.condition) results = results.filter(r => r.condition === opts.condition);

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

function computeM2(results) {
  const groups = {};
  for (const r of results) {
    const key = `${r.taskId}_${agentKey(r)}`;
    if (!groups[key]) groups[key] = [];
    groups[key].push(r.gatewayMetrics?.toolCallCount || 0);
  }

  return Object.entries(groups).map(([key, counts]) => {
    const parts = key.split('_');
    const agent = parts.pop();
    const taskId = parts.join('_');
    return {
      taskId,
      agent,
      median: median(counts),
      mean: mean(counts).toFixed(1),
      min: Math.min(...counts),
      max: Math.max(...counts),
      ...iqr(counts),
    };
  });
}

// ─── M3: Error Rate ─────────────────────────────────────────────────────────

function computeM3(results) {
  const groups = {};
  for (const r of results) {
    const key = agentKey(r);
    if (!groups[key]) groups[key] = { errors: 0, toolCalls: 0, runs: 0 };
    groups[key].errors += r.gatewayMetrics?.errorCount || 0;
    groups[key].toolCalls += r.gatewayMetrics?.toolCallCount || 0;
    groups[key].runs++;
  }

  return Object.entries(groups).map(([agent, g]) => ({
    agent,
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

function effectiveTokens(usage) {
  if (!usage) return { input: 0, output: 0, total: 0 };
  const input = (usage.inputTokens || 0)
    + (usage.cacheReadTokens || 0)
    + (usage.cacheCreationTokens || 0);
  const output = usage.outputTokens || 0;
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
// Estimates how many tokens of each turn's context are spent on tool schemas.
//
// OpenAPI (condition B): all 19 tools are injected into every turn.
// Hydra   (condition A): starts with 1 tool, grows lazily as collections
//                         are visited — only the tools the agent needs.
// Hydra   (condition C): fixed two-tool list (read_api_resource,
//                         invoke_api_operation). Operation schemas travel in
//                         tool results instead, so they show up in input
//                         tokens and not in the schema overhead column.
//
// This gap widens with API size: a 200-operation API would inject ~17,800
// tool-schema tokens per turn under OpenAPI, while the Hydra gateway still
// starts at ~51.

// Measured from the actual OpenAPI MCP server tools/list response
const OPENAPI_TOOL_COUNT = 19;
const OPENAPI_SCHEMA_TOKENS = 1685; // ~6738 chars / 4
const HYDRA_STATIC_SCHEMA_TOKENS = 51; // 1 tool, ~202 chars / 4
const AVG_TOKENS_PER_HYDRA_TOOL = 80; // Hydra tools have simpler schemas
// Estimated schema size of the two static tools (condition C); an estimate,
// to be replaced by a measurement of the gateway's tools/list response.
const HYDRA_STATIC_TOOLS_SCHEMA_TOKENS = 2 * AVG_TOKENS_PER_HYDRA_TOOL;

const usesGateway = r => (r.condition || 'A') !== 'B';

function computeM6(results) {
  const rows = [];
  for (const r of results) {
    if (!r.usage) continue; // skip runs with no usage data

    const isGateway = usesGateway(r);
    const isStatic = r.condition === 'C';
    const eff = effectiveTokens(r.usage);
    if (eff.input === 0) continue;

    let schemaTokensPerTurn;
    let maxToolCount;

    if (isGateway) {
      const snapshots = r.gatewayMetrics?.toolListSnapshots || [1];
      maxToolCount = Math.max(1, ...snapshots);
      // Average tools across snapshots ≈ turns
      const avgToolCount = snapshots.length > 0
        ? snapshots.reduce((s, v) => s + v, 0) / snapshots.length
        : 1;
      schemaTokensPerTurn = isStatic
        ? HYDRA_STATIC_TOOLS_SCHEMA_TOKENS
        : Math.ceil(avgToolCount * AVG_TOKENS_PER_HYDRA_TOOL);
    } else {
      maxToolCount = OPENAPI_TOOL_COUNT;
      schemaTokensPerTurn = OPENAPI_SCHEMA_TOKENS;
    }

    // Estimate turn count from gateway calls (A, C) or input growth (B)
    let turns;
    if (isGateway) {
      turns = Math.max(1, (r.gatewayMetrics?.toolCallCount || 0) + 1);
    } else {
      // Each turn re-sends schemas + growing conversation
      turns = Math.max(1, Math.round(eff.input / (schemaTokensPerTurn + 500)));
    }

    const cumulativeSchemaOverhead = schemaTokensPerTurn * turns;
    const schemaPctOfInput = ((cumulativeSchemaOverhead / eff.input) * 100).toFixed(1);

    rows.push({
      taskId: r.taskId,
      condition: r.condition || 'A',
      agent: agentKey(r),
      maxTools: maxToolCount,
      schemaPerTurn: schemaTokensPerTurn,
      estTurns: turns,
      schemaOverhead: cumulativeSchemaOverhead,
      inputTokens: eff.input,
      schemaPct: schemaPctOfInput + '%',
    });
  }

  return rows;
}

// ─── M7: A vs B Comparison (per CLI) ───────────────────────────────────────

function computeComparison(results) {
  // Group by agent × condition
  const groups = {};
  for (const r of results) {
    const agent = agentKey(r);
    const cond = r.condition || 'A';
    const key = `${cond}_${agent}`;
    if (!groups[key]) groups[key] = { condition: cond, agent, pass: 0, fail: 0, manual: 0, totalTokens: 0, count: 0, broken: 0 };
    const g = groups[key];
    g.count++;
    const eff = effectiveTokens(r.usage);
    g.totalTokens += eff.total;
    if (r.goalResult?.pass === true) g.pass++;
    else if (r.goalResult?.pass === false) g.fail++;
    else g.manual++;
    // Detect infrastructure failures (no usage = broken MCP)
    if (!r.usage || (eff.total === 0 && r.exitCode !== 0)) g.broken++;
  }

  // Collect unique agents that have both A and B results
  const agents = new Set();
  for (const { agent } of Object.values(groups)) agents.add(agent);

  const rows = [];
  for (const agent of agents) {
    const a = groups['A_' + agent];
    const b = groups['B_' + agent];
    if (!a || !b) continue;
    const aAutoTotal = a.pass + a.fail;
    const bAutoTotal = b.pass + b.fail;
    rows.push({
      agent,
      'A_pass': `${a.pass}/${aAutoTotal}`,
      'A_tokens': a.totalTokens,
      'A_broken': a.broken,
      'B_pass': `${b.pass}/${bAutoTotal}`,
      'B_tokens': b.totalTokens,
      'B_broken': b.broken,
      tokenRatio: b.totalTokens > 0 ? (a.totalTokens / b.totalTokens).toFixed(2) + 'x' : 'N/A (B=0)',
    });
  }
  return rows;
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

  const m1 = computeM1(results);
  const m2 = computeM2(results);
  const m3 = computeM3(results);
  const m5 = computeM5(results);
  const m6 = computeM6(results);
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
    console.log('\n--- M6: Tool Schema Overhead ---');
    console.log(toCsv(m6));
    console.log('\n--- A vs B Comparison ---');
    console.log(toCsv(comparison));
    console.log('\n--- Tier Summary ---');
    console.log(toCsv(tiers));

    // Also write CSV files
    mkdirSync(RESULTS_DIR, { recursive: true });
    writeFileSync(join(RESULTS_DIR, 'summary_m1.csv'), toCsv(m1));
    writeFileSync(join(RESULTS_DIR, 'summary_m2.csv'), toCsv(m2));
    writeFileSync(join(RESULTS_DIR, 'summary_m3.csv'), toCsv(m3));
    writeFileSync(join(RESULTS_DIR, 'summary_m5.csv'), toCsv(m5));
    writeFileSync(join(RESULTS_DIR, 'summary_m6.csv'), toCsv(m6));
    writeFileSync(join(RESULTS_DIR, 'summary_comparison.csv'), toCsv(comparison));
    writeFileSync(join(RESULTS_DIR, 'summary_tiers.csv'), toCsv(tiers));
    console.log(`\nCSV files written to ${RESULTS_DIR}/summary_*.csv`);
  } else {
    printTable('M1: Task Completion', m1);
    printTable('M2: Tool Call Count (per task × cli)', m2);
    printTable('M3: Error Rate (aggregate per CLI)', m3);
    printTable('M5: Token / Context Cost (includes cache tokens)', m5);
    printTable('M6: Tool Schema Overhead', m6);
    printTable('A vs B Comparison', comparison);
    printTable('Tier Summary', tiers);

    // Negative capability breakdown
    const negCap = m1.filter(r => r.isNegativeCapability);
    if (negCap.length > 0) {
      printTable('Negative Capability Tests (T1.5, T2.5, T2.6, T4.4)', negCap);
    }
  }

  // Write JSON summary
  const summary = { m1, m2, m3, m5, m6, comparison, tiers, resultCount: results.length };
  mkdirSync(RESULTS_DIR, { recursive: true });
  writeFileSync(join(RESULTS_DIR, 'summary.json'), JSON.stringify(summary, null, 2));
  console.log(`\nJSON summary: ${RESULTS_DIR}/summary.json`);
}

main();
