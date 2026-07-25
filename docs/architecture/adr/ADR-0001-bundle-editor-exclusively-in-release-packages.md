---
id: ADR-0001
title: "Bundle the embedded editor exclusively in release packages"
status: Accepted
date: 2026-07-24
related:
  issues: []
  prs:
    - https://github.com/exelearning/moodle-mod_exelearning/pull/106
    - https://github.com/exelearning/wp-exelearning/pull/72
  sdds: []
  adrs: []
supersedes: []
superseded_by: []
ai_assistance:
  tool: "Claude Code"
  model: "claude-fable-5"
---

# ADR-0001: Bundle the embedded editor exclusively in release packages

## Status

Accepted

## Context

The module embeds the static eXeLearning editor from `dist/static/` inside the
module directory (`view/exelearning/editor-bootstrap.phtml` @ `b7796ea`).
Until this decision, that directory could be populated in two ways:

1. **Release packaging.** The release workflow builds the editor from the
   matching editor tag and packages it into the official release ZIP
   (`.github/workflows/release.yml` @ `b7796ea`), and a scheduled workflow
   cuts a new module release whenever a new editor release is published
   (`.github/workflows/check-editor-releases.yml` @ `b7796ea`).
2. **Runtime installation.** `ExeLearning\Service\StaticEditorInstaller`
   (`src/Service/StaticEditorInstaller.php` @ `b7796ea`) let an administrator
   download the latest static editor ZIP from GitHub Releases from the module
   configuration page, with release discovery via the GitHub Atom feed,
   SHA-256 digest verification, hardened ZIP extraction, atomic install with
   backup and rollback, a phase/lock state machine persisted in eight Omeka
   settings, two controllers exposing install/status endpoints on four routes
   (`config/module.config.php` @ `b7796ea`), and a polling admin UI
   (`asset/js/exelearning-installer.js` @ `b7796ea`).

The runtime path duplicates what the release pipeline already guarantees, and
it has real costs:

