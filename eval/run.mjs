#!/usr/bin/env node

/**
 * Eval Benchmark Runner
 *
 * Runs evaluation tasks against OpenCode CLI with multiple models (via GitHub Copilot),
 * collecting structured results + gateway NDJSON logs.
 *
 * Usage:
 *   node eval/run.mjs                                              # All tasks, all models, k=1
 *   node eval/run.mjs --model=github-copilot/gpt-5-mini           # Single model
 *   node eval/run.mjs --task T1.1              # Single task
 *   node eval/run.mjs --tier 1                 # Single tier
 *   node eval/run.mjs --runs 5                 # k=5 repetitions
 *   node eval/run.mjs --timeout 180000         # 3min timeout per task
 *   node eval/run.mjs --condition B            # OpenAPI static condition
 */

import { execFileSync } from 'node:child_process';
import { readFileSync, writeFileSync, mkdirSync, readdirSync, statSync, rmSync, existsSync } from 'node:fs';
import { join } from 'node:path';

import { TASKS } from './tasks.mjs';
import {
  PROJECT_ROOT, DEMO_API_DIR, EVAL_LOG_DIR, RESULTS_DIR,
  MCP_CACHE_DIR, MODELS, GATEWAY_CLIENT_SLUG,
  TEST_HYDRA_DIR, TEST_OPENAPI_DIR, modelSlug,
  SYSTEM_PROMPT_PATH,
} from './config.mjs';
import { runTask } from './adapters/opencode.mjs';

// ─── CLI argument parsing ───────────────────────────────────────────────────

function parseArgs() {
  const args = process.argv.slice(2);
  const opts = {
    model: null,    // null = all models
    task: null,     // null = all
    tier: null,     // null = all
    runs: 1,
    timeoutMs: 120_000,
    dryRun: false,
    skipReset: false,
    condition: 'A', // A = Hydra gateway, B = OpenAPI static
  };

  for (let i = 0; i < args.length; i++) {
    // Support --key=value syntax
    let key = args[i], val;
    if (key.includes('=')) {
      [key, ...val] = key.split('=');
      val = val.join('='); // rejoin in case value contains =
    }
    switch (key) {
      case '--model': opts.model = val ?? args[++i]; break;
      case '--task': opts.task = val ?? args[++i]; break;
      case '--tier': opts.tier = parseInt(val ?? args[++i], 10); break;
      case '--runs': opts.runs = parseInt(val ?? args[++i], 10); break;
      case '--timeout': opts.timeoutMs = parseInt(val ?? args[++i], 10); break;
      case '--dry-run': opts.dryRun = true; break;
      case '--skip-reset': opts.skipReset = true; break;
      case '--condition': {
        const v = (val ?? args[++i])?.toUpperCase();
        if (v !== 'A' && v !== 'B') {
          console.error('--condition must be A (Hydra gateway) or B (OpenAPI static)');
          process.exit(1);
        }
        opts.condition = v;
        break;
      }
      default:
        console.error(`Unknown argument: ${args[i]}`);
        process.exit(1);
    }
  }

  return opts;
}

// ─── Model selection ─────────────────────────────────────────────────────────

function selectModels(modelFilter) {
  if (!modelFilter) return MODELS;

  const match = MODELS.find(m => m === modelFilter);
  if (!match) {
    console.error(`Unknown model: ${modelFilter}`);
    console.error(`Available:\n${MODELS.map(m => '  ' + m).join('\n')}`);
    process.exit(1);
  }
  return [match];
}

// ─── Fixture reset ──────────────────────────────────────────────────────────

function resetFixtures() {
  console.log('  [fixtures] Resetting database...');
  try {
    execFileSync('docker', [
      'compose', '-f', `${DEMO_API_DIR}/compose.yaml`,
      'exec',
      '-e', 'FIXTURES_SEED=42',
      '-e', 'FOUNDRY_FAKER_SEED=42',
      '-e', 'FIXTURES_BOOK_LIMIT=75',
      'php', 'bin/console', 'doctrine:fixtures:load', '--no-interaction',
    ], { timeout: 60_000, stdio: 'pipe' });
  } catch (err) {
    console.error(`  [fixtures] Reset failed: ${err.stderr?.toString() || err.message}`);
    throw err;
  }
}

