---
tracking_issue: NNN   # this repository's pull-request number
title: "Short change title"
status: draft
date: YYYY-MM-DD
implementation_prs: []
related_issues: []    # upstream exelearning/exelearning issues, if any
related_adrs: []
supersedes: []
superseded_by: []
ai_assistance:
  tool: ""
  model: ""
---

<!--
How to use this template:

1. Find the change's GitHub tracking NUMBER. In this repository that is always
   the PULL REQUEST number: GitHub Issues are disabled here, and numbers from
   the upstream exelearning/exelearning tracker come from a different sequence,
   so they must never be used as an identifier. Link those under
   `related_issues` instead. NEVER open an issue anywhere just to get a number.
   The PR number only exists once the PR is open: open it first, then add the
   change directory in a follow-up commit on the same branch.
2. Create `docs/architecture/changes/<number>-<change-slug>/`.
3. Copy the frontmatter above into each document you create, and copy the
   matching section skeleton below into that document.
4. CREATE ONLY THE DOCUMENTS THAT CARRY REAL CONTENT. Empty placeholders are not
   required. A small change may be a single `proposal.md`.
5. Do not duplicate content across proposal.md, spec.md and design.md.
6. `implementation_prs` belongs ONLY in the canonical document — the first of
   proposal.md, spec.md, design.md, research.md, tasks.md that exists.
7. Status lives in the frontmatter only. Do not add a `## Status` section.
8. Mark sections that truly do not apply as "Not applicable" with a one-line
   reason, rather than deleting them.
9. Cite a verifiable source for each technical claim (repo path + commit,
   Omeka S / Laminas documentation, benchmark, experiment, issue, PR, or ADR).
10. Record AI assistance in `ai_assistance` (values, or `none` if not used).
11. Use issue/PR links for attribution — do not add people's names.
12. Run `make architecture-check` to validate.

Delete these guidance comments before submitting.
See ./README.md for the full policy.
-->

# Short change title — <document kind>

<!-- ======================================================================
     proposal.md — motivation, problem, scope, goals, non-goals
     ====================================================================== -->

## Motivation

<!-- Why this work is being done now. What is broken, missing or costly. -->

## Problem statement

<!-- The problem being solved, and who has it (site admins, editors, developers
integrating with the module), stated so a reader can tell whether a proposed
solution actually solves it. -->

## Scope

<!-- What is in scope and what is explicitly out of scope. -->

## Goals

<!-- What success looks like. Make these testable where possible. -->

- ...

## Non-goals

<!-- What this design explicitly does not attempt. -->

- ...

<!-- ======================================================================
     spec.md — observable behavior, requirements, scenarios, acceptance
     ====================================================================== -->

## Requirements

<!-- Normative statements. Use must / must not / may. Number them so reviews and
tests can cite them. -->

## Scenarios

<!-- Concrete admin-visible, public-visible or API-visible scenarios:
given / when / then. -->

## Acceptance criteria

- [ ] ...

<!-- ======================================================================
     design.md — technical implementation design
     ====================================================================== -->

## Current behavior

<!-- How things work today, in detail. Cite repository paths + commits
(e.g. `src/Controller/ContentController.php`, `Module.php`). -->

## Technical design

<!-- The design at a high level, then the detail. Diagrams welcome. Name the
classes/files that will change or be added under Module.php, src/, view/,
config/, data/, asset/. -->

## Affected areas

<!-- Check the areas this change touches; remove the rest. -->

- [ ] Module lifecycle / event hooks (`Module.php`)
- [ ] Controllers (`src/Controller/`, `src/Controller/Admin/`)
- [ ] Services (`src/Service/`)
- [ ] Forms (`src/Form/`)
- [ ] Media renderers (`src/Media/`, `src/Media/FileRenderer/`)
- [ ] Module configuration and routes (`config/module.config.php`, `config/module.ini`)
- [ ] Admin and public templates (`view/`)
- [ ] Static assets (`asset/`)
- [ ] Install / seed / fixture data (`data/`)
- [ ] Translations (`language/`)
- [ ] Tests (`test/ExeLearningTest/`)
- [ ] ELPX upload / extraction / ZIP validation
- [ ] Content proxy (`ContentController`) and routing
- [ ] CSP headers / iframe sandboxing / security headers
- [ ] Embedded editor build / bundling flow
- [ ] Release packaging / Docker development environment

