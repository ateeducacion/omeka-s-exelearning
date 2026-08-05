# AGENTS.md

This file is the single source of agent guidance for this repository. `CLAUDE.md`
and `.github/copilot-instructions.md` are one-line pointers to it, so there is
one copy to keep correct rather than three that drift.

## Development Workflow

**Test and lint (required before marking done):**
```bash
make lint              # PHP_CodeSniffer PSR2 + architecture-check — must pass
make test              # PHPUnit unit tests
make test-coverage     # Tests + MIN_COVERAGE gate (used in CI)
```

`make test-coverage` is the single blocking verification gate: it fails on any
failing test **and** on line coverage below `MIN_COVERAGE` (90). It measures
`src/` plus the root `Module.php`, excluding factories, and writes its reports
to `artifacts/coverage/` (`coverage.txt`, `clover.xml`, `html/`). Codecov
receives `clover.xml` but is informational only. See
[ADR-32-01](docs/architecture/adr/ADR-32-01-use-a-single-blocking-whole-module-coverage-gate.md);
`MIN_COVERAGE` is a ratchet — raise it, never lower it, and never narrow the
measured set to lift the percentage.

`make lint` also runs `make architecture-check`, which validates the architecture
records. It has no Composer dependencies, so it works in a checkout where
`composer install` has not run.

**Docker dev environment:**
```bash
make up                # Start → http://localhost:8080 (admin@example.com / PLEASE_CHANGEME)
make shell             # SSH into container
make down              # Stop
```

