# Preview Serving Contract v2 — Omeka S adapter

This module implements the eXeLearning **editor preview serving contract v2**: it
serves the editor preview of **untrusted author HTML/JS** over HTTP in an
**opaque origin**, keyed by an unguessable capability id, without ever publishing
the content under `/files/exelearning/`. It is the host-served opaque transport
the **embedded** editor requires: embedded editors never fall back to `srcdoc`
(it is removed as an authored-content transport) — without a valid `previewHttp`
config the editor fails closed with a clear error, never a same-origin document.

- **Canonical source (single source of truth):** eXe core
  `doc/development/preview-serving-contract.md`. Everything below mirrors it; on
  any conflict, eXe core wins.
- **Protocol version: 2.** A create-session response without
  `protocolVersion: 2` must make the client surface an error (no silent
  fallback). v1 (full SHA-256 manifest + content-addressed blob diff) is removed.
- **Conformance vectors:** `test/fixtures/preview-contract/vectors.json`
  (vendored verbatim from eXe core), replayed in
  `test/ExeLearningTest/Controller/PreviewContractVectorsTest.php`.

## Why a second serving path

The published-content proxy (`src/Controller/ContentController.php`) already
serves extracted `.elpx` content in an opaque origin with a response-level
`Content-Security-Policy: sandbox …`, reusing the sandbox tokens in
`src/Service/IframeSandbox.php` (secure tokens `allow-scripts allow-popups
allow-forms`). The **preview** needs the same isolation but a different source:
a **live editor buffer**, keyed by an unguessable capability id, that never has
to be published. `PreviewController` is the preview twin of `ContentController`:
same Laminas serving primitive, same opaque-origin philosophy, same sandbox
token set — different lookup.

## The three layers

A refresh costs `O(invalidated documents + new assets)` because the preview is
split into three layers with different lifecycles:

| Layer | Contents | Lifecycle | Transferred |
|---|---|---|---|
| **Fixed installation resources** | official libraries, base iDevice runtimes, base theme files, PDF.js, content CSS, logo, fonts | immutable per installed editor version | **never** — served from the installed static editor, gated by a build manifest |
| **Session project assets** | author images/audio/video/PDF/attachments | immutable per `assetKey`; whole session | **once per session** (again only if replaced → new key) |
| **Generated documents** | page HTML, navigation, generated CSS/JS, user themes/iDevices | change with every edit | **only the changed files**, as an atomic revision delta |

Classification is by **provenance, not by name**: a file is *fixed* only when the
client resolved it from an installation-immutable source. Custom themes,
user-installed iDevices, and anything embedded in an `.elpx` ride the session
layers.

## A. Management API (authenticated, owner-scoped)

`PreviewSessionController` — the **only** authenticated surface. Every action
requires a logged-in identity and a valid CSRF token (`CsrfValidationTrait`, the
same mandatory check as `ApiController`); the store enforces owner scoping
(403/404).

| Method & path | Body | Success |
|---|---|---|
| `POST /api/exelearning/preview-session` | – | `201` `{ previewId, protocolVersion: 2, revision: 0, limits }` |
| `POST /api/exelearning/preview-session/:id/assets` | multipart: `assets` (JSON `[{ key, size }]`), `files[]` (index-aligned) | `200` `{ stored[], alreadyStored[], rejected[] }` |
| `POST /api/exelearning/preview-session/:id/revisions` | multipart: `revision` (JSON, below), `files[]` (index-aligned with `writes`) | `200` `{ revision, active: true }` |
| `DELETE /api/exelearning/preview-session/:id` | – | `200` `{ success: true }` |

**Assets.** `key` must match `^[0-9a-fA-F-]{36}@[0-9a-f]{8,64}$` — the asset id
plus a content-hash prefix the editor already stores; the server treats it as an
opaque validated token and **never hashes**. Keys are **immutable**: re-uploading
an existing key does not replace bytes — it is reported in `alreadyStored`. A
replaced author file gets a new key (its content hash changed). Declared vs
actual byte size is enforced (a mismatch rejects that entry).

**Revisions.** The `revision` JSON:

```jsonc
{
  "baseRevision": 17,        // the revision the client believes is active
  "nextRevision": 18,        // must be baseRevision + 1
  "writes": ["index.html"],  // aligned with files[]
  "deletes": ["html/old.html"],
  "assetRefs": { "content/resources/photo.png": "<uuid>@<hash>" }, // FULL map
  "fixedRefs": { "libs/jquery/jquery.min.js": "libs/jquery/jquery.min.js" } // FULL map
}
```

`writes`/`deletes` are deltas over the document set; `assetRefs`/`fixedRefs` are
full replacement maps. Validation order: session exists (`404`) → `baseRevision`
equals the active revision **and** `nextRevision == baseRevision + 1`, else `409`
`{ reason: "revision-conflict", currentRevision }` → every path normalized/safe,
else `400` → every `assetRefs` value exists in the session asset store, else
`422` `{ reason: "missing-assets", missing }` → every `fixedRefs` value exists in
the manifest, else `422` `{ reason: "unknown-fixed-resources", resources }` →
file-count / byte budgets, else `413`.