## Data model or storage impact

<!-- New/changed on-disk structures: the `/files/original/` and
`/files/exelearning/{sha1}/` layout, thumbnails, cleanup, and any stored media
metadata. Note new keys and how existing data is handled. -->

## Omeka S resource/media impact

<!-- Impact on Omeka S items, media, ingesters/renderers, and the lifecycle
event hooks (`api.hydrate.post`, `api.create.post`, `api.delete.pre`,
`view.show.after`). Note ACL/permission implications. -->

## Module configuration impact

<!-- New/changed module settings, the configuration form, install/upgrade/
uninstall steps, and default values. Mark "Not applicable" if untouched. -->

## Admin UI impact

<!-- Changes to the admin media views, the edit/save flow, the modal editor, and
the module configuration screen. Mark "Not applicable" if untouched. -->

## Public rendering impact

<!-- Changes to how ELPX media are rendered on the public site (viewer, iframe,
thumbnails). Mark "Not applicable" if untouched. -->

## Content proxy impact

<!-- Changes to `ContentController` (hash validation, path-traversal protection,
MIME handling), routes, and the nginx/Apache rules that block direct
`/files/exelearning/` access. Mark "Not applicable" if untouched. -->

## Embedded editor impact

<!-- Changes to the bundled editor, the postMessage bridge, and save/load.
Mark "Not applicable" if untouched. -->

## Security considerations

<!-- Upload validation, ZIP/path-traversal protection, CSP headers, iframe
sandboxing, CSRF token validation, ACL/permission checks, output escaping and
input sanitization. State the trust boundaries this change crosses. -->

## Privacy considerations

<!-- Any personal data handled, stored, logged or exposed; retention; and how
uninstall treats it. Mark "Not applicable" if none. -->

## Accessibility considerations

<!-- Keyboard operation, screen-reader labels, focus management, contrast, and
the embedded editor / viewer surfaces. -->

## Internationalization considerations

<!-- New user-facing strings wrapped for translation, `/* translators: */`
comments for placeholders, and updating the catalogs (`make generate-pot`,
`make update-po`, `make compile-mo`). -->

## Backward compatibility

<!-- Impact on existing ELPX media, stored metadata, routes, module settings and
the content-proxy contract. What keeps working unchanged, and what does not.
Note Omeka S and PHP version requirements. -->

## Migration/rollout

<!-- Install/upgrade/uninstall handling, data migration (e.g. re-extraction),
order of merges, staged enablement, and rollback. -->

## Testing strategy

<!-- PHPUnit coverage (`make test`, `make test-coverage`; the gate is 90%),
which flows get a test, and the coding-standards / untranslated-string checks
(`make lint`, `make check-untranslated`). Add stubs under `test/Stubs/` when
Omeka/Laminas collaborators are needed. -->

## Performance

## Risks and mitigations

## Open questions

<!-- Unresolved points that reviewers should weigh in on. -->

## ADRs required or referenced

<!-- List durable decisions. Link an existing ADR, or mark it "ADR needed".
Keep this table in sync with `related_adrs` in the frontmatter. -->

| Decision | ADR | Status |
|----------|-----|--------|
| Example durable decision | ADR-NNN-01 | Proposed |

<!-- ======================================================================
     research.md — evidence, experiments, alternatives, source analysis
     ====================================================================== -->

## Evidence

<!-- The verifiable basis for the design: repo paths + commits, Omeka S /
Laminas docs, benchmarks, reproducible experiments, issues, PRs, ADRs. No
technical claim without a source. -->

## Alternatives considered

<!-- The realistic options, with their pros and cons. Durable choices among them
belong in an ADR, not here. -->

<!-- ======================================================================
     tasks.md — implementation plan and progress
     ====================================================================== -->

## Implementation plan

<!-- The steps to build it, roughly in order, plus any deferred work. Link PRs
when they exist. -->

- [ ] ...

## References

<!-- All sources cited above, plus related issues, PRs and ADRs. -->
