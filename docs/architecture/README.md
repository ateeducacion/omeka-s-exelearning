# Architecture Documentation

This directory holds the **architecture records** for the `omeka-s-exelearning`
Omeka S module: durable decisions and significant designs that future
contributors should be able to read long after the pull request that introduced
them has scrolled out of view.

## Operational docs vs architecture records

The module keeps two different kinds of documentation, and they answer different
questions:

| Kind | Lives in | Answers |
|------|----------|---------|
| **Operational docs** | [`README.md`](../../README.md), [`SECURITY.md`](../../SECURITY.md), the Security & Architecture notes in [`AGENTS.md`](../../AGENTS.md) | *How do I use, run or secure this?* — install steps, nginx rules, the current proxy/CSP model. Describes the module as it is **now**. |
| **Architecture records** | `docs/architecture/` (this directory) | *Why is it built this way?* and *what are we about to build?* — the reasoning behind durable decisions and the design of significant changes. |

Operational docs are updated in place to match the code. Architecture records are
kept as a history: accepted decisions are not rewritten, they are superseded.

## What lives here

- **[Architecture Decision Records (ADR)](adr/README.md)** — one durable
  decision per record, with the context, the options, the evidence and the
  consequences. ADRs answer *"why is it built this way?"*
- **[Change documents](changes/README.md)** — the design gate for significant
  changes: goals, non-goals, the proposed design, and how it will be validated.
  They answer *"what are we about to build, and how?"* One directory per change,
  holding `proposal.md`, `spec.md`, `design.md`, `research.md` and `tasks.md` as
  needed. These were previously called Software Design Documents (SDD).
- **[The migration map](migration-map.md)** — every retired identifier and where
  its record lives now.

## When to use each

- Reach for an **[ADR](adr/README.md)** when a change locks in a decision that
  future contributors should not have to re-litigate — a storage layout, a
  security boundary, a compatibility guarantee.
- Reach for a **[change document](changes/README.md)** when a change is large
  enough to deserve a design review before implementation — a new feature, a
  cross-cutting refactor, or a change touching uploads, extraction, the content
  proxy, or the embedded editor.
- A large change often starts with a change directory; the durable decisions
  inside it are extracted into ADRs and linked, instead of being buried in the
  design prose.

See each guide for the exact rules on when a record is required, recommended, or
unnecessary.

## Identification

Records are identified by their **GitHub tracking number**, not by a global
counter: `ADR-<number>-<NN>-<decision-slug>.md` for decisions, and
`changes/<number>-<change-slug>/` for designs.

GitHub Issues are disabled on this repository — issue tracking is centralized in
[`exelearning/exelearning`](https://github.com/exelearning/exelearning/issues) —
so the tracking number here is always a **pull-request number** from this
repository's own sequence. Upstream issue numbers come from a different sequence
and are recorded as `related.issues`, never as identifiers.

There is no committed index: `make architecture-records` prints one from the
document frontmatter, and `make architecture-check` validates identifiers,
metadata and cross-references. Both are also run by `make lint` and by CI.

## Reading and validating the records

```bash
make architecture-records   # print the ADR and change indexes
make architecture-check     # validate identifiers, metadata, cross-references
```

## Module architecture areas

This module's architecture-sensitive surfaces — the ones most likely to justify
an ADR or a change document — include:

- `.elpx` upload and validation;
- ZIP extraction and the on-disk filesystem layout
  (`/files/original/`, `/files/exelearning/{sha1}/`);
- Omeka S media integration and the `view.show.after` / `api.*` lifecycle hooks;
- the embedded eXeLearning editor integration and its download/build/install
  flow;
- content proxying through `ContentController`;
- Content-Security-Policy headers and iframe sandboxing;
- the admin UI (media edit/save, module configuration form);
- public media rendering;
- module configuration;
- release packaging;
- the Docker development environment.

## Relationship to the main eXeLearning repository

The main [eXeLearning](https://github.com/exelearning/exelearning) repository
adopted this architecture-record workflow in
[`exelearning/exelearning#2149`](https://github.com/exelearning/exelearning/pull/2149),
following the proposal in
[`exelearning/exelearning#2148`](https://github.com/exelearning/exelearning/issues/2148),
and replaced its global ADR/SDD counter with tracking-number-based identifiers in
[`exelearning/exelearning#2232`](https://github.com/exelearning/exelearning/issues/2232).
There the records live under `doc/architecture/`.

`omeka-s-exelearning` adapts the **same lightweight approach** to an Omeka S
module. This repository stores its documentation under `docs/`, so the records
live under `docs/architecture/`. The templates and guidance are tailored to
module concerns — ELPX upload/extraction, Omeka S media and lifecycle hooks, the
content proxy and CSP/iframe security model, the embedded editor bundling,
and Omeka S / PHP compatibility — rather than the main repository's server,
collaboration and export internals. The two repositories keep separate,
independent record histories, and their tracking numbers are drawn from separate
GitHub sequences.

Two adaptations are deliberate, and the local validator enforces them:

- **No `deciders` / `reviewers` fields.** This repository's frontmatter records
  tools and links, never people's names; attribution belongs in PR links.
- **Cross-repository references are first class.** `related.prs` and
  `related.issues` accept `owner/repo#123` and full GitHub URLs, because much of
  this module's traceability points at `exelearning/exelearning`,
  `wp-exelearning` and `moodle-mod_exelearning`.

The validator itself (`scripts/architecture-records.mts`) is a PHP port of the
main repository's Bun/TypeScript checker. The rules are shared; the runtime is
not, because this repository's CI installs only PHP.
