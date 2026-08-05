# Architecture record migration map

This page maps every retired architecture identifier to its current location.

Identifiers were migrated from a globally sequential counter (`ADR-NNNN`,
`SDD-NNNN`) to tracking-number-based identifiers. The decision, the alternatives
considered and the evidence are recorded upstream in
[`exelearning/exelearning` ADR-2232-01](https://github.com/exelearning/exelearning/blob/main/doc/architecture/adr/ADR-2232-01-use-tracking-issue-based-architecture-identifiers.md),
under [`exelearning/exelearning#2232`](https://github.com/exelearning/exelearning/issues/2232).
This repository adopts that model rather than re-deciding it, so it carries no
local ADR for the convention itself — the policy lives in
[`adr/README.md`](adr/README.md) and the rationale lives upstream.

**Retired identifiers must not be used in new content.** `make architecture-check`
fails when one appears outside this page and the `legacy_id` frontmatter field.
Use the tables below to find the current identifier.

## Tracking numbers in this repository are pull requests

GitHub Issues are disabled on `exelearning/omeka-s-exelearning`; issue tracking
for the module is centralized in
[`exelearning/exelearning`](https://github.com/exelearning/exelearning/issues).
Every tracking number here is therefore a **pull-request number** drawn from this
repository's own sequence. Upstream issue numbers come from a different sequence
and would collide with local pull-request numbers, so they are never used as
identifiers — they are recorded under `related.issues` instead.

## Architecture Decision Records

| Old identifier | New identifier | Tracking PR | Current path |
|---|---|---|---|
| `ADR-0001` | `ADR-28-01` | [#28](https://github.com/exelearning/omeka-s-exelearning/pull/28) | [`adr/ADR-28-01-bundle-editor-exclusively-in-release-packages.md`](adr/ADR-28-01-bundle-editor-exclusively-in-release-packages.md) |
| `ADR-0002` | `ADR-32-01` | [#32](https://github.com/exelearning/omeka-s-exelearning/pull/32) | [`adr/ADR-32-01-use-a-single-blocking-whole-module-coverage-gate.md`](adr/ADR-32-01-use-a-single-blocking-whole-module-coverage-gate.md) |

### How the tracking numbers were established

Both records reached `main` in a single merge commit each, and neither pull
request closed an issue (`closingIssuesReferences` empty for both — issues are
disabled).

| Record | Merge commit | Pull request | Merged | Record `date` |
|---|---|---|---|---|
| `ADR-0001` | `b9387fa` | [#28](https://github.com/exelearning/omeka-s-exelearning/pull/28) *Bundle the embedded editor exclusively in release packages* | 2026-07-24 | 2026-07-24 |
| `ADR-0002` | `864aab7` | [#32](https://github.com/exelearning/omeka-s-exelearning/pull/32) *Fix the coverage gate, measure Module.php, publish to Codecov, and add Omeka S skills* | 2026-08-04 | 2026-08-04 |

### What changed inside each record

Both files kept their content and every metadata field, including
`ai_assistance`. Renames were made with `git mv`, so `git log --follow` still
resolves the full history. The edits were:

- `id` → the tracking-number form; `tracking_issue` and `legacy_id` added.
- The H1 rewritten to `# <id>: <title>`.
- The duplicated `## Status` section removed — status now lives in the
  frontmatter only.
- `related.sdds` renamed to `related.changes` (the field was empty in both).
- `related.prs` gained this repository's own pull request. `ADR-28-01` keeps its
  two cross-repository references to `moodle-mod_exelearning#106` and
  `wp-exelearning#72`.
- `ADR-32-01`'s cross-reference to `ADR-0001` rewritten to `ADR-28-01`, in the
  frontmatter, in *Risks* and in *References*.
- The trailing "ADR index" reference replaced by `make architecture-records`,
  since the index is no longer a file.

`ADR-0002`'s slug named the topic (`verification-contract-coverage-gate-and-codecov`)
rather than the decision. Its *Decision* section decides that `make test-coverage`
is the single blocking gate, measured over `src/` **plus** the root `Module.php`,
with Codecov informational only — hence
`use-a-single-blocking-whole-module-coverage-gate`. `ADR-0001`'s slug already
named its decision and is unchanged.

The local sequence is `-01` for both, because each tracking number owns exactly
one decision.

## Software Design Documents

None. `docs/architecture/sdd/` only ever contained scaffolding — a README, a
template and an empty index. No `SDD-NNNN` document was written, so no design
record needed migrating.

## Directories, templates and indexes

| Old path | Current path | Notes |
|---|---|---|
| `adr/ADR-0001-bundle-editor-exclusively-in-release-packages.md` | [`adr/ADR-28-01-bundle-editor-exclusively-in-release-packages.md`](adr/ADR-28-01-bundle-editor-exclusively-in-release-packages.md) | `git mv` |
| `adr/ADR-0002-verification-contract-coverage-gate-and-codecov.md` | [`adr/ADR-32-01-use-a-single-blocking-whole-module-coverage-gate.md`](adr/ADR-32-01-use-a-single-blocking-whole-module-coverage-gate.md) | `git mv`; slug rewritten to name the decision |
| `adr/template.md` | [`adr/template.md`](adr/template.md) | Rewritten: tracking-number grammar, `tracking_issue`, no `## Status` section |
| `adr/records.md` | *removed* | The index is no longer committed. `make architecture-records` prints it from frontmatter. |
| `sdd/` | [`changes/`](changes/README.md) | `git mv` of the whole directory |
| `sdd/README.md` | [`changes/README.md`](changes/README.md) | Rewritten for the change-directory model |
| `sdd/template.md` | [`changes/template.md`](changes/template.md) | Consolidated: one template covering `proposal.md`, `spec.md`, `design.md`, `research.md` and `tasks.md` |
| `sdd/records.md` | *removed* | Same as `adr/records.md`. |

The `docs/architecture/sdd/` directory no longer exists.

## Identifiers reserved on open branches

None. At migration time the only open pull request was
[#21](https://github.com/exelearning/omeka-s-exelearning/pull/21), which touches
no file under `docs/architecture/`, and neither remote feature branch adds a
record. Both retired identifiers therefore resolve unambiguously, and no
reconciliation with in-flight work is needed.

## Enforcement

`make architecture-check` (also run by `make lint` and by the *Architecture
records* CI workflow) fails on:

- a filename using the retired `ADR-NNNN` / `SDD-NNNN` grammar;
- a retired identifier referenced anywhere in a tracked file, outside this page
  and a record's own `legacy_id`;
- a committed `records.md` index;
- frontmatter that disagrees with the filename.