function clearMcpSessionCache() {
  if (existsSync(MCP_CACHE_DIR)) {
    try {
      const files = readdirSync(MCP_CACHE_DIR);
      for (const f of files) {
        rmSync(join(MCP_CACHE_DIR, f), { force: true });
      }
      console.log(`  [cache] Cleared ${files.length} MCP session files`);
    } catch {
      // Non-critical
    }
  }
}

// ─── Gateway NDJSON log matching ────────────────────────────────────────────

function findNdjsonLog(preRunTimestamp) {
  if (!existsSync(EVAL_LOG_DIR)) return null;

  const candidates = readdirSync(EVAL_LOG_DIR)
    .filter(f => f.endsWith('.ndjson') && f.startsWith(GATEWAY_CLIENT_SLUG + '_'))
    .map(f => ({
      name: f,
      path: join(EVAL_LOG_DIR, f),
      mtime: statSync(join(EVAL_LOG_DIR, f)).mtimeMs,
    }))
    .filter(f => f.mtime >= preRunTimestamp)
    .sort((a, b) => b.mtime - a.mtime);

  if (candidates.length === 0) return null;
  return candidates[0].path;
}

// ─── NDJSON metrics extraction ──────────────────────────────────────────────

function extractGatewayMetrics(ndjsonPath) {
  if (!ndjsonPath || !existsSync(ndjsonPath)) {
    return {
      toolCallCount: 0,
      toolCalls: [],
      errorCount: 0,
      toolListSnapshots: [],
      discoveredTools: [],
    };
  }

  const content = readFileSync(ndjsonPath, 'utf-8');
  const lines = content.trim().split('\n').filter(Boolean);

  const toolCalls = [];
  const errors = [];
  const toolListSnapshots = [];
  const discoveredToolSet = new Set();

  for (const line of lines) {
    let entry;
    try { entry = JSON.parse(line); } catch { continue; }

    if (entry.direction === 'in' && entry.method === 'tools/call') {
      toolCalls.push({
        tool: entry.tool,
        args: entry.arguments || {},
      });
    }

    if (entry.direction === 'out' && entry.method === 'tools/list') {
      toolListSnapshots.push(entry.tool_count || 0);
      if (entry.tool_names) {
        for (const t of entry.tool_names) discoveredToolSet.add(t);
      }
    }

    if (entry.direction === 'error' || entry.is_error === true) {
      errors.push({
        method: entry.method,
        tool: entry.tool || null,
        message: entry.error_message || null,
      });
    }
  }

  return {
    toolCallCount: toolCalls.length,
    toolCalls,
    errorCount: errors.length,
    toolListSnapshots,
    discoveredTools: [...discoveredToolSet],
  };
}

// ─── Goal evaluation ────────────────────────────────────────────────────────

async function evaluateGoal(task, result) {
  const { goal } = task;
  const text = (result.response || '').toLowerCase();

  switch (goal.type) {
    case 'response_contains': {
      const allFound = goal.values.every(v => text.includes(v.toLowerCase()));
      return {
        pass: allFound,
        detail: allFound
          ? 'all keywords found'
          : `missing: ${goal.values.filter(v => !text.includes(v.toLowerCase())).join(', ')}`,
      };
    }

    case 'response_contains_any': {
      const anyFound = goal.values.some(v => text.includes(v.toLowerCase()));
      return { pass: anyFound, detail: anyFound ? 'keyword found' : 'no keywords found' };
    }

    case 'response_indicates_limitation': {
      const anyFound = goal.keywords.some(kw => text.includes(kw.toLowerCase()));
      return {
        pass: anyFound,
        detail: anyFound ? 'limitation acknowledged' : 'no limitation reported',
      };
    }

    case 'api_state': {
      return verifyApiState(goal);
    }

    case 'api_state_chained': {
      return verifyApiStateChained(goal);
    }

    case 'response_match': {
      return { pass: null, detail: 'manual review required' };
    }

    default:
      return { pass: null, detail: `unknown goal type: ${goal.type}` };
  }
}

