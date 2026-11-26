import { resolve, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = dirname(fileURLToPath(import.meta.url));

export const PROJECT_ROOT = resolve(__dirname, '..');
const env = process.env;

export const DEMO_API_DIR = env.EVAL_DEMO_API_DIR || '/home/soyuka/forks/demo';
// Extra `docker compose` args for the demo stack (project name, override files) on a shared host.
export const DEMO_COMPOSE_ARGS = env.EVAL_DEMO_COMPOSE_ARGS ? env.EVAL_DEMO_COMPOSE_ARGS.split(' ') : ['-f', `${DEMO_API_DIR}/compose.yaml`];
export const DEMO_BASE_URL = env.EVAL_DEMO_BASE_URL || 'https://localhost';
export const GATEWAY_MCP_URL = env.EVAL_GATEWAY_MCP_URL || 'https://localhost:8000/mcp';
export const EVAL_LOG_DIR = resolve(PROJECT_ROOT, 'var/eval');
export const RESULTS_DIR = resolve(PROJECT_ROOT, 'var/eval/results');
export const MCP_CACHE_DIR = resolve(PROJECT_ROOT, 'var/cache/dev/mcp');

// Prepended to the task prompt. condition-a.md is SYSTEM_PROMPT.md as the March 2026 runs used it
// (removed from run.mjs in 98eda22, after those runs), kept verbatim, old tool name included.
export const SYSTEM_PROMPTS = {
  A: resolve(PROJECT_ROOT, 'eval/prompts/condition-a.md'),
};

// Condition A: Hydra gateway (lazy discovery, 1 tool at init)
export const TEST_HYDRA_DIR = env.EVAL_TEST_HYDRA_DIR || '/home/soyuka/projects/test-hydra-mcp';
// Condition B: OpenAPI static MCP (all tools pre-registered at init)
export const TEST_OPENAPI_DIR = env.EVAL_TEST_OPENAPI_DIR || '/home/soyuka/projects/test-openapi-mcp';

// All models run through OpenCode CLI via GitHub Copilot
// Use full model IDs; select with --model=github-copilot/gpt-5-mini
// EVAL_MODELS=openrouter/openai/gpt-5-mini,... overrides the list (e.g. another provider route).
export const MODELS = env.EVAL_MODELS ? env.EVAL_MODELS.split(',') : [
  // 'github-copilot/claude-sonnet-4.6',
  'github-copilot/gemini-3-flash-preview',
  // 'github-copilot/gpt-4.1',
  // 'mistral/mistral-medium-latest',
  // 'mistral/devstral-medium-latest',
  'github-copilot/gpt-5-mini',
];

// Derive a short slug from a full model ID for filenames/display
export function modelSlug(id) {
  return id.replace(/^github-copilot\//, '');
}

// Gateway NDJSON log prefix (OpenCode identifies as "opencode" to the gateway)
export const GATEWAY_CLIENT_SLUG = 'opencode';
