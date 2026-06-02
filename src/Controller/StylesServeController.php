<?php
declare(strict_types=1);

namespace ExeLearning\Controller;

use ExeLearning\Service\StylesService;
use Laminas\Http\Response;
use Laminas\Mvc\Controller\AbstractActionController;

/**
 * Public controller that serves assets from extracted style packages.
 *
 * Route: `/exelearning/styles/:slug[/:file*]`.
 * Refuses any request that would escape the style's extraction dir,
 * that targets a slug not present in the registry, or that resolves
 * to a non-regular file.
 */
class StylesServeController extends AbstractActionController
{
    private StylesService $styles;

    public function __construct(StylesService $styles)
    {
        $this->styles = $styles;
    }

    public function serveAction()
    {
        return $this->serveStyle(
            (string) $this->params('slug', ''),
            (string) $this->params('file', 'style.css')
        );
    }

    /**
     * Resolve a ({slug}, {file}) pair against the registered style dir and
     * write the response. Exposed (not private) so tests can exercise the
     * routing-independent half of the serve path.
     */
    public function serveStyle(string $slug, string $file): Response
    {
        $file = ltrim($file, '/');
        if ($slug === '' || strpos($file, '..') !== false || strpos($slug, '..') !== false) {
            return $this->notFound();
        }

        $registry = $this->styles->getRegistry();
        if (!isset($registry['uploaded'][StylesService::normalizeSlug($slug)])) {
            return $this->notFound();
        }

        $dir = $this->styles->getStyleDir($slug);
        $baseReal = realpath($dir);
        $targetReal = realpath($dir . '/' . $file);
        if ($baseReal === false
            || $targetReal === false
            || strpos($targetReal, $baseReal . DIRECTORY_SEPARATOR) !== 0) {
            return $this->notFound();
        }
        if (!is_file($targetReal) || !is_readable($targetReal)) {
            return $this->notFound();
        }

        $mimeType = $this->guessMimeType($targetReal);

        /** @var Response $response */
        $response = $this->getResponse();
        $response->setStatusCode(200);
        $headers = $response->getHeaders();
        $headers->addHeaderLine('Content-Type', $mimeType);
        $headers->addHeaderLine('Content-Length', (string) filesize($targetReal));
        $headers->addHeaderLine('Cache-Control', 'public, max-age=3600');
        $headers->addHeaderLine('X-Content-Type-Options', 'nosniff');
        $headers->addHeaderLine('X-Frame-Options', 'SAMEORIGIN');

        // Style packages may legitimately ship svg/html/xml/js. Those render as
        // active documents on the Omeka origin, so an admin opening another
        // admin's uploaded file could execute its script. Serve any script
        // capable type with a sandboxed, script-free CSP and as an attachment.
        if ($this->isActiveDocumentType($mimeType)) {
            $headers->addHeaderLine('Content-Security-Policy', implode('; ', [
                "default-src 'none'",
                "style-src 'unsafe-inline'",
                "img-src 'self' data:",
                "script-src 'none'",
                "frame-ancestors 'self'",
                'sandbox',
            ]));
            $headers->addHeaderLine('Content-Disposition', 'attachment');
        }

        $response->setContent(file_get_contents($targetReal));
        return $response;
    }

    /**
     * Whether a MIME type can execute script when opened as a top-level
     * document or framed (HTML, SVG, XML, JavaScript).
     */
    private function isActiveDocumentType(string $mimeType): bool
    {
        foreach (['html', 'svg', 'xml', 'javascript'] as $needle) {
            if (strpos($mimeType, $needle) !== false) {
                return true;
            }
        }
        return false;
    }

    private function notFound(): Response
    {
        $response = $this->getResponse();
        $response->setStatusCode(404);
        $response->setContent('Not found');
        return $response;
    }

    /**
     * Best-effort MIME guess covering the style package's allowed extensions.
     */
    private function guessMimeType(string $path): string
    {
        $map = [
            'css' => 'text/css',
            'js'  => 'application/javascript',
            'map' => 'application/json',
            'json' => 'application/json',
            'xml' => 'application/xml',
            'html' => 'text/html',
            'htm' => 'text/html',
            'md'  => 'text/markdown',
            'txt' => 'text/plain',
            'svg' => 'image/svg+xml',
            'png' => 'image/png',
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            'ico' => 'image/vnd.microsoft.icon',
            'woff' => 'font/woff',
            'woff2' => 'font/woff2',
            'ttf' => 'font/ttf',
            'otf' => 'font/otf',
            'eot' => 'application/vnd.ms-fontobject',
        ];
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        return $map[$ext] ?? 'application/octet-stream';
    }
}
