<?php

declare(strict_types=1);

namespace ExeLearningTest\Controller;

use ExeLearning\Controller\PreviewController;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Unit tests for the host-served opaque HTTP preview controller.
 *
 * Mirrors the ContentController/StylesServeController test style: instantiate
 * the controller against the lightweight Laminas stubs, drive serveAction()
 * through setRouteParams(), and assert on the resulting Laminas\Http\Response
 * (status + hardening headers + CSP + body). The ephemeral session store is not
 * implemented yet, so the serving success path is exercised through a Testable
 * subclass that overrides the protected lookupPreviewFile() seam.
 *
 * @covers \ExeLearning\Controller\PreviewController
 */
class PreviewControllerTest extends TestCase
{
    /** A previewId that satisfies the capability-UUID gate. */
    private const VALID_UUID = '123e4567-e89b-12d3-a456-426614174000';

    /**
     * BYTE-IDENTICAL expected value of PreviewController::PREVIEW_SANDBOX_CSP,
     * itself a copy of eXe core previewCspHeader()
     * (src/shared/security/previewSandbox.ts). Kept as an independent literal so
     * a silent reformat/reorder of the controller constant fails this test.
     */
    private const EXPECTED_CSP =
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

    // =========================================================================
    // serveAction(): capability-UUID gate and store-miss 404s
    // =========================================================================

    public function testServeActionRejectsNonUuidPreviewIdWith404(): void
    {
        $controller = new PreviewController();
        $controller->setRouteParams(['previewId' => 'not-a-uuid', 'file' => 'index.html']);

        $response = $controller->serveAction();

        $this->assertSame(404, $response->getStatusCode());
        $this->assertSame('Not found', $response->getContent());
        $this->assertHardeningHeaders($response);
        // A 404 is served as text/plain, which is not scriptable: no CSP.
        $this->assertNull($response->getHeaders()->get('Content-Security-Policy'));
    }

    public function testServeActionRejectsEmptyPreviewIdWith404(): void
    {
        $controller = new PreviewController();
        $controller->setRouteParams(['file' => 'index.html']);

        $response = $controller->serveAction();

        $this->assertSame(404, $response->getStatusCode());
        $this->assertHardeningHeaders($response);
    }

    public function testServeActionRejectsTraversalPathWith404(): void
    {
        $controller = new PreviewController();
        $controller->setRouteParams(['previewId' => self::VALID_UUID, 'file' => '../secret.html']);

        $response = $controller->serveAction();

        $this->assertSame(404, $response->getStatusCode());
        $this->assertHardeningHeaders($response);
    }

    public function testServeActionReturns404WhenSessionStoreHasNoFile(): void
    {
        // Real controller: the stub lookupPreviewFile() returns null, so a valid
        // capability URL still 404s (with the correct hardening headers).
        $controller = new PreviewController();
        $controller->setRouteParams(['previewId' => self::VALID_UUID, 'file' => 'index.html']);

        $response = $controller->serveAction();

        $this->assertSame(404, $response->getStatusCode());
        $this->assertSame('Not found', $response->getContent());
        $this->assertHardeningHeaders($response);
    }

    // =========================================================================
    // serveAction(): serving success path (scriptable → CSP, passive → none)
    // =========================================================================

    public function testServeActionServesHtmlWithByteExactSandboxCsp(): void
    {
        $bytes = '<html><body><script>alert(1)</script></body></html>';
        $controller = $this->servingController(['bytes' => $bytes, 'mime' => 'text/html']);
        $controller->setRouteParams(['previewId' => self::VALID_UUID, 'file' => 'index.html']);

        $response = $controller->serveAction();

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame($bytes, $response->getContent());
        $headers = $response->getHeaders();
        $this->assertSame('text/html', $headers->get('Content-Type')->getFieldValue());
        $this->assertHardeningHeaders($response);

        $csp = $headers->get('Content-Security-Policy');
        $this->assertNotNull($csp, 'A scriptable document must carry the sandbox CSP');
        // The served header must equal the controller constant, byte for byte…
        $reflected = (new ReflectionClass(PreviewController::class))
            ->getConstant('PREVIEW_SANDBOX_CSP');
        $this->assertSame($reflected, $csp->getFieldValue());
        // …and that constant must not have drifted from the canonical literal.
        $this->assertSame(self::EXPECTED_CSP, $reflected);
    }

    public function testServeActionServesSvgWithSandboxCsp(): void
    {
        $bytes = '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>';
        $controller = $this->servingController(['bytes' => $bytes, 'mime' => 'image/svg+xml']);
        $controller->setRouteParams(['previewId' => self::VALID_UUID, 'file' => 'evil.svg']);

        $response = $controller->serveAction();

        $this->assertSame(200, $response->getStatusCode());
        $headers = $response->getHeaders();
        $this->assertSame('image/svg+xml', $headers->get('Content-Type')->getFieldValue());
        $csp = $headers->get('Content-Security-Policy');
        $this->assertNotNull($csp, 'An SVG opened top-level must be sandboxed');
        $this->assertSame(self::EXPECTED_CSP, $csp->getFieldValue());
    }

    public function testServeActionServesCssWithoutCsp(): void
    {
        $bytes = 'body { color: red; }';
        $controller = $this->servingController(['bytes' => $bytes, 'mime' => 'text/css']);
        $controller->setRouteParams(['previewId' => self::VALID_UUID, 'file' => 'style.css']);

        $response = $controller->serveAction();

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame($bytes, $response->getContent());
        $headers = $response->getHeaders();
        $this->assertSame('text/css', $headers->get('Content-Type')->getFieldValue());
        // CSS is passive — no sandbox CSP is attached.
        $this->assertNull($headers->get('Content-Security-Policy'));
    }

