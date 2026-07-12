# HTTP editor preview — Omeka S adapter

This module implements eXeLearning **Preview Serving Contract v2** for the embedded editor. The canonical protocol, CSP, scriptable MIME set, bridge files, and conformance vectors live in eXeLearning core. This document records the Omeka S mapping and operational requirements.

The authored preview runs in an iframe without `allow-same-origin`. Scriptable responses also receive the sandbox-first CSP, so a capability URL remains opaque when opened directly.

There is no authored-content `srcdoc` transport and no Service Worker fallback inside Omeka. Missing or invalid `previewHttp` configuration fails closed.

## Transport configuration

`EditorController` and `view/exelearning/editor-bootstrap.phtml` inject:

```jsonc
{
  "previewHttp": {
    "protocolVersion": 2,
    "managementBaseUrl": "/api/exelearning/preview-session",
    "servingBaseUrl": "/exelearning/preview",
    "managementHeaders": {
      "X-CSRF-Token": "..."
    }
  }
}
```

The two URL bases intentionally differ. Management requests use `credentials: "same-origin"`; serving requests use `credentials: "omit"` and never receive the CSRF token.

## Endpoint mapping

| Operation | Omeka route | Trust model |
|---|---|---|
| Create session | `POST {managementBaseUrl}` | authenticated, CSRF-protected |
| Upload assets | `POST {managementBaseUrl}/{previewId}/assets` | authenticated, CSRF, owner-scoped |
| Publish revision | `POST {managementBaseUrl}/{previewId}/revisions` | authenticated, CSRF, owner-scoped |
| Delete session | `DELETE {managementBaseUrl}/{previewId}` | authenticated, CSRF, owner-scoped |
| Serve preview | `GET {servingBaseUrl}/{previewId}/{path}` | authless capability URL |

The management controller requires an Omeka identity and a dedicated preview CSRF token. Unknown sessions return `404`; sessions owned by another user return `403`.

The serving route accepts only a server-generated UUID capability and is bounded by TTL and quotas.

## Long-lived CSRF token

Normal Laminas form CSRF containers may have a short absolute lifetime. Preview uses a dedicated namespace with `timeout => null`, preserving one token across a normal editing session and multiple sequential requests.

This is defense in depth. Session ownership is enforced independently.

## Protocol layers

1. **Fixed resources** — official editor resources resolved through `bundles/preview-fixed-resources.json`; never uploaded into a session.
2. **Session assets** — author images, audio, video, PDF, and attachments; immutable per `{assetId}@{hash}` key and uploaded once per session.
3. **Generated documents** — page HTML and generated CSS/JS; only changed files are sent as an atomic revision delta.

Classification is based on provenance. Custom themes, user-installed iDevices, and project-bundled files are never treated as installation-fixed merely because their paths resemble official resources.

## Private storage

The session-store factory defaults to:

```text
{system-temp}/omeka-s-exelearning-preview-{site-hash}
```

The site hash is derived from `OMEKA_PATH`, preventing unrelated installations sharing the same system temporary directory from sharing preview capabilities or budgets.

The default is intentionally outside Omeka's public `files/` tree. Materialized HTML, SVG, XML, CSS, and JavaScript must never gain a direct web-server URL that bypasses `PreviewController` and its sandbox CSP.

A deployment may configure another **private** location:

```php
'exelearning' => [
    'preview_store_path' => '/private/storage/exelearning-preview',
]
```

The configured path must not be publicly served. Apache and nginx deny rules remain useful defense in depth for legacy or custom layouts, but they are no longer the primary security boundary.

## Atomicity and write integrity

Assets are immutable per validated key. Declared size, actual size, per-asset limit, per-session budget, and global budget are checked before indexing.

A failed or short asset write is reported as `write-failed` and is never indexed. A revision is materialized into a new immutable directory; failed document, metadata, rename, or pointer operations abort publication and leave the previous revision active.

The active pointer is read once per serving request, so a reader observes revision N or N+1, never a mixed state. Current and previous revisions are retained briefly for in-flight requests.

The global-budget session scan is amortized once per asset batch rather than once per entry.

## Serving behavior

Resolution order for the active revision:

```text
generated document → session asset reference → fixed-resource reference → 404
```

A bare capability URL redirects with a relative `302` target to `{previewId}/index.html`, preserving the correct base for relative resources.

Range behavior for session assets:

- valid satisfiable single range → `206`;
- valid unsatisfiable single range, including `bytes=-0` → `416`;
- malformed, multi-range, non-`bytes`, or inverted range → ignore and return the full `200` response.

Assets support ETag and `If-None-Match`.

## Response security policy

Every response, including errors, includes:

```text
X-Content-Type-Options: nosniff
Referrer-Policy: no-referrer
Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=()
Access-Control-Allow-Origin: *
```

`Access-Control-Allow-Origin: *` is used only by the cookieless serving route and is never paired with credentials.

HTML, SVG, XML, `text/xml`, and XHTML from every layer receive the byte-identical sandbox-first CSP defined by eXeLearning core. The iframe sandbox omits `allow-same-origin`.

Cache policy is layer-specific:

- generated document: `no-store`;
- session asset: `no-cache`, ETag, optional Range;
- fixed resource: `private, max-age=31536000`;
- error: `no-store`.

## Limits and cleanup

Reference defaults:

- idle TTL: 30 minutes;
- sessions per user: 4;
- files per session: 5000;
- bytes per session: 200 MiB;
- bytes per asset: 128 MiB;
- global store budget: 2 GiB.

Cleanup is opportunistic and TTL-based. An expired or deleted session returns `404`; the core provider recreates the session on the next refresh.

## Activation status

The host adapter is implemented and tested, but the released editor currently bundled by the module predates `HttpPreviewProvider`. Production activation requires a core editor release containing:

```text
HttpPreviewProvider
StaticServiceWorkerPreviewProvider
bundles/preview-fixed-resources.json
```

Until that editor is installed, the released client ignores `previewHttp`. Endpoint and bootstrap tests do not by themselves prove browser activation.

The final integration gate is a browser test using one reproducible static-editor artifact from the target core commit, demonstrating create → assets → revision → opaque capability iframe → incremental update → cleanup.

## Conformance and tests

The Omeka harness replays an exact vendored copy of the core vectors. Additional tests cover:

- identity, CSRF lifetime, and ownership;
- private default store and configured override;
- asset and revision write failures;
- atomic publication;
- CSP on every scriptable MIME;
- traversal and malformed multipart input;
- Range, ETag, bare-root redirect, expiry, and cleanup;
- one global-budget scan per asset batch.

When this document conflicts with the canonical core contract, the core contract wins and the adapter must be updated.