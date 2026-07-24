# Architecture Decision Records

## Purpose

An **Architecture Decision Record (ADR)** captures a single durable architectural
decision together with the reasoning behind it: the context, the problem, the
options that were considered, the evidence that informed the choice, the decision
itself, and the consequences that follow.

ADRs exist so that `omeka-s-exelearning` contributors — human and AI — can answer
*"why is it built this way?"* long after the fact, without archaeology through
pull request threads or chat logs. A decision recorded only inside a PR
description is easy to lose; an ADR is a first-class, long-lived document.

Guiding principles:

- **Evidence before preference.** Prefer a verifiable source over an assertion.
- **No technical claim without a source.** Cite a repository path + commit,
  official Omeka S / Laminas documentation, a benchmark, a reproducible
  experiment, an issue, a PR, an SDD, or a previous ADR.
- **Separate facts, interpretation and decision.** Say what is observed, what it
  means, and what was decided — in that order.
- **Stable, monotonic IDs.** IDs are never reused.
- **Append-only.** Accepted decisions are not rewritten; they are superseded.

## ADRs vs SDDs

ADRs and [Software Design Documents (SDDs)](../sdd/README.md) are complementary,
not competing.

| Artifact | Answers | Lifetime |
|----------|---------|----------|
| **SDD** | *What* will be built and *how* a significant change will be implemented | May become historical once implemented |
| **ADR** | *Which* durable decision was made and *why* | Long-lived, append-only |
| **PR** | The concrete code/doc changes under review | Historical review record |
| **Issue** | The problem, proposal or discussion being coordinated | Historical coordination record |

A large change usually starts with an SDD that describes the design and the
implementation plan. Inside that SDD, the decisions that will outlive the change
itself — a storage layout, a proxy contract, a security boundary — should be
extracted into ADRs or linked to existing ones. The SDD records the design; the
ADR records the decision and its rationale. Do **not** copy the whole SDD into an
ADR.

## An ADR is not…

- **…an implementation plan.** That is what an [SDD](../sdd/README.md) is for.
- **…an issue summary.** The issue already coordinates the discussion; the ADR
  records the durable conclusion.
- **…a changelog entry.** Use the release notes for user-facing changes.

## When an ADR is required

Create or update an ADR when a change **introduces or modifies a durable
architectural decision** — one that future contributors should not have to
re-litigate. In this module that typically includes decisions affecting:

- the storage model for ELPX packages and extracted content (the
  `/files/original/` and `/files/exelearning/{sha1}/` layout, cleanup);
- ELPX validation and extraction (ZIP handling, path-traversal protection,
  size limits);
- the content proxy (`ContentController`) and how extracted content is served;
- the CSP headers, iframe sandboxing and other security boundaries;
- CSRF protection and ACL/permission checks on the admin/API surface;
- Omeka S integration boundaries: media rendering, lifecycle event hooks
  (`api.hydrate.post`, `api.create.post`, `api.delete.pre`, `view.show.after`),
  and module configuration;
- the embedded editor bundling/build flow and where its assets
  live;
- backward-compatibility guarantees for stored media, routes, or the module
  configuration;
- Omeka S version or PHP version compatibility choices;
- any AI-assisted generation workflow or policy.

If in doubt, prefer writing a short ADR over losing the reasoning.

## When an ADR is recommended

An ADR is worth writing, even when not strictly required, when a change would
otherwise leave reviewers guessing *why* a non-obvious approach was chosen — for
example picking one Omeka S / Laminas API over another, or accepting a trade-off
that a later contributor might be tempted to "fix".

## When an ADR is not needed

Do **not** write an ADR for:

- bug fixes that restore intended behavior;
- routine refactors with no externally observable decision;
- dependency bumps, lint/format changes, copy edits;
- translation-only or test-only changes;
- purely local implementation details with no cross-cutting impact.

If a change is significant enough to need a design but does not yet lock a
durable decision, start with an [SDD](../sdd/README.md) instead.

## Location and naming

- ADRs live in `docs/architecture/adr/`.
- Filenames follow: `ADR-NNNN-short-kebab-case-title.md` — for example
  `ADR-0001-serve-extracted-content-through-proxy.md`.
