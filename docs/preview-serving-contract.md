# Preview Serving Contract — Omeka S adapter

This host can serve the **eXeLearning editor preview** of **untrusted author
HTML/JS** over HTTP in an **opaque origin**, per the eXeLearning canonical
contract. It is the Omeka S implementation of the *host-served opaque HTTP
preview*, the same-origin-safe alternative to the editor's `srcdoc` fallback.

- **Canonical source (single source of truth):** eXe core
  `doc/development/preview-serving-contract.md`. Everything below mirrors it; on
  any conflict, eXe core wins.
- **Reference endpoint in this repo:** `src/Controller/PreviewController.php`.

## Why a second serving path

The published-content proxy (`src/Controller/ContentController.php`) already
serves extracted `.elpx` content in an opaque origin with a response-level
`Content-Security-Policy: sandbox …`, reusing the sandbox tokens in
`src/Service/IframeSandbox.php` (secure tokens `allow-scripts allow-popups
allow-forms`). The **preview** needs the same isolation but a different source:
a **live editor buffer**, keyed by an unguessable capability id, that never
has to be published or written under `/files/exelearning/`. `PreviewController`
is the preview twin of `ContentController`: same Laminas serving primitive,
same opaque-origin philosophy, same sandbox token set — different lookup.

## Serving route (authless capability URL)

```
GET  <omeka-base>/exelearning/preview/{previewId}/*
```

- `previewId` **must** match
  `^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$` — anything
  else is a **404**.
- **Authless & cookieless.** The opaque iframe sends no SameSite cookies, so
  this route must not depend on the admin session. It is gated only on the
  unguessable, server-minted `previewId` (a capability URL) plus an **idle
  TTL** — mirroring this host's existing cookieless content proxy.
- The path resolves against the session's **active manifest only** (exact-key
  store lookup); unknown or traversal paths → 404. It never touches the real
  filesystem.

Wire it as a `Regex` route next to `exelearning-content` in
`config/module.config.php` (follow-up):
`'/exelearning/preview/(?<previewId>[0-9a-f-]{36})(?:/(?<file>.*))?'`.

## Required response headers

Emit these on **every** serving response, **including 404s**:

```
X-Content-Type-Options: nosniff
Referrer-Policy: no-referrer
Cache-Control: no-store
Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=()
Access-Control-Allow-Origin: *
Content-Type: <the file's real MIME type>
```

`Access-Control-Allow-Origin: *` is safe because the route is authless and
cookieless; never pair it with `Access-Control-Allow-Credentials`. There is
deliberately **no** `X-Frame-Options` — framing is governed by the CSP
`frame-ancestors` directive below, and the preview is meant to be framed by the
editor.

## Sandbox CSP on every scriptable document type

On **every scriptable document type** — `text/html`, `image/svg+xml`,
`application/xml`, `application/xhtml+xml` (not just HTML) — add this CSP
**verbatim**, so the document stays opaque even when the capability URL is
opened directly (new tab, popup, raw URL):

```
sandbox allow-scripts allow-popups allow-forms; default-src 'self'; script-src 'self' 'unsafe-inline' 'unsafe-eval'; style-src 'self' 'unsafe-inline'; img-src 'self' data: blob: https:; media-src 'self' data: blob: https:; font-src 'self' data:; connect-src 'self'; frame-src 'self' https://www.youtube-nocookie.com https://player.vimeo.com; child-src 'self' https://www.youtube-nocookie.com https://player.vimeo.com; object-src 'none'; base-uri 'none'; form-action 'self'; frame-ancestors 'self';
```

This string is byte-for-byte eXe core's `previewCspHeader()`. Keep it
**byte-identical** — `PreviewController::PREVIEW_SANDBOX_CSP` holds it as a
single literal; do not reformat, reorder, or "profile" it, and add a drift
check against core. An author SVG served without this CSP would execute its
inline `<script>` same-origin when opened top-level; `nosniff` does not help,
because `image/svg+xml` is already a scriptable document type.

## How the editor activates it

The editor selects this transport deterministically from its embedding config —
there is **no silent fallback** to a same-origin document:

```jsonc
{
  "embeddingConfig": {
    "previewTransport": "http",
    "previewBasePath": "<omeka-base>/exelearning"
  }
}
```

The client then issues `{previewBasePath}/preview/{previewId}/*`. **Never** serve
the preview same-origin or via a Service Worker — an SW cannot back an opaque
iframe (its subresources bypass the SW).

## Follow-up (not yet implemented)

This doc + `PreviewController.php` cover the **serving route** contract only.
Still to build, with tests:

- **`PreviewSessionStore`** — content-addressed store: re-hash every blob
  server-side, atomic manifest swap, per-session file/byte caps + global cap +
  idle-TTL sweeper (defaults: 30 min idle, 5000 files, 200 MiB/session, 2 GiB
  global).
- **Management API** (authenticated, owner-scoped, CSRF like `ApiController`),
  under `/api/exelearning/preview-session`: `POST` create → `{previewId,
  limits}`; `POST /:id/manifest` → `{manifestId, missing[], active}`; `POST
  /:id/blobs` (multipart, re-hash, quarantine mismatches) → `{stored[],
  mismatched[], active}`; `DELETE /:id`.
- **Wiring:** `PreviewControllerFactory`, the `Regex` route above, and
  `test/ExeLearningTest/Controller/PreviewControllerTest.php` (≥ 90% coverage).

See also: `src/Controller/ContentController.php`, `src/Service/IframeSandbox.php`.