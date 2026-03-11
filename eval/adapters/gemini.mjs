import { spawn } from 'node:child_process';

export const name = 'gemini';
export const model = 'gemini-3-flash-preview';
export const clientSlug = 'gemini-cli-mcp-client';

/**
 * Run a single task prompt via Gemini CLI in headless mode.
 *
 * JSON output is a single object: { session_id, response, stats }
 * stats.models.{modelName}.tokens has per-model token counts.
 * stats.tools has tool call counts.
 */
export function runTask(prompt, systemPrompt, { timeoutMs = 120_000, cwd } = {}) {
  return new Promise((resolve) => {
    const start = Date.now();

    const fullPrompt = systemPrompt
      ? `[System Instructions]\n${systemPrompt}\n[End System Instructions]\n\n${prompt}`
      : prompt;

    const args = [
      '-p', fullPrompt,
      '--output-format', 'json',
      '--yolo',
    ];

    const env = { ...process.env, NODE_TLS_REJECT_UNAUTHORIZED: '0' };
    const child = spawn('gemini', args, { env, cwd, stdio: ['ignore', 'pipe', 'pipe'] });

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

      // Gemini stderr may contain log lines before the JSON on stdout.
      // stdout may have prefix noise (e.g. "Tools updated for server: book-api")
      // before the JSON object. Extract the JSON portion.
      let jsonStr = stdout;
      const jsonStart = stdout.indexOf('{');
      if (jsonStart > 0) {
        jsonStr = stdout.slice(jsonStart);
      }

      try {
        const parsed = JSON.parse(jsonStr);
        response = parsed.response || '';
        sessionId = parsed.session_id || null;

        // Aggregate tokens across all models
        if (parsed.stats?.models) {
          let totalInput = 0, totalOutput = 0, totalCached = 0, totalThoughts = 0;
          for (const m of Object.values(parsed.stats.models)) {
            const t = m.tokens || {};
            totalInput += t.input || 0;
            totalOutput += t.candidates || 0;
            totalCached += t.cached || 0;
            totalThoughts += t.thoughts || 0;
          }
          usage = {
            inputTokens: totalInput,
            outputTokens: totalOutput + totalThoughts,
            totalTokens: totalInput + totalOutput + totalThoughts,
            cacheReadTokens: totalCached,
            cacheCreationTokens: 0,
          };
        }
      } catch {
        response = stdout.trim();
      }

      // Exit code 53 = turn limit reached
      resolve({
        response,
        sessionId,
        usage,
        durationMs,
        exitCode: code ?? 1,
        timedOut: timedOut || code === 53,
        raw: stdout,
        stderr: stderr.trim(),
      });
    });
  });
}