function verifyApiState(goal) {
  try {
    const raw = execFileSync('curl', [
      '-sk', `https://localhost${goal.url}`,
      '-H', 'Accept: application/ld+json',
    ], { timeout: 10_000, encoding: 'utf-8' });

    const data = JSON.parse(raw);

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
      } else if (key === 'member_condition_contains') {
        const members = data['hydra:member'] || [];
        const found = members.some(m =>
          (m.condition || '').toLowerCase().includes(expected.toLowerCase())
        );
        if (!found) {
          return { pass: false, detail: `no member has condition containing "${expected}"` };
        }
      } else if (key === 'all_members_condition_contains') {
        const members = data['hydra:member'] || [];
        if (members.length === 0) {
          return { pass: false, detail: 'no members found' };
        }
        const allMatch = members.every(m =>
          (m.condition || '').toLowerCase().includes(expected.toLowerCase())
        );
        if (!allMatch) {
          return { pass: false, detail: `not all members have condition containing "${expected}"` };
        }
      } else if (key === 'member_body_contains') {
        const members = data['hydra:member'] || [];
        const found = members.some(m =>
          (m.body || '').toLowerCase().includes(expected.toLowerCase())
        );
        if (!found) {
          return { pass: false, detail: `no member has body containing "${expected}"` };
        }
      }
    }

    return { pass: true, detail: 'API state verified' };
  } catch (err) {
    return { pass: false, detail: `API check failed: ${err.message}` };
  }
}

