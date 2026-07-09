---
id: SDD-0000
title: "Short design title"
status: Draft
date: YYYY-MM-DD
related:
  issues: []
  prs: []
  adrs: []
  sdds: []
supersedes: []
superseded_by: []
ai_assistance:
  tool: ""
  model: ""
---

<!--
How to use this template:
1. Copy this file to `SDD-NNNN-short-kebab-case-title.md` with the next free ID.
2. Update the frontmatter above (id, title, date, related links).
3. Fill the relevant sections. Mark sections that truly do not apply as
   "Not applicable" (with a one-line reason) rather than deleting them, and
   delete these guidance comments before submitting.
4. Use an SDD for significant proposals, not small fixes.
5. Cite a verifiable source for each technical claim (repo path + commit,
   Omeka S / Laminas documentation, benchmark, experiment, issue, PR, or ADR).
6. Capture durable decisions in "ADRs required or referenced" — link an existing
   ADR or mark it "ADR needed".
7. Record AI assistance in `ai_assistance` (values, or `none` if not used).
8. Use issue/PR links for attribution — do not add people's names.
Editing is free while Draft / In Review. Once Implemented, only fix typos/links.
See ../README.md for the full policy.
-->

# SDD-0000: Short design title

## Status

Draft

<!-- One of: Draft | In Review | Accepted | Implemented | Superseded | Abandoned.
Keep it in sync with the frontmatter `status`. -->

## Summary

<!-- One or two paragraphs: what this changes and why it matters. -->

## Context

<!-- The background a reviewer needs: how the relevant part of the module works
today at a high level, and what prompted this design. -->

## Problem statement

<!-- The problem being solved, and who has it (site admins, editors, developers
integrating with the module). -->

## Goals

<!-- What success looks like. Make these testable where possible. -->

## Non-goals

<!-- What this design explicitly does not attempt. -->

## Current behavior

<!-- How things work today, in detail. Cite repository paths + commits
(e.g. `src/Controller/ContentController.php`, `Module.php`). -->

## Proposed design

<!-- The design at a high level, then the detail. Diagrams welcome. Name the
classes/files that will change or be added under Module.php, src/, view/,
config/, data/, asset/. -->

## Affected areas

<!-- Check/keep the areas this change touches; remove the rest. -->

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
- [ ] Embedded editor download/build/install flow
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

<!-- Changes to the eXeLearning editor download/build/install flow, the
postMessage bridge, and save/load. Mark "Not applicable" if untouched. -->

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

<!-- PHPUnit coverage (`make test`, `make test-coverage`; the project targets
90%), which flows get a test, and the coding-standards / untranslated-string
checks (`make lint`, `make check-untranslated`). Add stubs under `test/Stubs/`
when Omeka/Laminas collaborators are needed. -->

## Acceptance criteria

<!-- Concrete, checkable conditions for "done". -->

- [ ] ...

## Open questions

<!-- Unresolved points that reviewers should weigh in on. -->

## ADRs required or referenced

<!-- List durable decisions. Link an existing ADR, or mark it "ADR needed". -->

| Decision | ADR | Status |
|----------|-----|--------|
| Example durable decision | ADR-XXXX | Proposed |

## Evidence

<!-- The verifiable basis for the design: repo paths + commits, Omeka S / Laminas
docs, benchmarks, reproducible experiments, issues, PRs, ADRs. No technical claim
without a source. -->

## Follow-up tasks

<!-- The steps to build it, roughly in order, plus any deferred work. Link
issues/PRs when they exist. -->

- [ ] ...

## References

<!-- All sources cited above, plus related issues, PRs, ADRs and SDDs. -->
