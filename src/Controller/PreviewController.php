<?php
declare(strict_types=1);

namespace ExeLearning\Controller;

use ExeLearning\Service\PreviewSnapshotStore;
use Laminas\Http\Response as HttpResponse;
use Laminas\Mvc\Controller\AbstractActionController;

/**
 * Host-served opaque HTTP preview — capability serving route (Omeka S adapter).
 *
 * Implements the eXeLearning canonical preview serving contract v2
 * (doc/development/preview-serving-contract.md in eXe core, mirrored in this
 * repo under docs/preview-serving-contract.md). It serves the editor preview of
 * UNTRUSTED author HTML/JS in an opaque origin over a real, cookieless
 * capability URL.
 *
 * It is the preview twin of ContentController (the published-content proxy):
 * the same Laminas serving primitive (Response + addHeaderLine + traversal-safe
 * path normalization + 404), the same opaque-origin philosophy, and the same
 * sandbox token set as ExeLearning\Service\IframeSandbox. It differs in the
 * lookup: bytes resolve from an ephemeral PreviewSnapshotStore keyed by an
 * unguessable previewId (a server-minted UUID) + idle TTL, out of a snapshot
 * directory that lives outside the web root.
 *
 * A requested path is normalized and then confirmed with realpath() to sit
 * inside that snapshot's content directory. Cache-Control is tiered by kind: a
 * scriptable document is no-store (it is rewritten on every refresh), every
 * other file is no-cache + ETag/Range/304. The sandbox-first CSP is emitted on
 * EVERY scriptable document type.
 *
 * IMPORTANT: self::PREVIEW_SANDBOX_CSP MUST stay BYTE-IDENTICAL to eXe core's
 * previewCspHeader() (src/shared/security/previewSandbox.ts). Do not reformat,
 * reorder, or "profile" it; the drift check in PreviewControllerTest asserts it.
 *
 * @license AGPL-3.0
 */
class PreviewController extends AbstractActionController
{
    /** A capability id must be a canonical UUID; anything else is a 404. */
    private const PREVIEW_ID_RE =
        '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';

    /**
     * Sandbox-first CSP, emitted VERBATIM on every scriptable document type so
     * the preview stays opaque even when the capability URL is opened top-level
     * (new tab / popup / raw URL). BYTE-IDENTICAL to eXe core previewCspHeader();
     * kept as a single literal so it can never drift via array/implode edits.
     */
    private const PREVIEW_SANDBOX_CSP =
        "sandbox allow-scripts allow-popups allow-forms; "
        . "default-src 'self'; "
        . "script-src 'self' 'unsafe-inline' 'unsafe-eval'; "
        . "style-src 'self' 'unsafe-inline'; "
        . "img-src 'self' data: blob: https:; "
        . "media-src 'self' data: blob: https:; "
        . "font-src 'self' data:; "
        . "connect-src 'self'; "
        . "frame-src 'self' https://www.youtube-nocookie.com https://player.vimeo.com; "
        . "child-src 'self' https://www.youtube-nocookie.com https://player.vimeo.com; "
        . "object-src 'none'; "
        . "base-uri 'none'; "
        . "form-action 'self'; "
        . "frame-ancestors 'self';";

    /** Minimal extension -> MIME map (mirrors ContentController::$mimeTypes). */
    private const MIME_TYPES = [
        'html' => 'text/html',
        'htm' => 'text/html',
        'xhtml' => 'application/xhtml+xml',
        'xml' => 'application/xml',
        'css' => 'text/css',
        'js' => 'application/javascript',
        'mjs' => 'text/javascript',
        'json' => 'application/json',
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'gif' => 'image/gif',
        'svg' => 'image/svg+xml',
        'webp' => 'image/webp',
        'ico' => 'image/x-icon',
        'woff' => 'font/woff',
        'woff2' => 'font/woff2',
        'ttf' => 'font/ttf',
        'mp3' => 'audio/mpeg',
        'mp4' => 'video/mp4',
        'webm' => 'video/webm',
        'ogg' => 'audio/ogg',
        'pdf' => 'application/pdf',
        'txt' => 'text/plain',
    ];

