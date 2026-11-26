/**
 * Stops a batch as soon as a run shows a protocol or infrastructure problem: every further run would be paid
 * for and thrown away. Model mistakes (wrong answer, refused task, expected API errors) never trip it.
 */

const BUILT_IN_TOOLS = new Set([
  'apply_patch', 'bash', 'edit', 'execute', 'glob', 'grep', 'list_mcp_resource_templates', 'list_mcp_resources',
  'lsp', 'plan_exit', 'question', 'read', 'read_mcp_resource', 'skill', 'task', 'todowrite', 'webfetch',
  'websearch', 'write',
]);

const INFRASTRUCTURE_ERROR = /could not resolve host|failed to connect|timed out after|connection (reset|refused)|ssl|curl error|maximum execution time|bad gateway|service unavailable|error fetching \S+: (?!an error occurred|refusing to request)/i;

const MAX_TIMEOUT_STREAK = 3;

// A model that keeps calling tools until the timeout is a model failure; few calls in the whole budget means a stall.
const STALLED_MAX_TOOL_CALLS = 10;

export class Guard {
  #timeoutStreak = 0;

  /**
   * @returns {string[]} reasons to stop the batch, empty when the run is fine
   */
  check(result) {
    const reasons = [];

    for (const call of result.clientToolCalls ?? []) {
      if (BUILT_IN_TOOLS.has(call.tool)) {
        reasons.push(`built-in tool ${call.tool} was offered and used`);
      }
      if ('error' === call.status && INFRASTRUCTURE_ERROR.test(call.error ?? '')) {
        reasons.push(`infrastructure error on ${call.tool}: ${call.error.slice(0, 200)}`);
      }
    }

    const stalled = result.timedOut && (result.clientToolCalls ?? []).length < STALLED_MAX_TOOL_CALLS;
    this.#timeoutStreak = stalled ? this.#timeoutStreak + 1 : 0;
    if (this.#timeoutStreak >= MAX_TIMEOUT_STREAK) {
      reasons.push(`${MAX_TIMEOUT_STREAK} stalled timeouts in a row`);
    }

    if (!result.timedOut && 0 !== result.exitCode && !result.response) {
      reasons.push(`client exited with ${result.exitCode} and no response`);
    }

    return reasons;
  }
}
