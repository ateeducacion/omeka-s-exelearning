# Simplified opaque preview snapshots

The embedded eXeLearning editor keeps its normal in-browser preview generation.
When it runs inside Omeka S, the final generated file set is uploaded as one ZIP
snapshot so the preview can load from a real URL while remaining isolated from
the authenticated host.

This adapter intentionally does not implement the earlier protocol-v2 design.
There are no fixed/session/generated layers, manifests, blob negotiation,
incremental revisions, conflict recovery, or external-media overlays.

## Contract

The editor receives this host-native configuration:

```json
{
  "previewSnapshot": {
    "managementUrl": "/api/exelearning/preview-session/{mediaId}",
    "servingBaseUrl": "/exelearning/preview/",
    "managementHeaders": {
      "X-CSRF-Token": "<session-bound token>"
    }
  }
}
```

On refresh, the core creates a complete ZIP and sends it as the multipart field
`snapshot`. The first `POST` creates a server-minted UUIDv4 capability. Later
uploads include `previewId` and atomically replace that capability's complete
snapshot. `DELETE .../{previewId}` removes it when the project closes.

The editor loads:

```text
/exelearning/preview/{previewId}/index.html
```

in an iframe sandbox that includes scripts, forms, popups, downloads, and
presentation support, but never `allow-same-origin`. There is no fallback to a
same-origin preview if snapshot creation fails.

## Security boundaries

The management route requires all of the following:

- an authenticated Omeka identity;
- the session-bound CSRF token;
- a real media record;
- update permission for Omeka media;
- matching user and media ownership metadata when replacing or deleting a
  capability.

The serving route deliberately does not consult the Omeka session. Its only
authority is an unguessable UUIDv4 capability with a 30-minute idle lifetime.
Snapshots live under the system temporary directory, outside Omeka's public
file tree. Reads refresh the idle lifetime and ordinary traffic opportunistically
removes expired capabilities.

Uploaded archives are limited to 5,000 entries and 100 MiB uncompressed. They
must contain a root `index.html`. Absolute paths, traversal, non-canonical path
segments, backslashes, reserved metadata names, and symbolic links are rejected
before extraction. Replacement uses a staging directory and rename so readers
never observe a partially updated snapshot.

Every response uses `nosniff`, `no-referrer`, `no-store`, a restrictive
Permissions Policy, and a validated MIME type. HTML, SVG, XML, and XHTML also
receive a response-level sandbox CSP without `allow-same-origin`. The CSP allows
HTTPS images and media plus the known YouTube and Vimeo players because real
eXeLearning projects use those resources; opaque origin isolation does not
claim to prevent all network access or social-engineering behaviour.

## Trust-model distinction

This contract applies only to the editor embedded in Omeka S. Published Omeka
content and a normal, non-embedded eXeLearning editor have separate trust
models. In particular, source-aware filtering in the normal editor preview is
not equivalent to this opaque-origin boundary, and enabling custom active
content in the normal editor remains an explicit trust decision.
