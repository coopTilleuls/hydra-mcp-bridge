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

import { readFileSync, readdirSync, writeFileSync, mkdirSync } from 'node:fs';
import { join, resolve, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = dirname(fileURLToPath(import.meta.url));
const RESULTS_DIR = resolve(__dirname, '..', 'var/eval/results');

function parseArgs() {
  const args = process.argv.slice(2);
  const opts = { format: 'table', cli: null, tier: null, condition: null };
  for (let i = 0; i < args.length; i++) {
    switch (args[i]) {
      case '--format': opts.format = args[++i]; break;
      case '--cli': opts.cli = args[++i]; break;
      case '--tier': opts.tier = parseInt(args[++i], 10); break;
      case '--condition': opts.condition = args[++i]?.toUpperCase(); break;
    }
  }
  return opts;
}

function loadResults(opts) {
  const files = readdirSync(RESULTS_DIR).filter(f => f.endsWith('.json') && f !== 'summary.json');
  let results = files.map(f => JSON.parse(readFileSync(join(RESULTS_DIR, f), 'utf-8')));

  if (opts.cli) results = results.filter(r => r.cli === opts.cli);
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
  // Group by taskId × condition × cli
  const groups = {};
  for (const r of results) {
    const key = `${r.taskId}_${r.condition || 'A'}_${r.cli}`;
    if (!groups[key]) groups[key] = [];
    groups[key].push(r);
  }

  const rows = [];
  for (const [key, runs] of Object.entries(groups)) {
    const autoEval = runs.filter(r => r.goalResult?.pass !== null);
    const passes = autoEval.filter(r => r.goalResult?.pass === true);
    const passRate = autoEval.length > 0 ? passes.length / autoEval.length : null;
    // pass^k: fraction where ALL runs pass
    const allPass = autoEval.length > 0 && autoEval.every(r => r.goalResult?.pass === true);

    rows.push({
      taskId: runs[0].taskId,
      tier: runs[0].tier,
      condition: runs[0].condition || 'A',
      cli: runs[0].cli,
      runs: runs.length,
      autoEvaluated: autoEval.length,
      passes: passes.length,
      passRate,
      passK: allPass,
      isNegativeCapability: runs[0].isNegativeCapability,
    });
  }

  return rows;
}

// ─── M2: Tool Call Count ────────────────────────────────────────────────────

function computeM2(results) {
  const groups = {};
  for (const r of results) {
    const key = `${r.taskId}_${r.cli}`;
    if (!groups[key]) groups[key] = [];
    groups[key].push(r.gatewayMetrics?.toolCallCount || 0);
  }

  return Object.entries(groups).map(([key, counts]) => {
    const [taskId, cli] = key.split('_');
    return {
      taskId,
      cli,
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
    const key = r.cli;
    if (!groups[key]) groups[key] = { errors: 0, toolCalls: 0, runs: 0 };
    groups[key].errors += r.gatewayMetrics?.errorCount || 0;
    groups[key].toolCalls += r.gatewayMetrics?.toolCallCount || 0;
    groups[key].runs++;
  }

  return Object.entries(groups).map(([cli, g]) => ({
    cli,
    totalErrors: g.errors,
    totalToolCalls: g.toolCalls,
    errorRate: g.toolCalls > 0 ? (g.errors / g.toolCalls * 100).toFixed(1) + '%' : 'N/A',
    errorsPerRun: (g.errors / g.runs).toFixed(2),
  }));
}

// ─── M5: Token / Context Cost ───────────────────────────────────────────────

function computeM5(results) {
  const groups = {};
  for (const r of results) {
    const key = `${r.taskId}_${r.cli}`;
    if (!groups[key]) groups[key] = [];

    const snapshots = r.gatewayMetrics?.toolListSnapshots || [];
    const cumulativeTools = snapshots.reduce((s, v) => s + v, 0);
    const usage = r.usage || {};

    groups[key].push({
      cumulativeToolListSize: cumulativeTools,
      inputTokens: usage.inputTokens || 0,
      outputTokens: usage.outputTokens || 0,
      totalTokens: usage.totalTokens || 0,
    });
  }

  return Object.entries(groups).map(([key, runs]) => {
    const [taskId, cli] = key.split('_');
    return {
      taskId,
      cli,
      medianCumulativeTools: median(runs.map(r => r.cumulativeToolListSize)),
      medianInputTokens: median(runs.map(r => r.inputTokens)),
      medianOutputTokens: median(runs.map(r => r.outputTokens)),
      medianTotalTokens: median(runs.map(r => r.totalTokens)),
    };
  });
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
    const key = `Tier ${row.tier} — ${row.condition} — ${row.cli}`;
    if (!tiers[key]) tiers[key] = { tier: row.tier, condition: row.condition, cli: row.cli, tasks: 0, passes: 0, autoEval: 0, allPassK: 0 };
    tiers[key].tasks++;
    tiers[key].passes += row.passes;
    tiers[key].autoEval += row.autoEvaluated;
    if (row.passK) tiers[key].allPassK++;
  }

  return Object.values(tiers).map(t => ({
    tier: t.tier,
    condition: t.condition,
    cli: t.cli,
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
  const tiers = tierSummary(m1);

  if (opts.format === 'csv') {
    console.log('\n--- M1: Task Completion ---');
    console.log(toCsv(m1));
    console.log('\n--- M2: Tool Call Count ---');
    console.log(toCsv(m2));
    console.log('\n--- M3: Error Rate ---');
    console.log(toCsv(m3));
    console.log('\n--- M5: Token/Context Cost ---');
    console.log(toCsv(m5));
    console.log('\n--- Tier Summary ---');
    console.log(toCsv(tiers));

    // Also write CSV files
    mkdirSync(RESULTS_DIR, { recursive: true });
    writeFileSync(join(RESULTS_DIR, 'summary_m1.csv'), toCsv(m1));
    writeFileSync(join(RESULTS_DIR, 'summary_m2.csv'), toCsv(m2));
    writeFileSync(join(RESULTS_DIR, 'summary_m3.csv'), toCsv(m3));
    writeFileSync(join(RESULTS_DIR, 'summary_m5.csv'), toCsv(m5));
    writeFileSync(join(RESULTS_DIR, 'summary_tiers.csv'), toCsv(tiers));
    console.log(`\nCSV files written to ${RESULTS_DIR}/summary_*.csv`);
  } else {
    printTable('M1: Task Completion', m1);
    printTable('M2: Tool Call Count (per task × cli)', m2);
    printTable('M3: Error Rate (aggregate per CLI)', m3);
    printTable('M5: Token / Context Cost', m5);
    printTable('Tier Summary', tiers);

    // Negative capability breakdown
    const negCap = m1.filter(r => r.isNegativeCapability);
    if (negCap.length > 0) {
      printTable('Negative Capability Tests (T1.5, T2.5, T2.6, T4.4)', negCap);
    }
  }

  // Write JSON summary
  const summary = { m1, m2, m3, m5, tiers, resultCount: results.length };
  mkdirSync(RESULTS_DIR, { recursive: true });
  writeFileSync(join(RESULTS_DIR, 'summary.json'), JSON.stringify(summary, null, 2));
  console.log(`\nJSON summary: ${RESULTS_DIR}/summary.json`);
}

main();