**Editor build** (requires [Bun](https://bun.sh/), not npm/yarn):
```bash
make build-editor      # Build static editor from exelearning submodule
```

**Packaging:**
```bash
make package VERSION=1.2.3
```

## Code Style

- PSR2 standard enforced by phpcs. Run `make fix` to auto-fix.
- Factories are excluded from coverage requirements — keep them to wiring only.
- Test stubs live in `test/Stubs/` (framework classes the code type-hints or
  extends) and test doubles in `test/ExeLearningTest/Doubles/` (collaborators
  resolved by service name). The `omeka-s-testing` skill explains which is which.

## Git Conventions

- Branch names: `feature/*`, `fix/*`, `hotfix/*`
- CI runs on push/PR: untranslated string check, lint, test-coverage (all must pass)

## Architecture Notes

See the Security & Architecture section below for the full system design. Key gotchas:
- All ELPX content is served through `ContentController` (proxy) — never expose `/files/exelearning/` directly.
- State-changing endpoints validate CSRF via `CsrfValidationTrait`, which reads
  the token from the `csrf` POST field, the `X-CSRF-Token` header or the `csrf`
  query parameter and checks it with `Laminas\Validator\Csrf`. A missing token is
  a rejection. CSRF is not authorization — follow it with an
  `$acl->userIsAllowed('Omeka\Entity\Media', 'update')` check.
- The module uses Omeka event hooks (`api.hydrate.post`, `api.create.post`,
  `api.delete.pre`, `view.show.after`, `view.layout`, `rep.resource.json`) —
  check `Module.php` before adding new lifecycle behavior.
- URL building does **not** use `$request->getBasePath()`: it is unreliable in
  the PHP-WASM playground, where the `/playground/{uuid}/php83/` prefix is
  present in the request URI but absent from `$_SERVER['SCRIPT_NAME']`.
  `Module::extractBasePath()` derives the prefix from the request URI instead.

## Architecture decisions and design documents

Significant technical work is documented alongside the code under
[`docs/architecture/`](docs/architecture/README.md). Full policy:
[ADR guide](docs/architecture/adr/README.md),
[change-document guide](docs/architecture/changes/README.md).

- **Identifiers are tracking numbers, not a counter.** ADRs are
  `ADR-<number>-<NN>-<decision-slug>.md`; change directories are
  `changes/<number>-<change-slug>/`. `<NN>` is two digits scoped to that number
  alone, starting at `01`, present even for a single ADR. The slug names the
  **decision**, not the topic.
- **In this repository the tracking number is always a pull-request number.**
  GitHub Issues are disabled here; issue tracking lives upstream in
  [`exelearning/exelearning`](https://github.com/exelearning/exelearning/issues),
  whose numbers come from a different sequence and must never be used as an
  identifier — link them under `related.issues` instead. **Never open an issue
  anywhere just to obtain a number.** A PR number only exists once the PR is
  open: open the PR first, then add the record in a follow-up commit on the same
  branch.
- Before implementing a significant architectural change, run
  `make architecture-records` to list the existing records. There is **no
  committed index** — it is derived from frontmatter on demand.
- **Create or update a change document** for large changes, cross-cutting
  features, security-sensitive changes, data/storage changes, module lifecycle
  changes, content-proxy changes, embedded-editor changes, upload/extraction
  changes, or changes affecting public rendering. They live under
  `docs/architecture/changes/<number>-<slug>/` as `proposal.md`, `spec.md`,
  `design.md`, `research.md` and `tasks.md` — create only the files that carry
  real content.
- **Create an ADR** for durable technical decisions with long-term consequences
  (storage layout, ELPX validation/extraction, content-proxy/CSP/iframe security
  model, CSRF/ACL boundaries, Omeka S event-hook contracts, Omeka S/PHP
  compatibility, the verification contract). ADRs live under
  `docs/architecture/adr/`.
- Templates: `docs/architecture/adr/template.md`,
  `docs/architecture/changes/template.md`.
- **Status lives in the frontmatter only.** Do not add a `## Status` section.
  The H1 must be exactly `# <id>: <title>`.
- Keep **accepted ADRs append-only** — supersede them with a new ADR
  (`supersedes` / `superseded_by`) instead of rewriting history. Preserve
  **implemented change documents** as historical records; fix only typos/links.
- When both exist, **link them** (the design's *ADRs required or referenced*
  table plus `related_adrs`, and the ADR's `related.changes`).
- Record AI assistance in the frontmatter (`ai_assistance.tool` /
  `ai_assistance.model`; `none` if not used). Use issue/PR links for
  attribution — no people's names in frontmatter or templates.
- **Retired identifiers** (the old four-digit `ADR-NNNN` / `SDD-NNNN` form) must
  not appear in new content; `make architecture-check` fails on them.
  [`docs/architecture/migration-map.md`](docs/architecture/migration-map.md)
  resolves each one to its current path.
- **Do not** create records for trivial fixes, copy edits, translation-only or
  test-only changes, or straightforward bug fixes that do not change
  architecture. Do not create one ADR per section of a design.
- Run `make architecture-check` before submitting. It also runs as part of
  `make lint`, and in CI through the *Architecture records* workflow, which —
  unlike the main CI job — is not filtered by `paths-ignore`, so a
  documentation-only PR is still validated.
- Keep all architecture docs in English. For PHP code, continue following the
  repository's coding standards and the testing/linting rules above.

## Skills

Recurring procedures and domain knowledge live as skills in `.agents/skills/`,
the path GitHub Copilot, Codex and other agents read directly. Claude Code reads
`.claude/skills/`, which contains **symlinks** to those same directories, not
copies. When adding a skill, create it in `.agents/skills/` and link it from
`.claude/skills/`; never duplicate a `SKILL.md`.

Read the relevant skill *before* working in its area.

| Skill | Read it before | Origin |
| --- | --- | --- |
| `omeka-s-module-development` | Touching `Module.php`, `config/module.config.php`, services/factories, the config form, ACL/CSRF boundaries or the on-disk layout | first-party |
| `omeka-s-api-and-adapters` | Working with `api.*` / `rep.resource.json` events, media data, or the `/api/exelearning` endpoints | first-party |
| `omeka-s-testing` | Writing tests, adding a stub or double, or diagnosing a coverage number | first-party |
| `add-service` / `add-route` / `add-event` | Adding one of those, for the concrete file-by-file steps | first-party |
| `i18n` | Running the translation pipeline | first-party |
| `verify` | Running the full local verification pipeline | first-party |
| `release` | Packaging a release | first-party |
| `security-audit` | Hunting vulnerabilities and validating findings | [`cloudflare/security-audit-skill`](https://github.com/cloudflare/security-audit-skill), vendored |

**First-party skills describe this repository** and must be updated when the code
they describe changes — a skill that documents a removed API is worse than no
skill. **Vendored skills are third party and copied verbatim**: do not reformat
or edit them in place, because diverging from upstream makes future updates
harder. Fix the problem upstream and re-vendor instead. `security-audit` is
shared with the sibling `wp-exelearning` repository.

Skills are kept out of the release ZIP by `.distignore` and out of the source ZIP
by `.gitattributes`. Those two files have separate jobs and must not be kept in
sync with each other: `.distignore` is the only list `rsync` reads in
`make package` and is the single source of truth for what ships, while
`.gitattributes` only shapes the source ZIP GitHub serves at
`archive/refs/heads/*.zip`, the one `blueprint.json` installs in Playground.

---

# ExeLearning Module for Omeka S - Security & Architecture

This document describes the security considerations and system architecture implemented in the ExeLearning module.

## Architecture Overview

The module enables viewing and editing of eXeLearning (.elpx) files within Omeka S. The system consists of:

```
+------------------+     +-------------------+     +------------------+
|  Admin Interface |     |  Content Proxy    |     |  Editor (iframe) |
|  (media-show)    |---->|  (ContentController)|-->|  (eXeLearning)   |
+------------------+     +-------------------+     +------------------+
         |                        |                        |
         v                        v                        v
+------------------+     +-------------------+     +------------------+
|  Modal Editor    |     |  /files/exelearning/|   |  postMessage API |
|  (fullscreen)    |     |  (extracted files) |   |  (communication) |
+------------------+     +-------------------+     +------------------+
         |                                                 |
         v                                                 v
+------------------+                              +------------------+
|  API Controller  |<-----------------------------|  Bridge JS       |
|  (save/load)     |                              |  (import/export) |
+------------------+                              +------------------+
```

## File Storage

- **Original .elpx files**: Stored in Omeka's standard `/files/original/` directory
- **Extracted content**: Stored in `/files/exelearning/{sha1-hash}/` directories
- **Thumbnails**: Generated and stored as custom thumbnails for media items

## Security Measures

### 1. Iframe Sandboxing

All iframes displaying eXeLearning content use restrictive sandbox attributes:

```html
<iframe
    sandbox="allow-scripts allow-popups allow-popups-to-escape-sandbox"
    referrerpolicy="no-referrer"
    ...
></iframe>
```

**Allowed capabilities:**
- `allow-scripts`: Required for interactive content
- `allow-popups`: Some eXeLearning content may need popups
- `allow-popups-to-escape-sandbox`: Popups can function normally

**Blocked capabilities:**
- `allow-same-origin`: Prevents access to parent page cookies/storage
- `allow-forms`: Prevents form submission to external URLs
- `allow-top-navigation`: Prevents navigation of parent page

### 2. Content Security Policy (CSP)

The ContentController adds strict CSP headers for HTML content:

```
Content-Security-Policy:
  default-src 'self';
  script-src 'self' 'unsafe-inline' 'unsafe-eval';
  style-src 'self' 'unsafe-inline';
  img-src 'self' data: blob:;
  media-src 'self' data: blob:;
  font-src 'self' data:;
  frame-src 'self';
  object-src 'none';
  base-uri 'self'
```

This prevents:
- Loading external scripts (XSS mitigation)
- Connecting to external servers
- Embedding external iframes
- Using plugins (Flash, Java, etc.)

### 3. Secure Content Proxy

Direct access to `/files/exelearning/` is blocked. All content is served through a PHP proxy (`ContentController::serveAction`):

**Security validations:**
1. Hash format validation (40 hex characters - SHA1)
2. Path traversal prevention (blocks `..`)
3. File existence verification
4. MIME type detection and Content-Type headers

```php
// Hash validation
if (!preg_match('/^[a-f0-9]{40}$/', $hash)) {
    return $this->notFoundAction();
}

// Path traversal prevention
if (strpos($file, '..') !== false) {
    return $this->notFoundAction();
}
```

### 4. Additional Security Headers

```
X-Frame-Options: SAMEORIGIN
X-Content-Type-Options: nosniff
```

- **X-Frame-Options**: Prevents clickjacking by blocking external framing
- **X-Content-Type-Options**: Prevents MIME-sniffing attacks

### 5. Server Configuration (nginx)

The module requires nginx rules to:

1. **Block direct file access:**
```nginx
location ^~ /files/exelearning/ {
    return 403;
}
```

2. **Route proxy requests to PHP:**
```nginx
location ^~ /exelearning/content/ {
    try_files $uri /index.php$is_args$args;
}
```

### 6. CSRF Protection

State-changing endpoints validate a CSRF token through the shared
`ExeLearning\Controller\CsrfValidationTrait`. The token is read from the `csrf`
POST field, the `X-CSRF-Token` header or the `csrf` query parameter, in that
order, and validated against the session-bound `Laminas\Validator\Csrf`:

```php
if (!$this->validateCsrf($request)) {
    return new ApiProblemResponse(new ApiProblem(403, 'Invalid CSRF token'));
}
```

A request that omits the token entirely is rejected — a missing token is never
treated as "no check required", which is the bypass this trait closed.

### 7. ACL Permissions

CSRF proves the request came from our page; it says nothing about whether the
user may perform the action. Every state-changing endpoint therefore also asks
the ACL, after the token check:

```php
$acl = $this->getEvent()->getApplication()->getServiceManager()->get('Omeka\Acl');
if (!$acl->userIsAllowed('Omeka\Entity\Media', 'update')) {
    return new ApiProblemResponse(new ApiProblem(403, 'Permission denied'));
}
```

## Communication Flow

### Parent-Iframe Communication

Communication uses `postMessage` API with origin validation:

**Editor to Parent:**
```javascript
window.parent.postMessage({
    type: 'exelearning-bridge-ready'
}, window.location.origin);

window.parent.postMessage({
    type: 'exelearning-save-complete',
    success: true
}, window.location.origin);
```

**Parent to Editor:**
```javascript
iframe.contentWindow.postMessage({
    type: 'exelearning-request-save'
}, '*');
```

### Save Flow

1. User clicks "Save to Omeka" button
2. Parent sends `exelearning-request-save` message
3. Bridge exports ELPX from editor
4. Bridge POSTs to `/api/exelearning/save/{id}` with CSRF token
5. Server validates token, permissions, and saves file
6. Bridge sends `exelearning-save-complete` message
7. Parent closes modal and refreshes preview

## File Types and MIME Detection

The module handles various file types within .elpx archives:

| Extension | MIME Type |
|-----------|-----------|
| .html | text/html |
| .css | text/css |
| .js | application/javascript |
| .json | application/json |
| .png | image/png |
| .jpg/.jpeg | image/jpeg |
| .gif | image/gif |
| .svg | image/svg+xml |
| .mp4 | video/mp4 |
| .webm | video/webm |
| .mp3 | audio/mpeg |
| .ogg | audio/ogg |
| .woff/.woff2 | font/woff, font/woff2 |
| .ttf | font/ttf |
| .pdf | application/pdf |

## Potential Attack Vectors (Mitigated)

1. **XSS via uploaded content**: Mitigated by CSP headers and iframe sandboxing
2. **Path traversal**: Mitigated by `..` filtering and hash validation
3. **CSRF attacks**: Mitigated by CSRF token validation
4. **Unauthorized editing**: Mitigated by ACL permission checks
5. **Clickjacking**: Mitigated by X-Frame-Options header
6. **Direct file access**: Mitigated by nginx rules blocking /files/exelearning/
7. **MIME sniffing**: Mitigated by X-Content-Type-Options header

## Recommendations for Administrators

1. Ensure nginx is properly configured with the blocking rules
2. Review CSP headers if specific eXeLearning content requires external resources
3. Keep the module updated for security patches
4. Monitor server logs for suspicious access patterns
5. Consider additional rate limiting on API endpoints