## B. Serving route (authless capability URL)

```
GET  <omeka-base>/exelearning/preview/{previewId}/{path}
```

`PreviewController::serveAction`. Gated **only** on the unguessable, server-minted
`previewId` (matching `^[0-9a-f]{8}-…-[0-9a-f]{12}$`; anything else → 404) plus an
idle TTL — the opaque iframe sends no SameSite cookies, so this route must not
depend on the admin session. **Resolution order** (exact-key lookups against the
active revision only):

```
1. documents[path]              → generated document bytes
2. assets[assetRefs[path]]      → session project asset bytes
3. manifest[fixedRefs[path]]    → fixed installation file
4. 404
```

The path never does filesystem arithmetic: documents/assets are exact-key reads,
and only the server-controlled manifest path reaches the filesystem (with
containment checks) for the fixed layer.

- **Bare capability URL.** A `GET` to `…/{previewId}` or `…/{previewId}/` (no
  file) **302-redirects** to `…/{previewId}/index.html` (query string
  preserved); it never serves `index.html` bytes at the bare URL. The redirect
  is driven by the actual request path, so an explicit `…/index.html` still
  serves `200`.
- **Range requests.** Session-asset responses advertise `Accept-Ranges: bytes`.
  A single **satisfiable** range → `206`; a syntactically valid but
  **unsatisfiable** single range (start at/after the end, or a zero-length
  suffix) → `416`; a **malformed / multi-range / non-`bytes`** Range is ignored
  and answered with a normal `200` full body (a Range the server cannot
  interpret is not an error).
- **Conditional requests.** Session-asset responses carry `ETag: "<assetKey>"`
  and honor `If-None-Match` with `304`.

### Required response headers (every response, including 404s)

```
X-Content-Type-Options: nosniff
Referrer-Policy: no-referrer
Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=()
Access-Control-Allow-Origin: *
Content-Type: <the file's real MIME type>
```

`Access-Control-Allow-Origin: *` is safe because the route is authless and
cookieless; never pair it with `Access-Control-Allow-Credentials`. There is
deliberately **no** `X-Frame-Options` — framing is governed by CSP
`frame-ancestors`.

`Cache-Control` is **tiered by layer**:

| Response | Cache-Control |
|---|---|
| Generated document (layer 3) | `no-store` |
| Session project asset (layer 2) | `no-cache` (+ `ETag`, `If-None-Match` → 304) |
| Fixed installation resource (layer 1) | `private, max-age=31536000` |
| 404 / errors | `no-store` |

### Sandbox CSP on every scriptable document type

On **every scriptable document type** — `text/html`, `image/svg+xml`,
`application/xml`, `application/xhtml+xml` (not just HTML) — from **any** layer,
add this CSP **verbatim**, so the document stays opaque even when the capability
URL is opened directly (new tab, popup, raw URL):

```
sandbox allow-scripts allow-popups allow-forms; default-src 'self'; script-src 'self' 'unsafe-inline' 'unsafe-eval'; style-src 'self' 'unsafe-inline'; img-src 'self' data: blob: https:; media-src 'self' data: blob: https:; font-src 'self' data:; connect-src 'self'; frame-src 'self' https://www.youtube-nocookie.com https://player.vimeo.com; child-src 'self' https://www.youtube-nocookie.com https://player.vimeo.com; object-src 'none'; base-uri 'none'; form-action 'self'; frame-ancestors 'self';
```

This string is byte-for-byte eXe core's `previewCspHeader()`. Keep it
**byte-identical** — `PreviewController::PREVIEW_SANDBOX_CSP` holds it as a single
literal, and `PreviewControllerTest` asserts it against an independent copy so a
silent reformat/reorder fails CI. An author SVG served without this CSP would
execute its inline `<script>` same-origin when opened top-level; `nosniff` does
not help, because `image/svg+xml` is already a scriptable document type.

## The fixed-resource manifest

The build emits `bundles/preview-fixed-resources.json` **inside the installed
static editor distribution** (`StaticEditorInstaller::getEditorPath()` →
`{module}/dist/static`), so `PreviewFixedResources` resolves it at
`{dist/static}/bundles/preview-fixed-resources.json`:

```jsonc
{
  "schemaVersion": 1,
  "buildVersion": "<editor version>",
  "resources": {
    // fixedResourceId → { path (relative to dist/static), size }
    "libs/jquery/jquery.min.js": { "path": "libs/jquery/jquery.min.js", "size": 89476 }
  }
}
```

- `fixedResourceId` is an opaque key resolved by **exact map lookup** — never by
  path arithmetic on client input. The file at `resources[id].path` is served
  under the distribution root with `..`/symlink containment checks.
- An **absent or invalid manifest disables the fixed layer** (never fatal): every
  id then misses, so a revision carrying `fixedRefs` gets a `422` and the client
  demotes those paths to document writes.

## The session store

`PreviewSessionStore` is file-backed (PHP is request-scoped — an in-memory map
does not survive requests). Sessions live under the Omeka file store in
`exelearning-preview/{previewId}/`:

