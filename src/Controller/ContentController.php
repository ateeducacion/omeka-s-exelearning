<?php
declare(strict_types=1);

namespace ExeLearning\Controller;

use Laminas\Mvc\Controller\AbstractActionController;
use Laminas\Http\Response as HttpResponse;

/**
 * Secure content delivery controller for eXeLearning files.
 *
 * Serves extracted eXeLearning content with security headers to prevent:
 * - XSS attacks via malicious content
 * - Clickjacking
 * - Data exfiltration
 */
class ContentController extends AbstractActionController
{
    /** @var string */
    protected $basePath;

    /** @var string Iframe-security mode (secure|legacy); drives the response sandbox CSP. */
    protected $iframeMode = \ExeLearning\Service\IframeSandbox::MODE_SECURE;

    /** @var array MIME types for common file extensions */
    protected $mimeTypes = [
        'html' => 'text/html',
        'htm' => 'text/html',
        'css' => 'text/css',
        'js' => 'application/javascript',
        'json' => 'application/json',
        'xml' => 'application/xml',
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
        'eot' => 'application/vnd.ms-fontobject',
        'mp3' => 'audio/mpeg',
        'mp4' => 'video/mp4',
        'webm' => 'video/webm',
        'ogg' => 'audio/ogg',
        'ogv' => 'video/ogg',
        'pdf' => 'application/pdf',
        'zip' => 'application/zip',
        'txt' => 'text/plain',
    ];

    /**
     * @param string $basePath   Path to the exelearning files directory
     * @param string $iframeMode Iframe-security mode (secure|legacy)
     */
    public function __construct(string $basePath, string $iframeMode = \ExeLearning\Service\IframeSandbox::MODE_SECURE)
    {
        $this->basePath = $basePath;
        $this->iframeMode = \ExeLearning\Service\IframeSandbox::normalizeMode($iframeMode);
    }

    /**
     * Serve a file from the extracted eXeLearning content.
     *
     * @return HttpResponse|ViewModel
     *
     * @codeCoverageIgnore
     */
    public function serveAction()
    {
        $hash = $this->params()->fromRoute('hash');
        $file = $this->params()->fromRoute('file');

        // Validate hash format (SHA1 = 40 hex characters)
        if (!$hash || !preg_match('/^[a-f0-9]{40}$/i', $hash)) {
            return $this->notFound('Invalid content identifier');
        }

        // Validate and sanitize file path
        if (!$file) {
            $file = 'index.html';
        }

        // Prevent directory traversal attacks
        $file = $this->sanitizePath($file);
        if ($file === null) {
            return $this->notFound('Invalid file path');
        }

        // Build full file path
        $fullPath = $this->basePath . '/' . $hash . '/' . $file;

        // Check file exists
        if (!file_exists($fullPath) || !is_file($fullPath)) {
            return $this->notFound('File not found');
        }

        // Check file is within the expected directory (double-check against symlinks)
        $realPath = realpath($fullPath);
        $realBasePath = realpath($this->basePath . '/' . $hash);
        if ($realPath === false || $realBasePath === false ||
            strpos($realPath, $realBasePath) !== 0) {
            return $this->notFound('Access denied');
        }

        // Get file info
        $extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        $mimeType = $this->mimeTypes[$extension] ?? 'application/octet-stream';

        $teacherModeVisibleParam = strtolower((string) $this->params()->fromQuery('teacher_mode_visible', '1'));
        $teacherModeVisible = !in_array($teacherModeVisibleParam, ['0', 'false', 'no'], true);


        // Create response
        $response = new HttpResponse();
        $response->setStatusCode(200);

        // Set content headers
        $headers = $response->getHeaders();
        $headers->addHeaderLine('Content-Type', $mimeType);

        // Security headers
        $this->addSecurityHeaders($headers, $mimeType);

        // Cache headers (content is static)
        $headers->addHeaderLine('Cache-Control', 'public, max-age=3600');

        // Read and return file content
        $content = file_get_contents($fullPath);
        if ($content === false) {
            return $this->notFound('File not found');
        }

        if (strpos($mimeType, 'text/html') !== false && !$teacherModeVisible) {
            $content = $this->injectTeacherModeCss($content);
        }

        $headers->addHeaderLine('Content-Length', (string) strlen($content));
        $response->setContent($content);

        return $response;
    }

