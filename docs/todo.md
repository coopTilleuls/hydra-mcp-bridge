# Eval and paper todo

Open work after the groups scaling (2026-11-27). Results live on the eval server under `~/hydra-eval/gateway/var/eval`; `archive/final-ab` and `var/eval/results` hold the same full grid.

## Next

1. Done 2026-11-28: G/GS full grid, judge on all conditions, scaling on the 3 models, measured M6 (research log). The luna G plateau was a stale `cache.app` (fixed in the server scripts, luna G/GS scaling and the probes rerun 2026-11-28). Open: let `find_groups` list the catalog on an empty query (deepseek fishes with "a", "*"), then rerun GS everywhere.
2. Track the server setup in the repo: `oc-isolated.sh` (parametrized by `EVAL_OC_IMAGE`), `runner/` and `runner-groups/` Dockerfiles, `scaling-*.sh`, `parity-then-g.sh`, `env.sh`. Reproducing a run currently depends on files only present on the server.
3. Report the inline vs search numbers on the groups SEP (modelcontextprotocol/progressive-disclosure-wg#19): the draft does not say how a client presents groups, and that choice decides whether groups scale.
4. Judge agreement sample (judge vs keyword vs manual on a subset).
5. Reasoning effort `high` arm, to separate the interface effect from the reasoning budget.

## Analysis

- M3 client-side tool errors, checked 2026-11-27. deepseek A 13.9% vs B 4.5% is mostly T3.4: anonymous `POST /bookmarks` fails by design (500, `user_id` null) and deepseek A retried it 24 times against 3 under B. Without T3.4: deepseek A 6.4% vs B 3.0%, gemini 8.7% vs 7.8%, luna 1.7% vs 1.9%. gemini A's largest cluster (16 calls, T4.3) passes `/books/x/reviews` to `get_review_collection` instead of the book IRI; the gateway error ("has no \"reviews\" link") is accurate but does not say what to pass. Report M3 with and without T3.4. Improving that message is gateway work outside the paper: do it after the paper's baseline is tagged, no eval rerun needed.
- Condition C: at most a cheap luna scaling check, only as the fallback for clients that negotiate neither dynamic tools nor groups.

## Infrastructure

- Reset the OpenCode test directory per run (read-only mount or fresh copy).
- Run `tests/e2e.sh` against the link-bound operations.
- Follow upstream api-platform/core#8876 and drop the fork once merged.
- Commit the demo changes in `~/forks/demo` (eval scaling knob, AP 5.1 upgrade).