- IDs are zero-padded, monotonic and never reused. The next ID is
  `max(existing) + 1`, starting at `ADR-0001`.
- [`template.md`](template.md) is the canonical template. Copy it to a new file
  and assign the next ID.
- [`records.md`](records.md) lists every ADR. The index is maintained by hand.

## Status values

| Status | Meaning |
|--------|---------|
| `Proposed` | Under discussion; not yet agreed. |
| `Accepted` | Agreed and in force. |
| `Rejected` | Considered and declined. Kept for the record. |
| `Superseded` | Replaced by a later ADR (see `superseded_by`). |

A decision that is still being debated stays `Proposed`. It becomes `Accepted`
only after reviewer approval on the PR.

## Evidence and traceability

Every technical claim in an ADR should cite a verifiable source. Acceptable
evidence includes:

- a repository path plus commit (e.g. `src/Controller/ContentController.php` @ `abc1234`);
- official Omeka S / Laminas documentation or a specification;
- a benchmark or a reproducible experiment (numbers + how to reproduce);
- a linked issue, PR, SDD, or prior ADR.

Keep the evidence **inside** the ADR. This repository does not maintain a
separate `sources/` or `experiments/` tree.

## Recording alternatives and consequences

- **Alternatives considered** — list the realistic options, with their pros and
  cons, so a reader can see the decision was a choice and not an accident.
- **Consequences** — state the positive, negative and neutral effects honestly.
  A decision with no downsides is usually a decision that was not examined
  closely enough.

## AI-assisted ADRs

If an AI tool helped draft or research an ADR, disclose it in the frontmatter:

```yaml
ai_assistance:
  tool: "Claude Code"        # tool / interface used
  model: "claude-opus-4-8"   # model, when relevant
```

If no AI tool was involved, set both fields to `none`. Disclosure is about
traceability, not judgement: it records how the document was produced so the
evidence can be weighed accordingly. The frontmatter deliberately records tools
and links, **not** the names of people — use issue and PR links for attribution.

## Superseding an ADR

Accepted ADRs are **append-only**. Do not rewrite them except to fix typos or
broken links.

To change an accepted decision:

1. Create a new ADR with the next ID.
2. Set `supersedes: [ADR-XXXX]` in the new ADR's frontmatter.
3. Set `status: Superseded` and `superseded_by: [ADR-YYYY]` in the old ADR.
4. Update [`records.md`](records.md).

This keeps the decision history intact and readable in order.

## Linking ADRs and SDDs

- List related SDDs under `related.sdds` in the ADR frontmatter and in the
  References section.
- From an SDD, list the ADR in the *ADRs required or referenced* table.
- Refer to ADRs by their ID so links stay stable:
  - **From code / comments:** `// See ADR-0001 for why content is served through the proxy.`
  - **From docs:** `[ADR-0001](ADR-0001-....md)` (adjust the relative path).
  - **From a PR or issue:** mention `ADR-0001` in the description and link the file.

## Workflow

1. Identify a durable decision (see *When an ADR is required*).
2. Copy [`template.md`](template.md) to `ADR-NNNN-short-title.md` with the next
   ID.
3. Fill in context, problem, alternatives, evidence, decision and consequences.
   Start at `status: Proposed`.
4. Add the ADR to [`records.md`](records.md).
5. Open (or reference) a PR. Reviewers discuss and, if agreed, the status moves
   to `Accepted`.
6. If a later change reverses the decision, supersede it — never edit the
   accepted record.

## Review checklist

- [ ] The ADR has a unique, monotonic ID and a kebab-case title.
- [ ] Context, problem, alternatives, decision and consequences are all present.
- [ ] Every technical claim cites a verifiable source.
- [ ] Positive, negative and neutral consequences are stated honestly.
- [ ] `status` reflects reality (`Proposed` while under discussion).
- [ ] `ai_assistance` is filled in (values or `none`).
- [ ] Superseding ADRs set `supersedes` / the old ADR sets `superseded_by`.
- [ ] [`records.md`](records.md) is updated.
