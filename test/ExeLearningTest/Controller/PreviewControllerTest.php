<?php

declare(strict_types=1);

namespace ExeLearningTest\Controller;

use ExeLearning\Controller\PreviewController;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Unit tests for the host-served opaque HTTP preview controller (serving
 * contract v2).
 *
 * The ephemeral session store is driven directly in its own test; here the
 * serving success paths are exercised through a Testable subclass overriding the
 * protected lookupPreviewFile() seam, so the controller's HTTP concerns (tiered
 * Cache-Control, the byte-exact sandbox CSP on every scriptable layer, ETag /
 * If-None-Match / Range) are asserted in isolation.
 *
 * @covers \ExeLearning\Controller\PreviewController
 */
class PreviewControllerTest extends TestCase
{
    /** A previewId that satisfies the capability-UUID gate. */
    private const VALID_UUID = '123e4567-e89b-12d3-a456-426614174000';

    private const PHOTO_KEY = 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeffff0000@9c41d2e8a1b03f57';

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
        $this->assertBaseHardening($response);
        $this->assertSame('no-store', $response->getHeaders()->get('Cache-Control')->getFieldValue());
        // A 404 is served as text/plain, which is not scriptable: no CSP.
        $this->assertNull($response->getHeaders()->get('Content-Security-Policy'));
    }

    public function testServeActionRejectsEmptyPreviewIdWith404(): void
    {
        $controller = new PreviewController();
        $controller->setRouteParams(['file' => 'index.html']);

        $response = $controller->serveAction();

        $this->assertSame(404, $response->getStatusCode());
        $this->assertBaseHardening($response);
    }

    public function testServeActionRejectsTraversalPathWith404(): void
    {
        $controller = new PreviewController();
        $controller->setRouteParams(['previewId' => self::VALID_UUID, 'file' => '../secret.html']);

        $response = $controller->serveAction();

        $this->assertSame(404, $response->getStatusCode());
        $this->assertBaseHardening($response);
    }

    public function testServeActionReturns404WhenStoreHasNoFile(): void
    {
        // Real controller with no store: lookupPreviewFile() returns null, so a
        // valid capability URL still 404s (with the correct hardening headers).
        $controller = new PreviewController();
        $controller->setRouteParams(['previewId' => self::VALID_UUID, 'file' => 'index.html']);

        $response = $controller->serveAction();

        $this->assertSame(404, $response->getStatusCode());
        $this->assertSame('Not found', $response->getContent());
        $this->assertBaseHardening($response);
        $this->assertSame('no-store', $response->getHeaders()->get('Cache-Control')->getFieldValue());
    }

    // =========================================================================
    // serveAction(): documents (layer 3) — no-store + CSP when scriptable
    // =========================================================================

    public function testServeActionServesHtmlDocumentWithByteExactSandboxCsp(): void
    {
        $bytes = '<html><body><script>alert(1)</script></body></html>';
        $controller = $this->servingController(['kind' => 'document', 'bytes' => $bytes]);
        $controller->setRouteParams(['previewId' => self::VALID_UUID, 'file' => 'index.html']);

        $response = $controller->serveAction();

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame($bytes, $response->getContent());
        $headers = $response->getHeaders();
        $this->assertSame('text/html; charset=utf-8', $headers->get('Content-Type')->getFieldValue());
        $this->assertSame('no-store', $headers->get('Cache-Control')->getFieldValue());
        $this->assertBaseHardening($response);

        $csp = $headers->get('Content-Security-Policy');
        $this->assertNotNull($csp, 'A scriptable document must carry the sandbox CSP');
        // The served header must equal the controller constant, byte for byte…
        $reflected = (new ReflectionClass(PreviewController::class))->getConstant('PREVIEW_SANDBOX_CSP');
        $this->assertSame($reflected, $csp->getFieldValue());
        // …and that constant must not have drifted from the canonical literal.
        $this->assertSame(self::EXPECTED_CSP, $reflected);
    }

    public function testServeActionServesSvgDocumentWithSandboxCsp(): void
    {
        $bytes = '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>';
        $controller = $this->servingController(['kind' => 'document', 'bytes' => $bytes]);
        $controller->setRouteParams(['previewId' => self::VALID_UUID, 'file' => 'evil.svg']);

        $response = $controller->serveAction();

        $this->assertSame(200, $response->getStatusCode());
        $headers = $response->getHeaders();
        $this->assertSame('image/svg+xml; charset=utf-8', $headers->get('Content-Type')->getFieldValue());
        $this->assertSame(self::EXPECTED_CSP, $headers->get('Content-Security-Policy')->getFieldValue());
    }

    public function testServeActionServesCssDocumentWithoutCsp(): void
    {
        $controller = $this->servingController(['kind' => 'document', 'bytes' => 'body { color: red; }']);
        $controller->setRouteParams(['previewId' => self::VALID_UUID, 'file' => 'style.css']);

        $response = $controller->serveAction();

        $this->assertSame(200, $response->getStatusCode());
        $headers = $response->getHeaders();
        $this->assertSame('text/css; charset=utf-8', $headers->get('Content-Type')->getFieldValue());
        $this->assertNull($headers->get('Content-Security-Policy'));
    }

    public function testServeActionServesPngDocumentWithoutCspAndNoCharset(): void
    {
        $bytes = base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg=='
        );
        $controller = $this->servingController(['kind' => 'document', 'bytes' => $bytes]);
        $controller->setRouteParams(['previewId' => self::VALID_UUID, 'file' => 'p.png']);

        $response = $controller->serveAction();

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame($bytes, $response->getContent());
        $this->assertSame('image/png', $response->getHeaders()->get('Content-Type')->getFieldValue());
        $this->assertNull($response->getHeaders()->get('Content-Security-Policy'));
    }

    public function testServeActionDefaultsKindToDocument(): void
    {
        // A descriptor with no 'kind' is treated as a generated document.
        $controller = $this->servingController(['bytes' => '<html></html>']);
        $controller->setRouteParams(['previewId' => self::VALID_UUID, 'file' => 'index.html']);

        $response = $controller->serveAction();

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('no-store', $response->getHeaders()->get('Cache-Control')->getFieldValue());
        $this->assertNotNull($response->getHeaders()->get('Content-Security-Policy'));
    }

    // =========================================================================
    // serveAction(): fixed resources (layer 1) — immutable long-lived cache
    // =========================================================================

    public function testServeActionServesFixedResourceWithImmutableCacheNoCsp(): void
    {
        $controller = $this->servingController(['kind' => 'fixed', 'bytes' => 'window.jQuery=function(){};']);
        $controller->setRouteParams(['previewId' => self::VALID_UUID, 'file' => 'libs/jquery/jquery.min.js']);

        $response = $controller->serveAction();

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('window.jQuery=function(){};', $response->getContent());
        $this->assertSame('private, max-age=31536000', $response->getHeaders()->get('Cache-Control')->getFieldValue());
        $this->assertNull($response->getHeaders()->get('Content-Security-Policy'));
    }

    public function testServeActionServesFixedScriptableSvgWithSandboxCsp(): void
    {
        // The sandbox CSP must be emitted on scriptable types from EVERY layer,
        // fixed included ("SVG opened in a new tab").
        $bytes = '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>';
        $controller = $this->servingController(['kind' => 'fixed', 'bytes' => $bytes]);
        $controller->setRouteParams(['previewId' => self::VALID_UUID, 'file' => 'theme/icon.svg']);

        $response = $controller->serveAction();

        $headers = $response->getHeaders();
        $this->assertSame('image/svg+xml; charset=utf-8', $headers->get('Content-Type')->getFieldValue());
        $this->assertSame('private, max-age=31536000', $headers->get('Cache-Control')->getFieldValue());
        $this->assertSame(self::EXPECTED_CSP, $headers->get('Content-Security-Policy')->getFieldValue());
    }

    // =========================================================================
    // serveAction(): session assets (layer 2) — revalidating tier
    // =========================================================================

    public function testServeActionServesAssetWithEtagAndAcceptRanges(): void
    {
        $controller = $this->servingController(['kind' => 'asset', 'bytes' => 'PHOTO-BYTES-v1', 'etag' => self::PHOTO_KEY]);
        $controller->setRouteParams(['previewId' => self::VALID_UUID, 'file' => 'content/resources/photo.png']);

        $response = $controller->serveAction();

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('PHOTO-BYTES-v1', $response->getContent());
        $headers = $response->getHeaders();
        $this->assertSame('image/png', $headers->get('Content-Type')->getFieldValue());
        $this->assertSame('no-cache', $headers->get('Cache-Control')->getFieldValue());
        $this->assertSame('"' . self::PHOTO_KEY . '"', $headers->get('ETag')->getFieldValue());
        $this->assertSame('bytes', $headers->get('Accept-Ranges')->getFieldValue());
        // A passive image asset carries no CSP.
        $this->assertNull($headers->get('Content-Security-Policy'));
    }

    public function testServeActionAssetScriptableSvgGetsSandboxCsp(): void
    {
        $bytes = '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(2)</script></svg>';
        $controller = $this->servingController(['kind' => 'asset', 'bytes' => $bytes, 'etag' => 'k']);
        $controller->setRouteParams(['previewId' => self::VALID_UUID, 'file' => 'img/inline.svg']);

        $response = $controller->serveAction();

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(self::EXPECTED_CSP, $response->getHeaders()->get('Content-Security-Policy')->getFieldValue());
    }

    public function testServeAssetReturns304OnMatchingIfNoneMatch(): void
    {
        $controller = $this->servingController(
            ['kind' => 'asset', 'bytes' => 'PHOTO-BYTES-v1', 'etag' => self::PHOTO_KEY],
            $this->requestWithHeaders(['If-None-Match' => '"' . self::PHOTO_KEY . '"'])
        );
        $controller->setRouteParams(['previewId' => self::VALID_UUID, 'file' => 'content/resources/photo.png']);

        $response = $controller->serveAction();

        $this->assertSame(304, $response->getStatusCode());
        $this->assertSame('', $response->getContent());
        $this->assertSame('nosniff', $response->getHeaders()->get('X-Content-Type-Options')->getFieldValue());
    }

    public function testServeAssetReturns206ForSatisfiableRange(): void
    {
        $controller = $this->servingController(
            ['kind' => 'asset', 'bytes' => '0123456789', 'etag' => 'clip'],
            $this->requestWithHeaders(['Range' => 'bytes=2-4'])
        );
        $controller->setRouteParams(['previewId' => self::VALID_UUID, 'file' => 'media/clip.mp4']);

        $response = $controller->serveAction();

        $this->assertSame(206, $response->getStatusCode());
        $this->assertSame('234', $response->getContent());
        $headers = $response->getHeaders();
        $this->assertSame('bytes 2-4/10', $headers->get('Content-Range')->getFieldValue());
        $this->assertSame('3', $headers->get('Content-Length')->getFieldValue());
    }

    public function testServeAssetReturns416ForUnsatisfiableRange(): void
    {
        $controller = $this->servingController(
            ['kind' => 'asset', 'bytes' => '0123456789', 'etag' => 'clip'],
            $this->requestWithHeaders(['Range' => 'bytes=99-'])
        );
        $controller->setRouteParams(['previewId' => self::VALID_UUID, 'file' => 'media/clip.mp4']);

        $response = $controller->serveAction();

        $this->assertSame(416, $response->getStatusCode());
        $this->assertSame('bytes */10', $response->getHeaders()->get('Content-Range')->getFieldValue());
    }

    public function testServeAssetSuffixRangeReturnsLastBytes(): void
    {
        $controller = $this->servingController(
            ['kind' => 'asset', 'bytes' => '0123456789', 'etag' => 'clip'],
            $this->requestWithHeaders(['Range' => 'bytes=-3'])
        );
        $controller->setRouteParams(['previewId' => self::VALID_UUID, 'file' => 'media/clip.mp4']);

        $response = $controller->serveAction();

        $this->assertSame(206, $response->getStatusCode());
        $this->assertSame('789', $response->getContent());
        $this->assertSame('bytes 7-9/10', $response->getHeaders()->get('Content-Range')->getFieldValue());
    }

    public function testServeAssetMalformedRangeIsUnsatisfiable(): void
    {
        $controller = $this->servingController(
            ['kind' => 'asset', 'bytes' => '0123456789', 'etag' => 'clip'],
            $this->requestWithHeaders(['Range' => 'items=1-2'])
        );
        $controller->setRouteParams(['previewId' => self::VALID_UUID, 'file' => 'media/clip.mp4']);

        $response = $controller->serveAction();

        $this->assertSame(416, $response->getStatusCode());
    }

    /**
     * @dataProvider unsatisfiableRangeProvider
     */
    public function testServeAssetEdgeCaseRangesAreUnsatisfiable(string $range): void
    {
        $controller = $this->servingController(
            ['kind' => 'asset', 'bytes' => '0123456789', 'etag' => 'clip'],
            $this->requestWithHeaders(['Range' => $range])
        );
        $controller->setRouteParams(['previewId' => self::VALID_UUID, 'file' => 'media/clip.mp4']);

        $response = $controller->serveAction();

        $this->assertSame(416, $response->getStatusCode());
    }

    public function unsatisfiableRangeProvider(): array
    {
        return [
            'both-open' => ['bytes=-'],
            'end-before-start' => ['bytes=5-2'],
            'zero-suffix' => ['bytes=-0'],
        ];
    }

    public function testServeAssetOpenEndedRangeReturnsRemainder(): void
    {
        $controller = $this->servingController(
            ['kind' => 'asset', 'bytes' => '0123456789', 'etag' => 'clip'],
            $this->requestWithHeaders(['Range' => 'bytes=7-'])
        );
        $controller->setRouteParams(['previewId' => self::VALID_UUID, 'file' => 'media/clip.mp4']);

        $response = $controller->serveAction();

        $this->assertSame(206, $response->getStatusCode());
        $this->assertSame('789', $response->getContent());
        $this->assertSame('bytes 7-9/10', $response->getHeaders()->get('Content-Range')->getFieldValue());
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
    // contentTypeFor() / mimeFor()
    // =========================================================================

    public function testContentTypeForAppendsCharsetToTextualTypes(): void
    {
        $controller = new PreviewController();
        $this->assertSame('text/html; charset=utf-8', $this->invokePrivate($controller, 'contentTypeFor', ['page.html']));
        $this->assertSame('image/svg+xml; charset=utf-8', $this->invokePrivate($controller, 'contentTypeFor', ['icon.svg']));
        $this->assertSame('application/json; charset=utf-8', $this->invokePrivate($controller, 'contentTypeFor', ['a.json']));
        $this->assertSame('image/png', $this->invokePrivate($controller, 'contentTypeFor', ['p.png']));
    }

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
     * given descriptor, so the serving success path can be exercised without a
     * live store.
     *
     * @param array{kind?: string, bytes: string, etag?: string}|null $file
     * @param object|null $request Optional request stub exposing getHeaders().
     */
    private function servingController(?array $file, ?object $request = null): PreviewController
    {
        $controller = new class($file) extends PreviewController {
            /** @var array|null */
            private $stubFile;

            public function __construct(?array $file)
            {
                parent::__construct(null);
                $this->stubFile = $file;
            }

            protected function lookupPreviewFile(string $previewId, string $path): ?array
            {
                return $this->stubFile;
            }
        };
        if ($request !== null) {
            $controller->setRequest($request);
        }
        return $controller;
    }

    /**
     * A request stub exposing a case-insensitive getHeaders()->get()->getFieldValue().
     *
     * @param array<string, string> $headers
     */
    private function requestWithHeaders(array $headers): object
    {
        return new class ($headers) {
            /** @var array<string, string> */
            private $headers;

            public function __construct(array $headers)
            {
                $this->headers = $headers;
            }

            public function getHeaders()
            {
                $headers = $this->headers;
                return new class ($headers) {
                    /** @var array<string, string> */
                    private $headers;

                    public function __construct(array $headers)
                    {
                        $this->headers = $headers;
                    }

                    public function get($name)
                    {
                        foreach ($this->headers as $key => $value) {
                            if (strcasecmp($key, $name) === 0) {
                                return new class ($value) {
                                    private string $value;
                                    public function __construct(string $value)
                                    {
                                        $this->value = $value;
                                    }
                                    public function getFieldValue(): string
                                    {
                                        return $this->value;
                                    }
                                };
                            }
                        }
                        return null;
                    }
                };
            }
        };
    }

    /**
     * Assert the four hardening headers the contract requires on EVERY preview
     * response (hits and misses alike). Cache-Control is tiered per layer, so it
     * is asserted per test rather than here.
     */
    private function assertBaseHardening(object $response): void
    {
        $headers = $response->getHeaders();
        $this->assertSame('nosniff', $headers->get('X-Content-Type-Options')->getFieldValue());
        $this->assertSame('no-referrer', $headers->get('Referrer-Policy')->getFieldValue());
        $this->assertSame('*', $headers->get('Access-Control-Allow-Origin')->getFieldValue());
        $this->assertSame(
            'camera=(), microphone=(), geolocation=(), payment=()',
            $headers->get('Permissions-Policy')->getFieldValue()
        );
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
