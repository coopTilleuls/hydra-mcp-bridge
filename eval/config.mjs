import { resolve, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = dirname(fileURLToPath(import.meta.url));

export const PROJECT_ROOT = resolve(__dirname, '..');
export const DEMO_API_DIR = '/home/soyuka/forks/demo';
export const EVAL_LOG_DIR = resolve(PROJECT_ROOT, 'var/eval');
export const RESULTS_DIR = resolve(PROJECT_ROOT, 'var/eval/results');
export const MCP_CACHE_DIR = resolve(PROJECT_ROOT, 'var/cache/dev/mcp');
export const SYSTEM_PROMPT_PATH = resolve(PROJECT_ROOT, 'SYSTEM_PROMPT.md');

// Condition A: Hydra gateway (lazy discovery, 1 tool at init)
export const TEST_HYDRA_DIR = '/home/soyuka/projects/test-hydra-mcp';
// Condition B: OpenAPI static MCP (all tools pre-registered at init)
export const TEST_OPENAPI_DIR = '/home/soyuka/projects/test-openapi-mcp';

// All models run through OpenCode CLI via GitHub Copilot
// Use full model IDs; select with --model=github-copilot/gpt-5-mini
export const MODELS = [
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
