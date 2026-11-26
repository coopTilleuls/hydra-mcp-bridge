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
 *   node eval/run.mjs --condition C            # Hydra gateway, static two-tool mode
 *                                              # (gateway must run with HYDRA_LEGACY_DISCOVERY=static)
 */

import { execFileSync, spawnSync } from 'node:child_process';
import { readFileSync, writeFileSync, mkdirSync, readdirSync, statSync, rmSync, existsSync } from 'node:fs';
import { join, relative } from 'node:path';

import { TASKS } from './tasks.mjs';
import { evaluateGoal } from './grade.mjs';
import { Guard } from './guard.mjs';
import {
  PROJECT_ROOT, EVAL_LOG_DIR, RESULTS_DIR,
  MCP_CACHE_DIR, MODELS, GATEWAY_CLIENT_SLUG,
  TEST_HYDRA_DIR, TEST_OPENAPI_DIR, modelSlug,
  DEMO_COMPOSE_ARGS, DEMO_BASE_URL, GATEWAY_MCP_URL, SYSTEM_PROMPTS,
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
    skipExisting: false,
    condition: 'A', // A = Hydra gateway (dynamic tools), B = OpenAPI static, C = Hydra gateway (static tools)
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
      case '--skip-existing': opts.skipExisting = true; break;
      case '--condition': {
        const v = (val ?? args[++i])?.toUpperCase();
        if (v !== 'A' && v !== 'B' && v !== 'C') {
          console.error('--condition must be A (Hydra gateway), B (OpenAPI static) or C (Hydra gateway, static tools)');
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
      'compose', ...DEMO_COMPOSE_ARGS,
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

// ─── Result persistence ─────────────────────────────────────────────────────

function saveResult(result, condSuffix) {
  mkdirSync(RESULTS_DIR, { recursive: true });
  const slug = result.modelSlug.replace(/\//g, '_');
  const filename = `${result.taskId}_${condSuffix}_${slug}_${result.run}.json`;
  writeFileSync(join(RESULTS_DIR, filename), JSON.stringify(result, null, 2));
}

// ─── Gateway mode preflight ─────────────────────────────────────────────────

const MCP_PROTOCOL_VERSION = '2025-06-18';

function parseMcpBody(body) {
  const trimmed = body.trim();
  if (trimmed.startsWith('{')) return JSON.parse(trimmed);
  for (const line of trimmed.split(/\r?\n/)) {
    if (line.startsWith('data:')) {
      const payload = line.slice(5).trim();
      if (payload.startsWith('{')) return JSON.parse(payload);
    }
  }
  throw new Error(`unparseable MCP response: ${trimmed.slice(0, 200)}`);
}

function postMcp(payload, extraHeaders = []) {
  const proc = spawnSync('curl', [
    '-sk', '-i', '-X', 'POST', GATEWAY_MCP_URL,
    '-H', 'Content-Type: application/json',
    '-H', 'Accept: application/json, text/event-stream',
    ...extraHeaders.flatMap(h => ['-H', h]),
    '--data', JSON.stringify(payload),
  ], { timeout: 15_000, encoding: 'utf-8' });

  if (proc.error || proc.status !== 0 || !proc.stdout) {
    throw new Error(proc.error?.message || `curl exited with ${proc.status}`);
  }

  const parts = proc.stdout.split(/\r?\n\r?\n/);
  let idx = 0;
  while (idx < parts.length - 1 && /^HTTP\/[\d.]+ 1\d\d/.test(parts[idx])) idx++;
  const head = parts[idx];
  const body = parts.slice(idx + 1).join('\n\n');
  const sessionId = head.match(/^mcp-session-id:\s*(\S+)/im)?.[1] ?? null;
  return { sessionId, message: parseMcpBody(body) };
}

function preflightGatewayMode(condition) {
  const expectStatic = condition === 'C';
  let toolNames;
  try {
    const init = postMcp({
      jsonrpc: '2.0',
      id: 1,
      method: 'initialize',
      params: {
        protocolVersion: MCP_PROTOCOL_VERSION,
        capabilities: {},
        clientInfo: { name: 'eval-preflight', version: '1.0' },
      },
    });
    if (!init.sessionId) throw new Error('initialize response carried no Mcp-Session-Id header');

    const list = postMcp(
      { jsonrpc: '2.0', id: 2, method: 'tools/list' },
      [`Mcp-Session-Id: ${init.sessionId}`, `MCP-Protocol-Version: ${MCP_PROTOCOL_VERSION}`],
    );
    toolNames = (list.message?.result?.tools ?? []).map(t => t.name);
  } catch (err) {
    console.error(`Gateway preflight failed: cannot query ${GATEWAY_MCP_URL} (${err.message})`);
    console.error('Start the gateway from the repo root with docker compose up -d.');
    process.exit(1);
  }

  const isStatic = toolNames.includes('invoke_api_operation');
  if (isStatic !== expectStatic) {
    const running = isStatic ? 'static' : 'dynamic';
    const wanted = expectStatic ? 'static' : 'dynamic';
    console.error(`Gateway mode mismatch: condition ${condition} needs ${wanted} discovery but the running gateway is ${running} (tools: ${toolNames.join(', ') || 'none'}).`);
    console.error(`Fix, from the repo root: HYDRA_LEGACY_DISCOVERY=${wanted} docker compose up -d`);
    process.exit(1);
  }
  console.log(`Gateway mode OK: ${expectStatic ? 'static' : 'dynamic'} (tools: ${toolNames.join(', ')})\n`);
}

// ─── Main ───────────────────────────────────────────────────────────────────

async function main() {
  const opts = parseArgs();

  const usesGateway = opts.condition !== 'B';
  const conditionLabel = {
    A: 'A (Hydra gateway)',
    B: 'B (OpenAPI static)',
    C: 'C (Hydra gateway, static tools)',
  }[opts.condition];
  const cwd = usesGateway ? TEST_HYDRA_DIR : TEST_OPENAPI_DIR;
  const systemPromptFile = SYSTEM_PROMPTS[opts.condition] ?? null;
  const systemPrompt = systemPromptFile ? readFileSync(systemPromptFile, 'utf-8') : '';

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

  if (usesGateway) preflightGatewayMode(opts.condition);

  mkdirSync(RESULTS_DIR, { recursive: true });

  const summary = { total: 0, pass: 0, fail: 0, manual: 0, error: 0 };
  const guard = new Guard();

  for (const model of models) {
    const slug = modelSlug(model);
    console.log(`\n--- Model: ${slug} (${model}) ---`);

    for (const task of tasks) {
      for (let run = 1; run <= opts.runs; run++) {
        const resultFile = join(RESULTS_DIR, `${task.id}_${opts.condition.toLowerCase()}_${slug.replace(/\//g, '_')}_${run}.json`);
        if (opts.skipExisting && existsSync(resultFile)) {
          continue;
        }

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

        // 4. Find gateway NDJSON log (gateway conditions A and C)
        let ndjsonPath = null;
        if (usesGateway) {
          ndjsonPath = findNdjsonLog(preRunTimestamp);
          if (ndjsonPath) {
            console.log(`  Gateway log: ${ndjsonPath.replace(PROJECT_ROOT + '/', '')}`);
          } else {
            console.log('  Gateway log: NOT FOUND');
          }
        }

        // 5. Extract gateway metrics
        const gatewayMetrics = extractGatewayMetrics(ndjsonPath);
        if (usesGateway) {
          console.log(`  Tool calls: ${gatewayMetrics.toolCallCount}, Errors: ${gatewayMetrics.errorCount}, Tools discovered: [${gatewayMetrics.discoveredTools.join(', ')}]`);
        }

        // 6. Evaluate goal
        const goalResult = evaluateGoal(task.goal, cliResult.response);
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
          systemPromptFile: systemPromptFile && relative(PROJECT_ROOT, systemPromptFile),
          response: cliResult.response,
          sessionId: cliResult.sessionId,
          usage: cliResult.usage,
          durationMs: cliResult.durationMs,
          exitCode: cliResult.exitCode,
          timedOut: cliResult.timedOut,
          isNegativeCapability: task.negativeCapability,
          goalResult,
          gatewayLog: ndjsonPath ? ndjsonPath.replace(PROJECT_ROOT + '/', '') : null,
          clientToolCalls: cliResult.clientToolCalls,
          extraResources: Number(process.env.EVAL_EXTRA_RESOURCES ?? 0),
          gatewayMetrics,
          stderr: cliResult.stderr || null,
        };

        saveResult(resultObj, condSuffix);
        console.log(`  Saved: var/eval/results/${task.id}_${condSuffix}_${slug.replace(/\//g, '_')}_${run}.json`);

        const abortReasons = guard.check(resultObj);
        if (abortReasons.length > 0) {
          console.log(`\nABORT: ${abortReasons.join('; ')}`);
          process.exit(3);
        }
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
