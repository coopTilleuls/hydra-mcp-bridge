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
export const RESULTS_DIR = process.env.EVAL_RESULTS_DIR ?? resolve(PROJECT_ROOT, 'var/eval/results');
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

// All models run through OpenCode CLI via OpenRouter; select one with --model=openrouter/openai/gpt-6-luna.
// EVAL_MODELS=a,b,... overrides the list. Sampling and provider pinning are per model in
// eval/opencode/*/opencode.json (provider.openrouter.models, keyed without the `openrouter/` prefix).
export const MODELS = env.EVAL_MODELS ? env.EVAL_MODELS.split(',') : [
  'openrouter/openai/gpt-6-luna',
  'openrouter/google/gemini-3.5-flash-lite',
  'openrouter/deepseek/deepseek-v4.1-flash',
];

// Derive a short slug from a full model ID for filenames/display
export function modelSlug(id) {
  return id.replace(/^github-copilot\//, '');
}

// Gateway NDJSON log prefix (OpenCode identifies as "opencode" to the gateway)
export const GATEWAY_CLIENT_SLUG = 'opencode';