    /** @var PreviewSnapshotStore|null Snapshot store (null in helper unit tests). */
    private ?PreviewSnapshotStore $store;

    /**
     * @param PreviewSnapshotStore|null $store Injected by the factory in Omeka;
     *                                        null when only the pure helpers are
     *                                        unit-tested.
     */
    public function __construct(?PreviewSnapshotStore $store = null)
    {
        $this->store = $store;
    }

    /**
     * Serve one file of a preview session by capability id.
     *
     * @return HttpResponse
     */
    public function serveAction()
    {
        $previewId = (string) $this->params()->fromRoute('previewId', '');
        $relPath = (string) $this->params()->fromRoute('file', '');

        // 1) Capability-UUID gate. A non-UUID can never name a session -> 404.
        if (!preg_match(self::PREVIEW_ID_RE, $previewId)) {
            return $this->preview404();
        }

        // 1b) The bare capability URL (…/{previewId} or …/{previewId}/) must
        //     never serve index.html bytes: 302 to …/{previewId}/index.html
        //     (contract v2 §4). The decision is driven by the ACTUAL request
        //     path, not the route's file=index.html default, so an explicit
        //     …/{previewId}/index.html still serves 200.
        $redirect = $this->bareCapabilityRedirect($previewId);
        if ($redirect !== null) {
            return $this->previewRedirect($redirect);
        }

        // 2) Traversal-safe normalization for the exact-key layer lookup.
        $path = $this->normalizePath($relPath);
        if ($path === null) {
            return $this->preview404();
        }

        // 3) Three-layer resolution against the active revision only. The store
        //    never lets a client path do filesystem arithmetic; documents/assets
        //    are exact-key reads and the fixed layer resolves through the
        //    server-controlled manifest.
        $file = $this->lookupPreviewFile($previewId, $path);
        if ($file === null) {
            return $this->preview404();
        }

        $mime = $this->contentTypeFor($path);
        $kind = (string) ($file['kind'] ?? 'document');
        $bytes = (string) ($file['bytes'] ?? '');

        if ($kind === 'asset') {
            return $this->serveAsset($bytes, $mime, (string) ($file['etag'] ?? ''));
        }

        // Documents (no-store) and fixed resources (immutable, long-lived).
        $response = new HttpResponse();
        $response->setStatusCode(200);
        $headers = $response->getHeaders();
        $this->applyBaseHeaders($headers, $mime);
        $headers->addHeaderLine(
            'Cache-Control',
            $kind === 'fixed' ? 'private, max-age=31536000' : 'no-store'
        );
        $this->addSandboxCspIfScriptable($headers, $mime);
        $headers->addHeaderLine('Content-Length', (string) strlen($bytes));
        $response->setContent($bytes);
        return $response;
    }

    /**
     * Serve a session project asset (layer 2): revalidating cache tier with an
     * `ETag: "<assetKey>"`, `If-None-Match` -> 304, and single-range 206/416 so
     * large audio/video seek without a full re-download.
     *
     * @param string $bytes
     * @param string $mime
     * @param string $etag  The assetKey (opaque immutable content identity).
     * @return HttpResponse
     */
    private function serveAsset(string $bytes, string $mime, string $etag): HttpResponse
    {
        $response = new HttpResponse();
        $headers = $response->getHeaders();
        $this->applyBaseHeaders($headers, $mime);
        $headers->addHeaderLine('Cache-Control', 'no-cache');
        $headers->addHeaderLine('ETag', '"' . $etag . '"');
        $headers->addHeaderLine('Accept-Ranges', 'bytes');
        // A scriptable asset (an author SVG) opened top-level must stay opaque.
        $this->addSandboxCspIfScriptable($headers, $mime);

        if ($this->ifNoneMatchMatches($this->requestHeader('If-None-Match'), $etag)) {
            $response->setStatusCode(304);
            return $response;
        }

        $total = strlen($bytes);
        $range = $this->parseRange($this->requestHeader('Range'), $total);
        if ($range === 'unsatisfiable') {
            $response->setStatusCode(416);
            $headers->addHeaderLine('Content-Range', 'bytes */' . $total);
            return $response;
        }
        if (is_array($range)) {
            $slice = substr($bytes, $range['start'], $range['end'] - $range['start'] + 1);
            $response->setStatusCode(206);
            $headers->addHeaderLine(
                'Content-Range',
                'bytes ' . $range['start'] . '-' . $range['end'] . '/' . $total
            );
            $headers->addHeaderLine('Content-Length', (string) strlen($slice));
            $response->setContent($slice);
            return $response;
        }

        $response->setStatusCode(200);
        $headers->addHeaderLine('Content-Length', (string) $total);
        $response->setContent($bytes);
        return $response;
    }

