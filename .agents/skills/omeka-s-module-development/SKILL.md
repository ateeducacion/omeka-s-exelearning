---
name: omeka-s-module-development
description: "Use when working on this Omeka S module's structure: Module.php lifecycle (install/upgrade/uninstall), config/module.config.php wiring, services and factories, the config form, ACL and CSRF boundaries, settings, and where files live on disk."
compatibility: "Omeka S >= 3.0 on PHP >= 7.4 (composer.json pins the 7.4 platform; CI runs 8.3). Laminas MVC 3 / ServiceManager 3."
---

# Omeka S Module Development

## When to use

Reach for this before changing `Module.php`, `config/module.config.php`, anything
under `src/Service/`, the module config form, or the on-disk layout. For the API
layer and event payloads see `omeka-s-api-and-adapters`; for tests see
`omeka-s-testing`.

## The shape of this module

```
Module.php                  lifecycle + every event handler (kept under the coverage gate)
config/module.config.php    routes, controllers, services, form elements, file renderers
config/module.ini           metadata; `make package` writes the version here
src/Controller/             Api, Content, Editor, StylesServe, Admin\Styles
src/Service/                ElpFileService, StylesService, EditorBundle, DownloadFormats, ZipSafety
src/Media/FileRenderer/     ExeLearningRenderer (registered as `exelearning_renderer`)
src/Form/                   ConfigForm, StylesUploadForm
view/                       .phtml partials, resolved via template_path_stack
data/exelearning/           extracted ELPX content, one directory per SHA1
dist/static/                the bundled editor -- a release artifact, see ADR-0001
```

`Module.php` lives at the repository root, which is Omeka's convention and means
it is **outside** Composer's `ExeLearning\ -> src/` PSR-4 map. Omeka loads it
directly; the test bootstrap has an explicit branch for it. Do not "fix" this by
moving it.

## Lifecycle

`install()`, `uninstall()` and `upgrade()` all receive a `ServiceLocatorInterface`
and must be **idempotent** — Omeka can re-run an upgrade, and a partially
installed module still has to uninstall cleanly.

- `install()` widens `media_type_whitelist` and `extension_whitelist` through
  `Omeka\Settings`, then creates the data directory. Always merge into the
  existing whitelist and re-index with `array_values()`: Omeka serialises these
  as JSON arrays, and a gapped key list becomes a JSON object instead.
- `uninstall()` and `upgrade()` both call `removeEditorInstallerSettings()`.
  Deleting a key that was never set must not fail.
- Adding a new setting means deciding what `uninstall()` does with it. Leaving
  orphan settings behind is a bug, not a default.

## Wiring in `config/module.config.php`

Every collaborator is constructed by a factory; nothing is `new`-ed inside a
controller. Register in the matching section:

| What | Section |
| --- | --- |
| Controller | `controllers.factories` + a short alias in `controllers.aliases` |
| Service | `service_manager.factories` |
| Form | `form_elements.invokables` |
| Media renderer | `file_renderers.factories` (+ MIME/extension `aliases`) |
| Route | `router.routes` |

Factories are excluded from the coverage requirement, so keep them to wiring
only — a factory with a branch in it is logic that has escaped its test.

Admin routes must be nested under the `admin` route's `child_routes` so Omeka
applies its admin ACL; a top-level route bypasses that entirely.

## Security boundaries

Three rules this module already enforces; keep them intact.

1. **Never expose `/files/exelearning/` or `data/exelearning/` directly.** All
   extracted content is served by `ContentController::serveAction`, which
   validates the 40-hex-character SHA1, rejects `..`, and sets the CSP and
   `X-Content-Type-Options` headers.
2. **State-changing endpoints validate CSRF** via `CsrfValidationTrait`, which
   reads the token from the `csrf` POST field, the `X-CSRF-Token` header, or the
   `csrf` query parameter, and validates it with `Laminas\Validator\Csrf`
   (`name => 'csrf'`). A missing token is a rejection, never a skip.
3. **Authorisation is separate from CSRF.** After the token check, ask the ACL:
   `$acl->userIsAllowed('Omeka\Entity\Media', 'update')`. A valid token proves
   the request came from your page, not that the user may perform the action.

## Base paths and the Playground

URLs are built from the **request URI**, not `getBasePath()`. In the PHP-WASM
playground the prefix (`/playground/{uuid}/php83/`) is present in the request URI
but missing from `$_SERVER['SCRIPT_NAME']`, so `getBasePath()` lies.
`Module::extractBasePath()` derives the prefix by truncating at the first
`/admin/`, `/s/` or `/api/` segment. Any new URL construction must go through the
same helper, and prefer emitting a **relative** content path that the client
resolves against `window.location`.

## Verification

```bash
make lint            # PSR2 over src/, config/, Module.php
make test-coverage   # full suite + the MIN_COVERAGE gate (ADR-0002)
```

Both must pass before a change is done. `make fix` auto-corrects most PSR2
findings.

## Failure modes

- **Route resolves but the controller is "not found"**: the alias in
  `controllers.aliases` and the `controller` value in the route defaults disagree,
  or `__NAMESPACE__` is missing from the route defaults.
- **Service not found at runtime, fine in tests**: registered in the test
  container but not in `service_manager.factories`.
- **Settings vanish after upgrade**: `upgrade()` deleting keys unconditionally
  instead of only the legacy ones.
- **Content 404s only in the playground**: a URL built from `getBasePath()`
  instead of `extractBasePath()`.

## Escalation

Read the Omeka S developer documentation for module structure and events before
inventing a pattern, and check `docs/architecture/adr/records.md` — a durable
decision may already have been made.
