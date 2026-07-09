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
- **[Software Design Documents (SDD)](sdd/README.md)** — the design gate for
  significant changes: goals, non-goals, the proposed design, and how it will be
  validated. SDDs answer *"what are we about to build, and how?"*

## When to use each

- Reach for an **[ADR](adr/README.md)** when a change locks in a decision that
  future contributors should not have to re-litigate — a storage layout, a
  security boundary, a compatibility guarantee.
- Reach for an **[SDD](sdd/README.md)** when a change is large enough to deserve
  a design review before implementation — a new feature, a cross-cutting
  refactor, or a change touching uploads, extraction, the content proxy, or the
  embedded editor.
- A large change often starts with an SDD; the durable decisions inside it are
  extracted into ADRs and linked, instead of being buried in the design prose.

See each guide for the exact rules on when a record is required, recommended, or
unnecessary.

## Module architecture areas

This module's architecture-sensitive surfaces — the ones most likely to justify
an ADR or an SDD — include:

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
adopted this ADR/SDD workflow in
[`exelearning/exelearning#2149`](https://github.com/exelearning/exelearning/pull/2149),
following the proposal in
[`exelearning/exelearning#2148`](https://github.com/exelearning/exelearning/issues/2148),
where the records live under `doc/architecture/`.

`omeka-s-exelearning` adapts the **same lightweight approach** to an Omeka S
module. This repository stores its documentation under `docs/`, so the records
live under `docs/architecture/`. The templates and guidance are tailored to
module concerns — ELPX upload/extraction, Omeka S media and lifecycle hooks, the
content proxy and CSP/iframe security model, the embedded editor install flow,
and Omeka S / PHP compatibility — rather than the main repository's server,
collaboration and export internals. The two repositories keep separate,
independent record histories.