    /**
     * Three-layer store lookup for the serving route. Returns a descriptor
     * `['kind' => 'document'|'asset'|'fixed', 'bytes' => string, 'etag'? =>
     * string]`, or null on a miss (unknown/expired session, no active revision,
     * or a path resolving to nothing).
     *
     * Declared `protected` so unit tests can exercise the serving success path
     * without a live store by overriding this single seam.
     *
     * @param string $previewId Validated capability UUID.
     * @param string $path      Normalized, traversal-safe key.
     * @return array{kind: string, bytes: string, etag?: string}|null
     */
    protected function lookupPreviewFile(string $previewId, string $path): ?array
    {
        if ($this->store === null) {
            return null;
        }
        $dir = $this->store->contentDir($previewId);
        if ($dir === null) {
            return null;
        }
        // normalizePath() already refused traversal, but the result is joined to
        // a real directory here and the resolved path confirmed to sit under the
        // snapshot root, so a symlink cannot aim the response outside it.
        $root = realpath($dir);
        $real = realpath($dir . '/' . $path);
        if ($root === false || $real === false || !is_file($real)) {
            return null;
        }
        if (strpos($real, $root . DIRECTORY_SEPARATOR) !== 0) {
            return null;
        }
        $bytes = @file_get_contents($real);
        if ($bytes === false) {
            return null;
        }
        // A scriptable document is rewritten on every opaque refresh, so it is
        // never cached; everything else revalidates with an ETag and supports
        // Range, which is what makes a video inside the snapshot seekable.
        $scriptable = $this->isScriptableDocument($this->contentTypeFor($path));
        return [
            'kind' => $scriptable ? 'document' : 'asset',
            'bytes' => $bytes,
            'etag' => md5($bytes),
        ];
    }

    /**
     * Hardening headers emitted on EVERY preview response, including 404s
     * (verbatim from the canonical contract). Cache-Control is deliberately NOT
     * here — it is tiered per layer by the caller. There is deliberately NO
     * X-Frame-Options: framing is governed by the CSP frame-ancestors directive.
     * ACAO:* is safe because the route is authless/cookieless.
     *
     * @param \Laminas\Http\Headers $headers
     * @param string $mime
     */
    private function applyBaseHeaders($headers, string $mime): void
    {
        $headers->addHeaderLine('Content-Type', $mime);
        $headers->addHeaderLine('X-Content-Type-Options', 'nosniff');
        $headers->addHeaderLine('Referrer-Policy', 'no-referrer');
        $headers->addHeaderLine(
            'Permissions-Policy',
            'camera=(), microphone=(), geolocation=(), payment=()'
        );
        $headers->addHeaderLine('Access-Control-Allow-Origin', '*');
    }

    /**
     * Attach the sandbox-first CSP when the MIME is a scriptable document type.
     * An author SVG/XML opened top-level would otherwise run its inline script
     * same-origin; nosniff does not help for image/svg+xml.
     *
     * @param \Laminas\Http\Headers $headers
     * @param string $mime
     */
    private function addSandboxCspIfScriptable($headers, string $mime): void
    {
        if ($this->isScriptableDocument($mime)) {
            $headers->addHeaderLine('Content-Security-Policy', self::PREVIEW_SANDBOX_CSP);
        }
    }

    /**
     * 404 that still carries the base hardening headers (the contract requires
     * them on EVERY serving response, including misses). text/plain is not a
     * scriptable type, so no CSP is attached.
     *
     * @return HttpResponse
     */
    private function preview404(): HttpResponse
    {
        $response = new HttpResponse();
        $response->setStatusCode(404);
        $headers = $response->getHeaders();
        $this->applyBaseHeaders($headers, 'text/plain');
        $headers->addHeaderLine('Cache-Control', 'no-store');
        $response->setContent('Not found');
        return $response;
    }

