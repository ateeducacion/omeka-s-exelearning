# Opaque editor preview — Omeka S adapter

The embedded editor renders its preview **filtered** by default: sanitised, with
no author JavaScript running. When the author opts in to running their own code,
the editor needs somewhere to put the real project bytes that is **not** the
Omeka page — a browser-enforced **opaque origin** the content cannot reach out
of.

This module is that somewhere. The editor POSTs the whole project as one ZIP and
gets back an unguessable capability id; the module serves that tree from an
authless route under a sandbox CSP. There is no authored-content `srcdoc`
transport and no Service Worker fallback inside Omeka: missing or invalid
configuration **fails closed** and the filtered preview stays.

## The two endpoints

| | Request | Result |
|---|---|---|
| Management | `POST {origin}/api/exelearning/preview-session` | multipart `snapshot=<zip>`, optional `previewId` → `{previewId}` |
| Management | `DELETE {origin}/api/exelearning/preview-session/{previewId}` | drops the snapshot |
| Serving | `GET {origin}/exelearning/preview/{previewId}/{file}` | the snapshot, authless |

Management is the only authenticated surface: a logged-in identity plus a valid
token from the dedicated, long-lived preview CSRF namespace (`PreviewCsrf`), and
the store scopes every snapshot to its owner (403/404).

Serving carries no authentication at all. The unguessable id plus the idle TTL is
the whole credential, which is what makes the origin opaque — an iframe pointed
at this URL carries no Omeka session, so author code inside it has nothing to
steal.

## Why one whole snapshot

An earlier revision implemented a layered protocol (contract v2): immutable asset
keys uploaded once, incremental document revisions, and a manifest of fixed
installation resources resolved out of the editor distribution — all to avoid
re-uploading unchanged bytes. The editor no longer speaks it; it was handed a
contract nothing read while the one it does read was withheld, which left the
opaque preview unreachable here. One ZIP per refresh replaced the store, the
fixed-resource layer and the four-operation management API.

## Storage

    {sys_get_temp_dir()}/omeka-s-exelearning-preview-{siteKey}/{previewId}/
      meta.json    ownerId
      access       empty marker; its mtime is the idle-TTL clock
      content/     the extracted snapshot

Outside the web root, so no direct web-server path can bypass the serving route
and its sandbox CSP. The site key keeps two Omeka installs on one host from
sharing a capability namespace, and `exelearning.preview_store_path` can point it
elsewhere. Content sits in its own subdirectory so no author path can collide
with the store's own files. A write is staged beside the live tree and swapped
in, so a reader sees the previous snapshot or the new one, never a half-written
one.

## What an archive must survive before extraction

The store does **not** carry its own inspector: `ZipSafety` already guards
uploads in this module and is stricter than a declared-size check would be.

- Unsafe entries (absolute paths, backslashes, schemes, `..` segments) and
  forbidden ones (PHP-capable and server-executable names) reject the **whole**
  archive in a first pass, before anything is written.
- The zip-bomb cap is measured on the **real decompressed bytes** as they stream,
  not on the attacker-controlled sizes declared in the central directory.
- An `index.html` must be present, or it is not a preview.

## Response headers

Every response, 404s included, carries `X-Content-Type-Options: nosniff`,
`Referrer-Policy: no-referrer`, the preview `Permissions-Policy` and
`Access-Control-Allow-Origin: *` — safe here precisely because the route is
authless and cookieless, and never to be paired with credentials. There is
deliberately no `X-Frame-Options`: framing is governed by the CSP
`frame-ancestors` directive.

Every **scriptable** type — `text/html`, `image/svg+xml`, XML, XHTML — also gets
the sandbox CSP, so a capability URL stays opaque even when opened directly. Not
just HTML: an author-supplied SVG runs its inline `<script>` top-level, and
`nosniff` does not help — SVG is already a scriptable type.

Caching is tiered: a scriptable document is `no-store` (it is rewritten on every
refresh), everything else revalidates with an `ETag` and supports single-range
206/416, which is what makes a video inside the snapshot seek without a full
re-download.

The bare capability URL (`…/{previewId}` or `…/{previewId}/`) never serves
`index.html` bytes: it 302s to `…/{previewId}/index.html`, so the opaque iframe's
base URL is the snapshot directory.

## Lifetime

Snapshots expire after 30 idle minutes. Serving one pushes its clock back, so a
preview in use never expires under the author, and every replace sweeps — the
store never depends on a scheduled job to bound its size.

## Client wiring

`EditorController::buildPreviewSnapshotConfig()` and
`view/exelearning/editor-bootstrap.phtml` inject:

```jsonc
"previewSnapshot": {
  "managementUrl":     "{origin}/api/exelearning/preview-session",
  "servingBaseUrl":    "{origin}/exelearning/preview",
  "deleteUrlTemplate": "{origin}/api/exelearning/preview-session/{previewId}",
  "managementHeaders": { "X-CSRF-Token": "…" }
}
```

Every URL derives from the same `serverUrl + basePath` origin as `saveEndpoint`,
so a subdirectory or php-wasm playground install resolves them identically.

## Tests

`PreviewSnapshotStoreTest` covers the store and the archive guards;
`PreviewSessionControllerTest` covers the two management actions, their
identity/CSRF/method guards and owner scoping; `PreviewControllerTest` covers the
serving response contract.
