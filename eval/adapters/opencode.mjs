import { spawn } from 'node:child_process';

const DEFAULT_MODEL = 'github-copilot/gpt-5.3-codex';
const MCP_SERVER_PREFIX = 'book-api_';

export function parseEvents(stdout) {
  const events = [];
  for (const line of String(stdout ?? '').trim().split('\n').filter(Boolean)) {
    try { events.push(JSON.parse(line)); } catch { /* skip non-JSON */ }
  }
  return events;
}

export function extractClientToolCalls(events) {
  const calls = [];
  for (const ev of events) {
    if (ev.type !== 'tool_use' || !ev.part?.tool) continue;
    const { tool, state = {} } = ev.part;
    const call = {
      tool: tool.startsWith(MCP_SERVER_PREFIX) ? tool.slice(MCP_SERVER_PREFIX.length) : tool,
      input: state.input ?? {},
      status: state.status ?? null,
    };
    if (state.status === 'error') call.error = state.error ?? null;
    calls.push(call);
  }
  return calls;
}

/**
 * Run a single task prompt via OpenCode CLI in headless mode.
 * OpenCode uses the `run` subcommand for non-interactive mode.
 *
 * @param {string} prompt
 * @param {string} systemPrompt
 * @param {object} opts
 * @param {number} opts.timeoutMs
 * @param {string} opts.cwd
 * @param {string} opts.model - Full model ID (e.g. 'github-copilot/claude-sonnet-4.6')
 *
 * JSON format outputs JSONL events with types:
 *   step_start, tool_use, text, step_finish, error
 */
export function runTask(prompt, systemPrompt, { timeoutMs = 120_000, cwd, model } = {}) {
  return new Promise((resolve) => {
    const start = Date.now();

    const fullPrompt = systemPrompt
      ? `[System Instructions]\n${systemPrompt}\n[End System Instructions]\n\n${prompt}`
      : prompt;

    const args = [
      'run', fullPrompt,
      '--model', model || DEFAULT_MODEL,
      '--format', 'json',
    ];

    const env = { ...process.env, NODE_TLS_REJECT_UNAUTHORIZED: '0' };
    const opencodeBin = process.env.OPENCODE_BIN || 'opencode';
    const child = spawn(opencodeBin, args, {
      env,
      cwd,
      stdio: ['ignore', 'pipe', 'pipe'],
    });

    let stdout = '';
    let stderr = '';
    let timedOut = false;

    child.stdout.on('data', (d) => { stdout += d; });
    child.stderr.on('data', (d) => { stderr += d; });

    const timer = setTimeout(() => {
      timedOut = true;
      child.kill('SIGTERM');
      setTimeout(() => child.kill('SIGKILL'), 5000);
    }, timeoutMs);

    child.on('close', (code) => {
      clearTimeout(timer);
      const durationMs = Date.now() - start;

      let response = '';
      let sessionId = null;
      let usage = null;

      // Parse JSONL events
      const events = parseEvents(stdout);

      // Extract session ID from first event
      for (const ev of events) {
        if (ev.sessionID) { sessionId = ev.sessionID; break; }
      }

      // Extract text responses — type:"text" events contain the assistant's message
      const textParts = events
        .filter(ev => ev.type === 'text' && ev.part?.text)
        .map(ev => ev.part.text);
      response = textParts.join('\n');

      // Sum token usage from step_finish events
      let totalInput = 0, totalOutput = 0, totalReasoning = 0, totalCacheRead = 0;
      for (const ev of events) {
        if (ev.type === 'step_finish' && ev.part?.tokens) {
          const t = ev.part.tokens;
          totalInput += t.input || 0;
          totalOutput += t.output || 0;
          totalReasoning += t.reasoning || 0;
          totalCacheRead += t.cache?.read || 0;
        }
      }
      if (totalInput > 0 || totalOutput > 0) {
        usage = {
          inputTokens: totalInput,
          // Includes reasoning, as in results written before reasoningTokens existed.
          outputTokens: totalOutput + totalReasoning,
          reasoningTokens: totalReasoning,
          totalTokens: totalInput + totalOutput + totalReasoning,
          cacheReadTokens: totalCacheRead,
          cacheCreationTokens: 0,
        };
      }

      // Check for error events
      const errorEvent = events.find(ev => ev.type === 'error');
      if (errorEvent && !response) {
        response = errorEvent.error?.data?.message || errorEvent.error?.name || JSON.stringify(errorEvent);
      }

      if (!response && stdout.trim()) {
        response = stdout.trim();
      }

      resolve({
        response,
        sessionId,
        clientToolCalls: extractClientToolCalls(events),
        usage,
        durationMs,
        exitCode: code ?? 1,
        timedOut,
        raw: stdout,
        stderr: stderr.trim(),
      });
    });
  });
}