    public function testServeActionServesPngWithoutCsp(): void
    {
        $bytes = base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg=='
        );
        $controller = $this->servingController(['bytes' => $bytes, 'mime' => 'image/png']);
        $controller->setRouteParams(['previewId' => self::VALID_UUID, 'file' => 'p.png']);

        $response = $controller->serveAction();

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame($bytes, $response->getContent());
        $this->assertNull($response->getHeaders()->get('Content-Security-Policy'));
    }

    public function testServeActionFallsBackToExtensionMimeWhenRecordHasNoMime(): void
    {
        // No 'mime' key in the store record → serveAction() must derive it from
        // the path via mimeFor(); '.html' is scriptable, so the CSP is attached.
        $controller = $this->servingController(['bytes' => '<html></html>']);
        $controller->setRouteParams(['previewId' => self::VALID_UUID, 'file' => 'index.html']);

        $response = $controller->serveAction();

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('text/html', $response->getHeaders()->get('Content-Type')->getFieldValue());
        $this->assertNotNull($response->getHeaders()->get('Content-Security-Policy'));
    }

    // =========================================================================
    // isScriptableDocument()
    // =========================================================================

    public function testIsScriptableDocumentMatchesEveryScriptableType(): void
    {
        $controller = new PreviewController();
        foreach ([
            'text/html',
            'image/svg+xml',
            'application/xml',
            'application/xhtml+xml',
            'text/html; charset=utf-8',
            'IMAGE/SVG+XML',
        ] as $mime) {
            $this->assertTrue(
                $this->invokePrivate($controller, 'isScriptableDocument', [$mime]),
                "$mime must be scriptable"
            );
        }
    }

    public function testIsScriptableDocumentRejectsPassiveTypes(): void
    {
        $controller = new PreviewController();
        foreach ([
            'text/css',
            'image/png',
            'application/javascript',
            'application/pdf',
        ] as $mime) {
            $this->assertFalse(
                $this->invokePrivate($controller, 'isScriptableDocument', [$mime]),
                "$mime must not be scriptable"
            );
        }
    }

    // =========================================================================
    // normalizePath()
    // =========================================================================

    public function testNormalizePathDefaultsToIndexHtmlForEmptyPath(): void
    {
        $controller = new PreviewController();
        $this->assertSame('index.html', $this->invokePrivate($controller, 'normalizePath', ['']));
    }

    public function testNormalizePathRejectsTraversal(): void
    {
        $controller = new PreviewController();
        $this->assertNull($this->invokePrivate($controller, 'normalizePath', ['../../etc/passwd']));
        $this->assertNull($this->invokePrivate($controller, 'normalizePath', ['css/../../secret']));
    }

    public function testNormalizePathNormalizesNestedPath(): void
    {
        $controller = new PreviewController();
        $this->assertSame(
            'css/styles.css',
            $this->invokePrivate($controller, 'normalizePath', ['./css//styles.css'])
        );
        $this->assertSame(
            'a/b/c.js',
            $this->invokePrivate($controller, 'normalizePath', ['a\\b\\c.js'])
        );
    }

    // =========================================================================
    // mimeFor()
    // =========================================================================

    public function testMimeForMapsKnownExtensions(): void
    {
        $controller = new PreviewController();
        $this->assertSame('text/html', $this->invokePrivate($controller, 'mimeFor', ['page.html']));
        $this->assertSame('image/svg+xml', $this->invokePrivate($controller, 'mimeFor', ['icon.SVG']));
        $this->assertSame('text/css', $this->invokePrivate($controller, 'mimeFor', ['a/b/style.css']));
    }

    public function testMimeForFallsBackToOctetStream(): void
    {
        $controller = new PreviewController();
        $this->assertSame(
            'application/octet-stream',
            $this->invokePrivate($controller, 'mimeFor', ['archive.unknownext'])
        );
        $this->assertSame(
            'application/octet-stream',
            $this->invokePrivate($controller, 'mimeFor', ['README'])
        );
    }

    // =========================================================================
    // helpers
    // =========================================================================

    /**
     * A PreviewController whose ephemeral-store lookup is stubbed to return the
     * given record, so the serving success path can be exercised without a live
     * PreviewSessionStore.
     *
     * @param array{bytes: string, mime?: string}|null $file
     */
    private function servingController(?array $file): PreviewController
    {
        $controller = new class($file) extends PreviewController {
            /** @var array{bytes: string, mime?: string}|null */
            private ?array $stubFile;

            public function __construct(?array $file)
            {
                $this->stubFile = $file;
            }

            protected function lookupPreviewFile(string $previewId, string $path): ?array
            {
                return $this->stubFile;
            }
        };

        return $controller;
    }

    /**
     * Assert the hardening headers the contract requires on EVERY preview
     * response (hits and misses alike).
     */
    private function assertHardeningHeaders(object $response): void
    {
        $headers = $response->getHeaders();
        $this->assertSame('nosniff', $headers->get('X-Content-Type-Options')->getFieldValue());
        $this->assertSame('no-referrer', $headers->get('Referrer-Policy')->getFieldValue());
        $this->assertSame('no-store', $headers->get('Cache-Control')->getFieldValue());
        $this->assertSame('*', $headers->get('Access-Control-Allow-Origin')->getFieldValue());
    }

    /**
     * Invoke a private/protected method via reflection.
     *
     * @param array<int, mixed> $args
     * @return mixed
     */
    private function invokePrivate(object $object, string $method, array $args)
    {
        $ref = new ReflectionClass($object);
        $m = $ref->getMethod($method);
        $m->setAccessible(true);
        return $m->invokeArgs($object, $args);
    }
}