    /**
     * Add security headers based on content type.
     *
     * @param \Laminas\Http\Headers $headers
     * @param string $mimeType
     */
    protected function addSecurityHeaders($headers, string $mimeType): void
    {
        // Prevent clickjacking - only allow embedding from same origin
        $headers->addHeaderLine('X-Frame-Options', 'SAMEORIGIN');

        // Prevent MIME type sniffing
        $headers->addHeaderLine('X-Content-Type-Options', 'nosniff');

        // For HTML content, add strict Content-Security-Policy
        if (strpos($mimeType, 'text/html') !== false) {
            // CSP that:
            // - Allows inline styles and scripts (needed for eXeLearning content)
            // - Restricts where content can be loaded from
            // - Prevents the content from framing other sites
            // - Allows images/media from same origin and data URIs
            $directives = [
                "default-src 'self'",
                "script-src 'self' 'unsafe-inline' 'unsafe-eval'",
                "style-src 'self' 'unsafe-inline'",
                "img-src 'self' data: blob:",
                "media-src 'self' data: blob:",
                "font-src 'self' data:",
                "connect-src 'self'",
                "frame-src 'self'",
                "frame-ancestors 'self'",
                "form-action 'none'",
                "base-uri 'self'",
            ];
            // In secure mode, sandbox the document at the response level so it keeps an
            // opaque origin even when loaded OUTSIDE the embedding iframe (opened in a new
            // tab, via an escaped popup, or by navigating to the raw content URL). Without
            // this, that top-level document would run author JS as the Omeka origin. The
            // tokens mirror the secure iframe sandbox (scripts + popups, no same-origin).
            if (\ExeLearning\Service\IframeSandbox::MODE_SECURE === $this->iframeMode) {
                $directives[] = 'sandbox allow-scripts allow-popups';
            }
            $headers->addHeaderLine('Content-Security-Policy', implode('; ', $directives));

            // Referrer policy - don't leak info to external sites
            $headers->addHeaderLine('Referrer-Policy', 'same-origin');

            // Permissions policy - disable dangerous features
            $headers->addHeaderLine(
                'Permissions-Policy',
                'geolocation=(), microphone=(), camera=(), payment=()'
            );
        } elseif ($this->isActiveDocumentType($mimeType)) {
            // SVG/XML can carry inline <script> and are served same-origin from
            // an attacker-controlled .elpx. A bitmap loaded via <img> ignores
            // these directives, but a victim navigating directly to the file
            // (or framing it) would otherwise execute its scripts in the Omeka
            // origin. Neutralize with a script-free, sandboxed CSP.
            $headers->addHeaderLine('Content-Security-Policy', implode('; ', [
                "default-src 'none'",
                "style-src 'unsafe-inline'",
                "img-src 'self' data:",
                "script-src 'none'",
                "frame-ancestors 'self'",
                'sandbox',
            ]));
            $headers->addHeaderLine('Referrer-Policy', 'same-origin');
        }
    }

    /**
     * Whether a served MIME type can execute script when treated as a
     * top-level document or framed (e.g. SVG, XML), and therefore needs a
     * script-free, sandboxed CSP even though it is not HTML.
     *
     * @param string $mimeType
     * @return bool
     */
    protected function isActiveDocumentType(string $mimeType): bool
    {
        return strpos($mimeType, 'svg') !== false
            || strpos($mimeType, 'xml') !== false;
    }

    /**
     * Sanitize file path to prevent directory traversal.
     *
     * @param string $path
     * @return string|null Sanitized path or null if invalid
     */


    /**
     * Inject CSS to hide teacher mode toggler inside eXeLearning content.
     */
    protected function injectTeacherModeCss(string $html): string
    {
        $css = '#teacher-mode-toggler-wrapper { visibility: hidden !important; }';
        $styleTag = '<style id="exelearning-hide-teacher-mode">' . $css . '</style>';

        if (stripos($html, '</head>') !== false) {
            return preg_replace('/<\/head>/i', $styleTag . '</head>', $html, 1) ?? $html;
        }

        return $styleTag . $html;
    }

    protected function sanitizePath(string $path): ?string
    {
        // Decode URL encoding
        $path = urldecode($path);

        // Remove null bytes
        $path = str_replace("\0", '', $path);

        // Normalize slashes
        $path = str_replace('\\', '/', $path);

        // Remove any ../ or ./ sequences
        $parts = explode('/', $path);
        $safeParts = [];

        foreach ($parts as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }
            if ($part === '..') {
                // Reject any attempt to go up directories
                return null;
            }
            $safeParts[] = $part;
        }

        if (empty($safeParts)) {
            return 'index.html';
        }

        return implode('/', $safeParts);
    }

    /**
     * Return a 404 response.
     *
     * @param string $message
     * @return HttpResponse
     */
    protected function notFound(string $message = 'Not found'): HttpResponse
    {
        $response = new HttpResponse();
        $response->setStatusCode(404);
        $response->getHeaders()->addHeaderLine('Content-Type', 'text/plain');
        $response->setContent($message);
        return $response;
    }
}