- Downloading executable code at runtime is exactly what module reviewers
  flag as *remotely sourced executable code*; the equivalent Moodle and
  WordPress plugins removed their runtime installers for this reason
  (exelearning/moodle-mod_exelearning#106, exelearning/wp-exelearning#72).
- Two sites reporting the same module version can serve different editor
  builds, which breaks support and diagnostics.
- The installer is a permanent security and maintenance surface: network,
  TLS, feed/API parsing, ZIP extraction, filesystem rollback and locking —
  all to reproduce a file tree the release ZIP already contains.
- The module directory must be writable by PHP for the installer to work,
  which contradicts hardened deployments where `modules/` is read-only.

Omeka S Playground has its own bootstrap: `blueprint.json` @ `b7796ea`
already downloads a pinned editor release asset
(`exelearning-static-v4.0.2.zip`) and unpacks it into the module's
`dist/static` before use, entirely at blueprint level, without touching the
runtime installer.

## Problem

Which distribution mechanism(s) should be supported for the embedded editor,
and may the module ever download editor code at runtime?

## Decision drivers

- Module-review constraints: no remotely sourced executable code at runtime.
- Security: minimize network, ZIP-extraction and filesystem attack surface.
- Reproducibility: one module version ↔ one known editor build.
- Operational simplicity for administrators.
- Development and Playground workflows must remain viable.

## Alternatives considered

### Option 1: Keep the runtime installer (status quo)

- Pro: a source checkout can self-provision the editor from the admin UI.
- Con: remotely sourced executable code; version drift between sites; large
  security surface; requires a writable module directory.

### Option 2: Keep the installer but disable it by default

- Pro: escape hatch for unusual setups.
- Con: all the code and its attack surface remain shipped; a single option
  flip re-enables remote code download. Rejected.

### Option 3: Let administrators upload an editor ZIP manually

- Pro: no network access from the module.
- Con: still decouples the served editor from the reviewed release and keeps
  the ZIP-extraction/rollback machinery. Rejected.

### Option 4: Load the editor directly from a remote URL (CDN)

- Con: remote executable code on every page load, availability coupling, and
  breaks the offline design of the static editor. Rejected.

### Option 5: Bundle the editor exclusively in release packages (chosen)

- Pro: every byte of editor code served by the module is part of the reviewed
  release ZIP; single editor version per module version; the installer's
  entire surface is deleted; works on read-only module directories.
- Con: updating the editor requires publishing a module release (already
  automated by `check-editor-releases.yml`); source checkouts must run
  `make build-editor` (already the documented workflow, `README.md` @
  `b7796ea`).

## Evidence

- `src/Service/StaticEditorInstaller.php` @ `b7796ea` — the runtime
  downloader this ADR removes.
- `.github/workflows/release.yml` and
  `.github/workflows/check-editor-releases.yml` @ `b7796ea` — release ZIPs
  already bundle the editor built from the matching tag, and a new editor
  release automatically produces a new module release.
- `blueprint.json` @ `b7796ea` — Playground provisions the editor at
  blueprint level from a pinned release asset, not through the installer.
- exelearning/moodle-mod_exelearning#106 and exelearning/wp-exelearning#72 —
  the same architectural change in the Moodle and WordPress plugins.

## Decision

We will treat the embedded editor exclusively as a release artifact:

- Official release ZIPs are the only supported distribution mechanism for the
  embedded editor; `dist/static/` inside the module directory is the only
  runtime editor source, exposed through the read-only
  `ExeLearning\Service\EditorBundle` helper.
- The module never downloads editor code at runtime. The installer service,
  its four routes, the install/status controller actions, the polling admin
  UI and the eight installer settings are removed.
- When the bundle is absent or invalid, embedded editing is disabled cleanly:
  the editor screen redirects to the module configuration page with an
  explanatory message, the export endpoint answers 503, and client-side
  export formats are marked unavailable.
- `make package` refuses to produce a ZIP when `dist/static/index.html`, the
  expected asset directories, or a non-empty `.editor-version` are missing.
- Omeka S Playground keeps fetching the editor at blueprint level, pinned to
  an exact release tag, before the module is used.

## Consequences

### Positive

- All executable editor code served by the module is part of the reviewed
  release package.
- A module version corresponds to one known editor version.
- Reduced attack surface: no runtime network, TLS, feed/API parsing, ZIP
  extraction, rollback or locking code paths.
- Simpler configuration page and runtime architecture; works with read-only
  module directories.

### Negative

- Updating the editor requires publishing a new module release (mitigated by
  the automated `check-editor-releases.yml` workflow).
- Development checkouts do not contain `dist/static/` until
  `make build-editor` runs (already the documented workflow).

### Neutral

- The eight `exelearning_editor_install*`/`exelearning_editor_installed_*`
  settings are deleted on module upgrade and uninstall; nothing else
  persists.
- A previously self-installed `dist/static/` copy is simply replaced on the
  next module update, which is the intent of this decision.

## Risks

- A release could theoretically be packaged without the editor if the guard
  is bypassed; the `make package` validation makes this loud and
  non-zero-exit.
- Sites that relied on the installer to fix a broken `dist/static/` must
  reinstall the module package instead; the configuration-page warning
  explains this.

## Validation

- PHPUnit covers: a valid bundle detected, an absent bundle disabling editing
  cleanly, and an invalid bundle (missing asset directories) rejected.
- `make package` fails with a clear stderr message and non-zero exit when
  `dist/static/` or `.editor-version` is missing or empty, and produces no
  partial ZIP.

## Follow-up work

- None beyond the implementing PR; the Playground blueprint already conforms.

## References

- exelearning/moodle-mod_exelearning#106 — equivalent decision in the Moodle
  plugin.
- exelearning/wp-exelearning#72 — equivalent decision in the WordPress
  plugin.
- `docs/architecture/adr/records.md` — ADR index.
