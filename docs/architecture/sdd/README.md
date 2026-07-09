# Software Design Documents

## Purpose

A **Software Design Document (SDD)** describes *what* a significant change will
build, *why*, *how* it will be validated, and *how* it affects the module. It is
the design gate for large work: the place to agree on goals, non-goals, the
proposed design, data/storage impact, security, accessibility,
internationalization, backward compatibility, testing and rollout **before**
implementation starts.

An SDD makes a big change reviewable as a whole, instead of arriving as a large
pull request that reviewers must reverse-engineer.

## SDDs vs ADRs

| Artifact | Answers | Lifetime |
|----------|---------|----------|
| **SDD** | *What* will be built and *how* it will be implemented | May become historical once implemented |
| **ADR** | *Which* durable decision was made and *why* | Long-lived, append-only |

An SDD is a **design**; an [ADR](../adr/README.md) is a **decision**. A single
SDD often contains several durable decisions (a storage choice, a proxy contract,
a security boundary). Those belong in ADRs so they outlive the feature work — the
SDD then links to them instead of burying them in prose.

An SDD is **not a substitute for an ADR**: when a design introduces a long-lived
technical decision, capture that decision in an ADR (existing or newly proposed)
and link it from the SDD's *ADRs required or referenced* table.

> Every significant proposal may start with an SDD. Every durable architectural
> decision inside that SDD should either link to an existing ADR or propose a new
> one.

## When an SDD is required

Write an SDD for work that needs a design gate before implementation:

- significant new features;
- major refactors or rewrites of a subsystem;
- cross-cutting changes (ELPX upload/extraction, the content proxy, the embedded
  editor install flow, media integration);
- security-sensitive changes (upload validation, path-traversal protection,
  CSP headers, iframe sandboxing, CSRF protection, ACL/permission checks);
- data or storage changes (the `/files/exelearning/{sha1}/` layout, cleanup,
  stored media metadata);
- module lifecycle changes (install/upgrade/uninstall, Omeka S event hooks in
  `Module.php`);
- content proxy or route changes;
- embedded editor download/build/install changes;
- changes affecting public rendering or the admin UI;
- release/packaging or Docker-environment changes;
- proposals with multiple implementation phases.

## When an SDD is recommended

An SDD is worth writing, even when not strictly required, when a change is small
in code but wide in impact — for example a new route contract, or a change that
alters how existing ELPX media are stored or served.

## When an SDD is not needed

Skip the SDD for:

- bug fixes and small enhancements;
- localized changes with an obvious implementation;
- translation-only or test-only changes;
- work already fully covered by an existing, current SDD.

A durable decision that needs no full design can go straight to an
[ADR](../adr/README.md).

## Location and naming

- SDDs live in `docs/architecture/sdd/`.
- Filenames follow: `SDD-NNNN-short-kebab-case-title.md` — for example
  `SDD-0001-elpx-upload-validation.md`.
- IDs are zero-padded, monotonic and never reused. The next ID is
  `max(existing) + 1`, starting at `SDD-0001`.
- [`template.md`](template.md) is the canonical template.
- [`records.md`](records.md) lists every SDD. The index is maintained by hand.

## Status values

| Status | Meaning |
|--------|---------|
| `Draft` | Being written; not yet ready for review. |
| `In Review` | Under review; open for feedback. |
| `Accepted` | Design agreed; implementation may start. |
| `Implemented` | The design has shipped. Kept as a historical record. |
| `Superseded` | Replaced by a newer SDD (see `superseded_by`). |
| `Abandoned` | Dropped before implementation. Kept for the record. |

An SDD can be edited freely while it is `Draft` or `In Review`. Once
`Implemented`, avoid rewriting it except for typo/link fixes. If the design
changes substantially, create a new SDD or mark the previous one `Superseded`.
Implemented SDDs are **preserved as historical design records** — do not delete
them.

## Recording tests, validation, rollout and compatibility

Every SDD must address, or explicitly mark as not-applicable:

- **Testing strategy** — which PHPUnit suites cover the change and how coverage
  is verified (the project targets 90% via `make test-coverage`).
- **Backward compatibility** — impact on existing ELPX media, stored metadata,
  routes and the module configuration; whether a migration is needed.
- **Migration/rollout** — install/upgrade steps, order of merges, and rollback.
- **Security, privacy, accessibility and internationalization** — the
  cross-cutting concerns this module must not skip (proxy, CSP, CSRF, ACL).

## Evidence and traceability

As with ADRs, technical claims should cite a verifiable source: a repository path
plus commit, official Omeka S / Laminas documentation, a benchmark, a
reproducible experiment, or a linked issue, PR or ADR. Keep the evidence inside
the SDD.

## AI-assisted SDDs

If an AI tool helped draft or research an SDD, disclose it in the frontmatter:

```yaml
ai_assistance:
  tool: "Claude Code"        # tool / interface used
  model: "claude-opus-4-8"   # model, when relevant
```

If no AI tool was involved, set both fields to `none`. The frontmatter records
tools and links, **not** the names of people — use issue and PR links for
attribution.

## Linking SDDs and ADRs

Every SDD template includes an **ADRs required or referenced** table. Use it to:

- link durable decisions to existing ADRs; or
- flag decisions that still need an ADR (`ADR needed`).

Do not duplicate a full SDD inside an ADR. The ADR records the decision and its
rationale; the SDD records the implementation design. An ADR may, in turn,
reference one or more SDDs.

## Superseding or abandoning an SDD

- **Substantial design change:** create a new SDD (or set the old one to
  `Superseded` with `superseded_by`).
- **Design dropped before implementation:** set `status: Abandoned` and note why.
- **Shipped:** set `status: Implemented`. Keep it as a historical design record;
  do not delete it.

## Referencing SDDs

Refer to SDDs by their ID so links stay stable:

- **From a PR or issue:** mention `SDD-0001` and link the file.
- **From an ADR:** list the SDD under `related.sdds` and in the References.
- **From docs / code:** `[SDD-0001](SDD-0001-....md)` (adjust the relative path).

## Workflow

1. Copy [`template.md`](template.md) to `SDD-NNNN-short-title.md` with the next
   ID. Start at `status: Draft`.
2. Fill in the design: problem, goals, non-goals, proposed design, affected
   areas, data/storage impact, security, accessibility, internationalization,
   compatibility, testing, rollout and acceptance criteria.
3. List durable decisions in the *ADRs required or referenced* table; create or
   link ADRs for them.
4. Add the SDD to [`records.md`](records.md) and open (or reference) a PR. Move
   to `In Review`.
5. On approval, set `Accepted` and implement. When it ships, set `Implemented`.

## Review checklist

- [ ] The SDD has a unique, monotonic ID and a kebab-case title.
- [ ] Goals and non-goals are explicit.
- [ ] Data/storage, security, privacy, accessibility, i18n, compatibility and
      testing are addressed (or marked not-applicable).
- [ ] Durable decisions are captured in the *ADRs required or referenced* table.
- [ ] Every technical claim cites a verifiable source.
- [ ] `status` reflects reality (`Draft`/`In Review` while under discussion).
- [ ] `ai_assistance` is filled in (values or `none`).
- [ ] [`records.md`](records.md) is updated.
