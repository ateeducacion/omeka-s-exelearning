<?php
declare(strict_types=1);

namespace ExeLearning\Controller;

use Laminas\Http\Response as HttpResponse;
use Laminas\Mvc\Controller\AbstractActionController;

/**
 * Host-served opaque HTTP preview — capability serving route (Omeka S adapter).
 *
 * Mirrors the eXeLearning canonical contract in eXe core
 * doc/development/preview-serving-contract.md (the single source of truth). This
 * is the Omeka S implementation of the contract's "serving route (authless
 * capability URL)": it serves the editor preview of UNTRUSTED author HTML/JS in
 * an opaque origin over a real, cookieless capability URL.
 *
 * It is the preview twin of ContentController (the published-content proxy):
 * the same Laminas serving primitive (Response + addHeaderLine + traversal-safe
 * path normalization + 404), the same opaque-origin philosophy, and the same
 * sandbox token set as ExeLearning\Service\IframeSandbox (secure tokens
 * "allow-scripts allow-popups allow-forms"). It differs from ContentController
 * in exactly one way: it resolves bytes from an ephemeral, content-addressed
 * PREVIEW SESSION STORE keyed by an unguessable previewId (server-minted UUID)
 * plus an idle TTL — never from the real filesystem — so a live editor preview
 * never has to be published first.
 *
 * IMPORTANT: self::PREVIEW_SANDBOX_CSP below MUST stay BYTE-IDENTICAL to eXe
 * core's previewCspHeader() (src/shared/security/previewSandbox.ts). Do not
 * reformat, reorder, or "profile" it here; add a drift check that compares it
 * against core.
 *
 * FOLLOW-UP (intentionally out of this skeleton): the PreviewSessionStore
 * (content-addressed, server-side re-hash, atomic manifest swap, per-session +
 * global byte/file caps, idle-TTL sweeper), the authenticated owner-scoped
 * management API (POST /api/exelearning/preview-session ...), the
 * PreviewControllerFactory + route wiring in config/module.config.php, and the
 * PHPUnit coverage in test/ExeLearningTest/Controller/PreviewControllerTest.php.
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

        // 2) Traversal-safe normalization for the exact-key manifest lookup.
        $path = $this->normalizePath($relPath);
        if ($path === null) {
            return $this->preview404();
        }

        // 3) Resolve bytes from the ACTIVE manifest of the ephemeral session
        //    store. The real lookup must touch the idle-TTL clock and return
        //    null for an unknown/expired session; the store is content-addressed
        //    and re-hashes every blob on upload, so this is an exact-key store
        //    read that MUST NOT hit the real filesystem.
        //
        // TODO(follow-up): inject ExeLearning\Service\PreviewSessionStore via a
        // PreviewControllerFactory and replace the stub below with, e.g.:
        //   $file = $this->store->getForServing($previewId)?->activeManifest()?->get($path);
        $file = $this->lookupPreviewFile($previewId, $path);
        if ($file === null) {
            return $this->preview404();
        }

        $mime = $file['mime'] ?? $this->mimeFor($path);

        $response = new HttpResponse();
        $response->setStatusCode(200);
        $headers = $response->getHeaders();
        $this->applyBaseHeaders($headers, $mime);

        // Sandbox-first CSP on EVERY scriptable document type (not just
        // text/html): an author SVG/XML opened top-level would otherwise run its
        // inline script same-origin. nosniff does not help for image/svg+xml.
        if ($this->isScriptableDocument($mime)) {
            $headers->addHeaderLine('Content-Security-Policy', self::PREVIEW_SANDBOX_CSP);
        }

        $response->setContent($file['bytes']);
        return $response;
    }

    /**
     * TODO(follow-up): real content-addressed preview session-store lookup.
     *
     * Must return the active-manifest entry for ($previewId, $path) as
     * ['bytes' => string, 'mime' => string], or null when the session is
     * unknown/expired or the path is not in the active manifest. Until the store
     * lands, this stub returns null, so every capability URL 404s (still with the
     * correct hardening headers) — the safe default.
     *
     * Declared `protected` (not `private`) so the follow-up
     * PreviewControllerFactory can inject a real PreviewSessionStore by
     * subclassing/overriding this single seam, and so unit tests can exercise
     * the serving success path without a live store — mirroring the overridable
     * seams used by the sibling controllers.
     *
     * @param string $previewId Validated capability UUID.
     * @param string $path      Normalized, traversal-safe manifest key.
     * @return array{bytes: string, mime: string}|null
     */
    protected function lookupPreviewFile(string $previewId, string $path): ?array
    {
        // Intentionally unresolved until PreviewSessionStore is implemented.
        return null;
    }

    /**
     * Hardening headers emitted on EVERY preview response, including 404s
     * (verbatim from the canonical contract). There is deliberately NO
     * X-Frame-Options: framing is governed by the CSP frame-ancestors directive,
     * and the opaque preview is meant to be framed by the editor. ACAO:* is safe
     * because the route is authless/cookieless — never pair it with
     * Access-Control-Allow-Credentials.
     *
     * @param \Laminas\Http\Headers $headers
     * @param string $mime
     */
    private function applyBaseHeaders($headers, string $mime): void
    {
        $headers->addHeaderLine('Content-Type', $mime);
        $headers->addHeaderLine('X-Content-Type-Options', 'nosniff');
        $headers->addHeaderLine('Referrer-Policy', 'no-referrer');
        $headers->addHeaderLine('Cache-Control', 'no-store');
        $headers->addHeaderLine(
            'Permissions-Policy',
            'camera=(), microphone=(), geolocation=(), payment=()'
        );
        $headers->addHeaderLine('Access-Control-Allow-Origin', '*');
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
        $this->applyBaseHeaders($response->getHeaders(), 'text/plain');
        $response->setContent('Not found');
        return $response;
    }

    /**
     * Whether a MIME type executes script when opened top-level or framed:
     * text/html, image/svg+xml, application/xml, application/xhtml+xml. This is a
     * superset of ContentController::isActiveDocumentType() — the preview CSP
     * must cover every scriptable type, per the contract.
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
            || strpos($mime, 'application/xhtml+xml') === 0;
    }

    /**
     * Traversal-safe path normalization for the exact-key manifest lookup.
     * Mirrors ContentController::sanitizePath(): strip null bytes, normalize
     * slashes, drop empty/"." segments, reject any "..", default to index.html.
     *
     * @param string $path
     * @return string|null Normalized key, or null if it tries to escape.
     */
    private function normalizePath(string $path): ?string
    {
        $path = urldecode($path);
        $path = str_replace(["\0", '\\'], ['', '/'], $path);

        $parts = [];
        foreach (explode('/', $path) as $seg) {
            if ($seg === '' || $seg === '.') {
                continue;
            }
            if ($seg === '..') {
                return null;
            }
            $parts[] = $seg;
        }

        return $parts === [] ? 'index.html' : implode('/', $parts);
    }

    /**
     * Extension -> MIME fallback. The store record should carry the real,
     * server-computed MIME; this is only used when it does not.
     *
     * @param string $path
     * @return string
     */
    private function mimeFor(string $path): string
    {
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        return self::MIME_TYPES[$ext] ?? 'application/octet-stream';
    }
}