    /**
     * If the request targets the bare capability URL (`…/{previewId}` or
     * `…/{previewId}/`, no file component), return the `Location` to redirect to
     * (`…/{previewId}/index.html`, original query string preserved); otherwise
     * null (serve the explicit file).
     *
     * The decision reads the ACTUAL request path — not the `file` route param,
     * which the route defaults to `index.html` and so cannot distinguish a bare
     * URL from an explicit `/index.html`. When the request exposes no URI, or the
     * previewId is absent from its path (e.g. a bare unit-test stub), it returns
     * null: never redirect on uncertainty.
     *
     * @param string $previewId Validated capability UUID.
     * @return string|null
     */
    private function bareCapabilityRedirect(string $previewId): ?string
    {
        $request = $this->getRequest();
        if (!method_exists($request, 'getUri')) {
            return null;
        }
        $uri = $request->getUri();
        if (!is_object($uri) || !method_exists($uri, 'getPath')) {
            return null;
        }
        $path = (string) $uri->getPath();
        $pos = strpos($path, $previewId);
        if ($pos === false) {
            return null;
        }
        $rest = substr($path, $pos + strlen($previewId));
        if ($rest !== '' && $rest !== '/') {
            return null; // an explicit file follows — serve it, don't redirect.
        }
        // A RELATIVE Location (base-path / origin / app:// safe) resolved against
        // the bare request URL: with no trailing slash the browser replaces the
        // last segment ({previewId}) → `{previewId}/index.html`; with a trailing
        // slash it appends → `index.html`.
        $location = $rest === '/' ? 'index.html' : $previewId . '/index.html';
        if (method_exists($uri, 'getQuery')) {
            $query = $uri->getQuery();
            if (is_string($query) && $query !== '') {
                $location .= '?' . $query;
            }
        }
        return $location;
    }

    /**
     * A 302 to `$location` carrying the base hardening headers the contract
     * requires on every serving response. text/plain, no CSP, `no-store` — the
     * bare capability URL never emits document bytes.
     *
     * @param string $location
     * @return HttpResponse
     */
    private function previewRedirect(string $location): HttpResponse
    {
        $response = new HttpResponse();
        $response->setStatusCode(302);
        $headers = $response->getHeaders();
        $this->applyBaseHeaders($headers, 'text/plain');
        $headers->addHeaderLine('Cache-Control', 'no-store');
        $headers->addHeaderLine('Location', $location);
        return $response;
    }

    /**
     * Whether a MIME type executes script when opened top-level or framed:
     * text/html, image/svg+xml, application/xml, text/xml,
     * application/xhtml+xml. Mirrors eXe core isScriptableDocumentType().
     *
     * @param string $mime
     * @return bool
     */
    private function isScriptableDocument(string $mime): bool
    {
        $mime = strtolower($mime);
        return strpos($mime, 'text/html') === 0
            || strpos($mime, 'image/svg+xml') === 0
            || strpos($mime, 'application/xml') === 0
            || strpos($mime, 'text/xml') === 0
            || strpos($mime, 'application/xhtml+xml') === 0;
    }

    /**
     * Traversal-safe path normalization for the exact-key layer lookup.
     * Delegates to the single source of truth on PreviewSnapshotStore so the
     * serving route and the revision validator normalize identically.
     *
     * @param string $path
     * @return string|null Normalized key, or null if it tries to escape.
     */
    private function normalizePath(string $path): ?string
    {
        return PreviewSnapshotStore::normalizePath($path);
    }

    /**
     * Resolve the served Content-Type for a path, appending a UTF-8 charset to
     * textual types (mirrors eXe core contentTypeFor) so responses paired with
     * nosniff stay strict and readable.
     *
     * @param string $path
     * @return string
     */
    private function contentTypeFor(string $path): string
    {
        $mime = $this->mimeFor($path);
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $isTextual = strpos($mime, 'text/') === 0
            || in_array($ext, ['js', 'mjs', 'json', 'svg', 'xml'], true);
        if ($isTextual && strpos($mime, 'charset') === false) {
            $mime .= '; charset=utf-8';
        }
        return $mime;
    }