- `assets/` — asset bytes, immutable per key;
- `rev/{n}/documents/` — a full self-contained document snapshot for revision `n`
  (unchanged files are hardlinked from the previous revision);
- `rev/{n}/revision.json` — that revision's full `assetRefs`/`fixedRefs` maps;
- `current` — the active-revision pointer.

**Atomic publish.** The whole `applyRevision` runs under an exclusive file lock:
the incoming revision materializes into a fresh `rev/{n}` directory, then the
`current` pointer is swapped with an atomic `rename`. A concurrent `GET` reads the
pointer once and observes revision *N* or *N+1*, never a mixture. Until the first
revision publishes, the serving route 404s.

**Never web-servable.** The store lives under the Omeka file store, which the web
server serves directly. `PreviewSessionStore` writes an Apache deny guard
(`.htaccess` + `index.php` stub) into `{file_store}/exelearning-preview/`, but
**nginx and other non-Apache servers ignore it**. Those deployments **MUST** deny
direct web access to `{file_store}/exelearning-preview/` (the shipped
`data/nginx-exelearning.conf` and `docker/nginx-exelearning.conf` samples include
the `location ^~ /files/exelearning-preview/ { return 403; }` rule — note the
`-preview/` prefix is *not* covered by the existing `/files/exelearning/` rule).
Without this, a direct `GET` to a materialized document would serve untrusted
author HTML same-origin **without** the sandbox CSP — a second, un-sandboxed
serving path that defeats the opaque-origin isolation.

## Budgets & TTL (DoS bounds)

Enforced by the store (reference defaults, overridable per instance): 30-minute
idle TTL with an opportunistic sweep on access, 4 sessions/user (LRU-evicted at
the cap), 5000 files/session, 200 MiB/session, 128 MiB/asset, 2 GiB global (LRU
eviction of other sessions). Sessions are ephemeral and reclaimed automatically;
they are never a durable store.

## How the editor activates it

`EditorController::editAction` injects a normalized `previewHttp` block into
`window.__EXE_EMBEDDING_CONFIG__` (serving contract v2 §1). Omeka's management
and serving routes live under **two distinct top-level prefixes**
(`/api/exelearning/…` vs `/exelearning/preview`), which a single `previewBasePath`
could not express — so the config carries **both** URLs explicitly:

```jsonc
{
  "previewHttp": {
    "protocolVersion": 2,
    "managementBaseUrl": "<omeka-base>/api/exelearning/preview-session",
    "servingBaseUrl": "<omeka-base>/exelearning/preview",
    "managementHeaders": { "X-CSRF-Token": "<preview-scoped Laminas CSRF token>" }
  }
}
```

Both URLs are derived from the same `serverUrl + basePath` origin as
`saveEndpoint`, so a subdirectory or php-wasm playground install resolves them
identically. The editor selects the `HttpPreviewProvider` deterministically when
`previewHttp` is present and valid; when it is **absent or invalid** the editor
fails closed with a clear error — there is **no silent fallback** to a
same-origin document, and **never** a Service Worker (an SW cannot back an opaque
iframe: its subresources bypass the SW).

`managementHeaders['X-CSRF-Token']` carries a **preview-scoped, long-lived**
Laminas CSRF token (`PreviewCsrf`, namespace `exelearning-preview`,
`timeout => null`), **not** the default 300 s form token. The default token is
not single-use (`Csrf::isValid()` is a pure comparison), but it stamps an
absolute, container-global 5-minute expiry that is never refreshed on validation,
so a token minted at editor load would 403 every publish after ~5 minutes of
editing. The dedicated namespace isolates the preview token from the save token's
expiry so a long editing session keeps previewing.

> **Editor-build dependency.** These endpoints are dormant until the installed
> static editor build ships the `HttpPreviewProvider` and its
> `bundles/preview-fixed-resources.json` manifest. An older editor build that
> still expects `srcdoc`/`previewBasePath` will not consume `previewHttp`; update
> the editor (module config → *Update to Latest Version*) to activate the opaque
> HTTP preview.

## Static/PWA standalone and the php-wasm escape hatch

`srcdoc` is **removed** as an authored-content transport. A pure serverless
static/PWA standalone editor keeps the existing **Service Worker** preview,
gated as `static-service-worker` (`opaqueSafe = false`) — a *trusted-content*
compatibility mode, **not** a security boundary, and never auto-selected in an
embedded context.

The php-wasm **Playground** (the whole CMS emulated by a Service Worker) cannot
serve opaque content at all, so its **published-content** demos fall back to the
dev-only `EXELEARNING_UNSAFE_LEGACY_IFRAME` hatch
(`IframeSandbox::isUnsafeLegacy()`, `blueprint.json`) — off by default, never a
UI/admin setting. The **editor preview** inside the playground has no such hatch:
with `srcdoc` gone and no real HTTP origin behind the SW, it **fails closed with
a clear error**. That is the intended dev-only behavior — previewing authored
content in the playground editor requires a real server-backed deployment.

See also: `src/Controller/ContentController.php`,
`src/Service/IframeSandbox.php`, and eXe core
`doc/development/preview-serving-contract.md`.
