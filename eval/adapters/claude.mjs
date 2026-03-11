import { spawn } from 'node:child_process';

export const name = 'claude';
export const model = 'sonnet';
export const clientSlug = 'claude-code';

/**
 * Run a single task prompt via Claude CLI in headless mode.
 * @param {string} prompt
 * @param {string} systemPrompt
 * @param {object} opts
 * @returns {Promise<{response: string, sessionId: string|null, usage: object|null, durationMs: number, exitCode: number, timedOut: boolean, raw: string}>}
 */
export function runTask(prompt, systemPrompt, { timeoutMs = 120_000, cwd } = {}) {
  return new Promise((resolve) => {
    const start = Date.now();

    const fullPrompt = systemPrompt ? `${systemPrompt}\n\n${prompt}` : prompt;

    const args = [
      '-p', fullPrompt,
      '--output-format', 'json',
      '--model', 'sonnet',
      '--allowedTools', 'mcp__book-api__*',
      '--disallowedTools', 'Bash,Read,Write,Edit,Glob,Grep,Agent,WebFetch,WebSearch,TodoRead,TodoWrite',
      '--permission-mode', 'bypassPermissions',
      '--max-turns', '25',
    ];

    const env = { ...process.env, CLAUDECODE: '', NODE_TLS_REJECT_UNAUTHORIZED: '0' };
    const child = spawn('claude', args, { env, cwd, stdio: ['ignore', 'pipe', 'pipe'] });

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

      try {
        const parsed = JSON.parse(stdout);
        response = typeof parsed.result === 'string' ? parsed.result : JSON.stringify(parsed.result);
        sessionId = parsed.session_id || null;
        if (parsed.usage) {
          usage = {
            inputTokens: parsed.usage.input_tokens || 0,
            outputTokens: parsed.usage.output_tokens || 0,
            totalTokens: (parsed.usage.input_tokens || 0) + (parsed.usage.output_tokens || 0),
            cacheReadTokens: parsed.usage.cache_read_input_tokens || 0,
            cacheCreationTokens: parsed.usage.cache_creation_input_tokens || 0,
          };
        }
      } catch {
        // Non-JSON output — use raw stdout
        response = stdout.trim();
        if (stderr.trim()) {
          response += `\n[stderr]: ${stderr.trim()}`;
        }
      }

      resolve({
        response,
        sessionId,
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