function verifyApiStateChained(goal) {
  try {
    let baseUrl = '';
    for (const step of goal.steps) {
      const url = step.url || (baseUrl + step.urlSuffix);
      const raw = execFileSync('curl', [
        '-sk', `https://localhost${url}`,
        '-H', 'Accept: application/ld+json',
      ], { timeout: 10_000, encoding: 'utf-8' });

      const data = JSON.parse(raw);

      if (step.extract === 'first_member_id') {
        const members = data['hydra:member'] || [];
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

// ─── Result persistence ─────────────────────────────────────────────────────

function saveResult(result, condSuffix) {
  mkdirSync(RESULTS_DIR, { recursive: true });
  const slug = result.modelSlug.replace(/\//g, '_');
  const filename = `${result.taskId}_${condSuffix}_${slug}_${result.run}.json`;
  writeFileSync(join(RESULTS_DIR, filename), JSON.stringify(result, null, 2));
}

// ─── Main ───────────────────────────────────────────────────────────────────

async function main() {
  const opts = parseArgs();

  const isConditionA = opts.condition === 'A';
  const conditionLabel = isConditionA ? 'A (Hydra gateway)' : 'B (OpenAPI static)';
  const cwd = isConditionA ? TEST_HYDRA_DIR : TEST_OPENAPI_DIR;
  const systemPrompt = isConditionA
    ? readFileSync(SYSTEM_PROMPT_PATH, 'utf-8')
    : '';

  // Filter tasks
  let tasks = TASKS;
  if (opts.task) tasks = tasks.filter(t => t.id === opts.task);
  if (opts.tier) tasks = tasks.filter(t => t.tier === opts.tier);

  if (tasks.length === 0) {
    console.error('No tasks match the filter criteria');
    process.exit(1);
  }

  // Select models
  const models = selectModels(opts.model);

  console.log(`\n=== Eval Benchmark Runner ===`);
  console.log(`Condition: ${conditionLabel}`);
  console.log(`CWD: ${cwd}`);
  console.log(`Tasks: ${tasks.length}, Models: ${models.map(m => modelSlug(m)).join(', ')}, Runs: ${opts.runs}`);
  console.log(`Timeout: ${opts.timeoutMs}ms, Dry run: ${opts.dryRun}\n`);

  if (opts.dryRun) {
    for (const model of models) {
      for (const task of tasks) {
        for (let run = 1; run <= opts.runs; run++) {
          console.log(`  [dry-run] ${modelSlug(model)} | ${task.id} | run ${run} | "${task.prompt.slice(0, 60)}..."`);
        }
      }
    }
    return;
  }

  mkdirSync(RESULTS_DIR, { recursive: true });

  const summary = { total: 0, pass: 0, fail: 0, manual: 0, error: 0 };

  for (const model of models) {
    const slug = modelSlug(model);
    console.log(`\n--- Model: ${slug} (${model}) ---`);

    for (const task of tasks) {
      for (let run = 1; run <= opts.runs; run++) {
        console.log(`\n[${slug}] Task ${task.id} (Tier ${task.tier}) — run ${run}/${opts.runs}`);
        console.log(`  Prompt: "${task.prompt}"`);

        summary.total++;

        // 1. Reset fixtures + clear MCP cache
        if (!opts.skipReset) {
          try {
            resetFixtures();
            clearMcpSessionCache();
          } catch (err) {
            console.error(`  SKIP — fixture reset failed: ${err.message}`);
            summary.error++;
            continue;
          }
        }

        // 2. Record pre-run timestamp
        const preRunTimestamp = Date.now();

        // 3. Run OpenCode with this model
        console.log(`  Running opencode --model ${model}...`);
        let cliResult;
        try {
          cliResult = await runTask(task.prompt, systemPrompt, {
            timeoutMs: opts.timeoutMs,
            cwd,
            model,
          });
        } catch (err) {
          console.error(`  ERROR — CLI execution failed: ${err.message}`);
          summary.error++;
          continue;
        }

        console.log(`  Duration: ${(cliResult.durationMs / 1000).toFixed(1)}s, Exit: ${cliResult.exitCode}${cliResult.timedOut ? ' (TIMEOUT)' : ''}`);

        if (cliResult.stderr) {
          console.log(`  stderr: ${cliResult.stderr.slice(0, 300)}`);
        }

        // 4. Find gateway NDJSON log (Condition A only)
        let ndjsonPath = null;
        if (isConditionA) {
          ndjsonPath = findNdjsonLog(preRunTimestamp);
          if (ndjsonPath) {
            console.log(`  Gateway log: ${ndjsonPath.replace(PROJECT_ROOT + '/', '')}`);
          } else {
            console.log('  Gateway log: NOT FOUND');
          }
        }

        // 5. Extract gateway metrics
        const gatewayMetrics = extractGatewayMetrics(ndjsonPath);
        if (isConditionA) {
          console.log(`  Tool calls: ${gatewayMetrics.toolCallCount}, Errors: ${gatewayMetrics.errorCount}, Tools discovered: [${gatewayMetrics.discoveredTools.join(', ')}]`);
        }

        // 6. Evaluate goal
        const goalResult = await evaluateGoal(task, cliResult);
        const passLabel = goalResult.pass === true ? 'PASS' : goalResult.pass === false ? 'FAIL' : 'MANUAL';
        console.log(`  Result: ${passLabel} — ${goalResult.detail}`);

        if (goalResult.pass === true) summary.pass++;
        else if (goalResult.pass === false) summary.fail++;
        else summary.manual++;

        // 7. Response preview
        const preview = (cliResult.response || '').slice(0, 200).replace(/\n/g, ' ');
        console.log(`  Response: ${preview}${cliResult.response?.length > 200 ? '...' : ''}`);

        // 8. Save result
        const condSuffix = opts.condition.toLowerCase();
        const resultObj = {
          taskId: task.id,
          tier: task.tier,
          condition: opts.condition,
          modelSlug: slug,
          model,
          run,
          timestamp: new Date().toISOString(),
          prompt: task.prompt,
          response: cliResult.response,
          sessionId: cliResult.sessionId,
          usage: cliResult.usage,
          durationMs: cliResult.durationMs,
          exitCode: cliResult.exitCode,
          timedOut: cliResult.timedOut,
          isNegativeCapability: task.negativeCapability,
          goalResult,
          gatewayLog: ndjsonPath ? ndjsonPath.replace(PROJECT_ROOT + '/', '') : null,
          gatewayMetrics,
          stderr: cliResult.stderr || null,
        };

        saveResult(resultObj, condSuffix);
        console.log(`  Saved: var/eval/results/${task.id}_${condSuffix}_${slug.replace(/\//g, '_')}_${run}.json`);
      }
    }
  }

  // Summary
  console.log(`\n=== Summary ===`);
  console.log(`Total: ${summary.total}, Pass: ${summary.pass}, Fail: ${summary.fail}, Manual: ${summary.manual}, Error: ${summary.error}`);
  if (summary.total > 0) {
    const autoTotal = summary.pass + summary.fail;
    if (autoTotal > 0) {
      console.log(`Auto-evaluated pass rate: ${(summary.pass / autoTotal * 100).toFixed(1)}%`);
    }
  }
}

main().catch(err => {
  console.error('Fatal error:', err);
  process.exit(1);
});