    /**
     * Extension -> base MIME fallback.
     *
     * @param string $path
     * @return string
     */
    private function mimeFor(string $path): string
    {
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        return self::MIME_TYPES[$ext] ?? 'application/octet-stream';
    }

    /**
     * Read a request header value, tolerating a stub/absent request object.
     *
     * @param string $name
     * @return string|null
     */
    private function requestHeader(string $name): ?string
    {
        $request = $this->getRequest();
        if (!method_exists($request, 'getHeaders')) {
            return null;
        }
        $headers = $request->getHeaders();
        if (!$headers || !method_exists($headers, 'get')) {
            return null;
        }
        $header = $headers->get($name);
        if (!$header || !method_exists($header, 'getFieldValue')) {
            return null;
        }
        return $header->getFieldValue();
    }

    /**
     * Loose If-None-Match evaluation: any listed entity tag (or `*`) matches.
     *
     * @param string|null $headerValue
     * @param string $etag
     * @return bool
     */
    private function ifNoneMatchMatches(?string $headerValue, string $etag): bool
    {
        if ($headerValue === null || $headerValue === '') {
            return false;
        }
        foreach (explode(',', $headerValue) as $candidate) {
            $cleaned = trim($candidate);
            $cleaned = (string) preg_replace('/^W\//i', '', $cleaned);
            $cleaned = trim($cleaned, '"');
            if ($cleaned === '*' || $cleaned === $etag) {
                return true;
            }
        }
        return false;
    }

    /**
     * Parse a single-range `Range` header against a body of `$total` bytes.
     * Per serving contract v2 §4:
     *  - `null` when the header is absent, OR malformed / multi-range / a
     *    non-`bytes` unit — a Range the server does not understand is IGNORED and
     *    answered with a normal `200` full body, not `416`;
     *  - `'unsatisfiable'` for a SYNTACTICALLY VALID single range that cannot be
     *    met (a `first-byte-pos` at/after the end, or a zero-length suffix) ->
     *    `416`;
     *  - an inclusive `{start, end}` window when satisfiable -> `206`.
     *
     * @param string|null $value
     * @param int $total
     * @return array{start: int, end: int}|string|null
     */
    private function parseRange(?string $value, int $total)
    {
        if ($value === null || $value === '') {
            return null;
        }
        $value = trim($value);

        // Only the "bytes" unit is supported; any other unit is ignored (200).
        if (strncasecmp($value, 'bytes=', 6) !== 0) {
            return null;
        }
        $spec = substr($value, 6);

        // Multiple ranges are unsupported; ignore the whole header (200).
        if (strpos($spec, ',') !== false) {
            return null;
        }
        // A single byte-range-spec: first-byte-pos / last-byte-pos, both digits,
        // at least one present. Anything else is malformed -> ignore (200).
        if (!preg_match('/^(\d*)-(\d*)$/', $spec, $matches)) {
            return null;
        }
        $rawStart = $matches[1];
        $rawEnd = $matches[2];
        if ($rawStart === '' && $rawEnd === '') {
            return null;
        }

        if ($rawStart === '') {
            // Suffix range (last N bytes). A zero-length suffix is a valid but
            // unsatisfiable spec.
            $suffix = (int) $rawEnd;
            if ($suffix === 0 || $total === 0) {
                return 'unsatisfiable';
            }
            return ['start' => max(0, $total - $suffix), 'end' => $total - 1];
        }

        $start = (int) $rawStart;
        if ($rawEnd === '') {
            if ($start >= $total) {
                return 'unsatisfiable';
            }
            return ['start' => $start, 'end' => $total - 1];
        }

        $end = (int) $rawEnd;
        if ($end < $start) {
            // last-byte-pos < first-byte-pos is an INVALID spec (RFC 9110
            // §14.1.2), not merely unsatisfiable -> ignore the header (200).
            return null;
        }
        if ($start >= $total) {
            return 'unsatisfiable';
        }
        return ['start' => $start, 'end' => min($end, $total - 1)];
    }
}
