# Eval and paper todo

Open work after the groups scaling (2026-11-27). Results live on the eval server under `~/hydra-eval/gateway/var/eval`; `archive/final-ab` and `var/eval/results` hold the same full grid.

## Next

1. Full grid for G and GS on the 3 models (luna, gemini-3.5-flash-lite, deepseek-v4.1-flash), 19 tasks, k=3. A and B already exist on the new stack. Estimate the cost before launching; the open question is whether gemini and deepseek handle `find_groups` and a free-name `load_group` as well as luna.
2. Track the server setup in the repo: `oc-isolated.sh` (parametrized by `EVAL_OC_IMAGE`), `runner/` and `runner-groups/` Dockerfiles, `scaling-*.sh`, `parity-then-g.sh`, `env.sh`. Reproducing a run currently depends on files only present on the server.
3. Report the inline vs search numbers on the groups SEP (modelcontextprotocol/progressive-disclosure-wg#19): the draft does not say how a client presents groups, and that choice decides whether groups scale.
4. Judge agreement sample (judge vs keyword vs manual on a subset).
5. Reasoning effort `high` arm, to separate the interface effect from the reasoning budget.

## Analysis

- M6 (tool schema overhead) is an estimate: 19 OpenAPI tools at 1685 tokens and 80 tokens per Hydra tool, hardcoded in `eval/analyze.mjs`. It is wrong for any scaled run. Measure the `tools/list` size per condition and API size, or drop M6 from the paper.
- M3 now counts client-side tool errors: A errors more than B on deepseek (13.9% vs 4.5%) and gemini is even (8.6% vs 9.3%). Look at what those errors are before writing about them.
- Condition C: at most a cheap luna scaling check, only as the fallback for clients that negotiate neither dynamic tools nor groups.

## Infrastructure

- Reset the OpenCode test directory per run (read-only mount or fresh copy).
- Run `tests/e2e.sh` against the link-bound operations.
- Follow upstream api-platform/core#8876 and drop the fork once merged.
- Commit the demo changes in `~/forks/demo` (eval scaling knob, AP 5.1 upgrade).
