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

// Client slug mapping (adapter name → NDJSON file prefix from McpEvalLogger)
// Note: Condition A logs go through the gateway (McpEvalLogger), Condition B
// uses a stdio MCP server so there are no gateway logs — only CLI-reported usage.
export const CLIENT_SLUGS = {
  claude: 'claude-code',
  gemini: 'gemini-cli-mcp-client',
  opencode: 'github-copilot-developer',
};



