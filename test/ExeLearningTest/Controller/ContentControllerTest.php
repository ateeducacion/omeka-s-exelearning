<?php

declare(strict_types=1);

namespace ExeLearningTest\Controller;

use ExeLearning\Controller\ContentController;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Unit tests for ContentController.
 *
 * @covers \ExeLearning\Controller\ContentController
 */
class ContentControllerTest extends TestCase
{
    private ContentController $controller;
    private string $testBasePath;

    protected function setUp(): void
    {
        $this->testBasePath = sys_get_temp_dir() . '/exelearning-test-' . uniqid();
        mkdir($this->testBasePath, 0755, true);
        $this->controller = new ContentController($this->testBasePath);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->testBasePath);
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }
        rmdir($dir);
    }

    /**
     * Helper to call protected methods via reflection.
     */
    private function callProtectedMethod(object $object, string $method, array $args = [])
    {
        $reflection = new ReflectionClass($object);
        $method = $reflection->getMethod($method);
        $method->setAccessible(true);
        return $method->invokeArgs($object, $args);
    }

    // =========================================================================
    // sanitizePath() tests
    // =========================================================================

    public function testSanitizePathWithNormalPath(): void
    {
        $result = $this->callProtectedMethod($this->controller, 'sanitizePath', ['css/styles.css']);
        $this->assertEquals('css/styles.css', $result);
    }

    public function testSanitizePathWithIndexHtml(): void
    {
        $result = $this->callProtectedMethod($this->controller, 'sanitizePath', ['index.html']);
        $this->assertEquals('index.html', $result);
    }

    public function testSanitizePathWithEmptyPath(): void
    {
        $result = $this->callProtectedMethod($this->controller, 'sanitizePath', ['']);
        $this->assertEquals('index.html', $result);
    }

    public function testSanitizePathWithDotSlash(): void
    {
        $result = $this->callProtectedMethod($this->controller, 'sanitizePath', ['./css/styles.css']);
        $this->assertEquals('css/styles.css', $result);
    }

    public function testSanitizePathRejectsDirectoryTraversal(): void
    {
        $result = $this->callProtectedMethod($this->controller, 'sanitizePath', ['../../../etc/passwd']);
        $this->assertNull($result);
    }

    public function testSanitizePathRejectsDoubleDotInMiddle(): void
    {
        $result = $this->callProtectedMethod($this->controller, 'sanitizePath', ['css/../../../etc/passwd']);
        $this->assertNull($result);
    }

    public function testSanitizePathNormalizesBackslashes(): void
    {
        $result = $this->callProtectedMethod($this->controller, 'sanitizePath', ['css\\styles.css']);
        $this->assertEquals('css/styles.css', $result);
    }

    public function testSanitizePathRemovesNullBytes(): void
    {
        $result = $this->callProtectedMethod($this->controller, 'sanitizePath', ["css\0/styles.css"]);
        $this->assertEquals('css/styles.css', $result);
    }

    public function testSanitizePathWithUrlEncodedPath(): void
    {
        $result = $this->callProtectedMethod($this->controller, 'sanitizePath', ['css%2Fstyles.css']);
        $this->assertEquals('css/styles.css', $result);
    }

    public function testSanitizePathWithDeepPath(): void
    {
        $result = $this->callProtectedMethod($this->controller, 'sanitizePath', ['a/b/c/d/e/file.js']);
        $this->assertEquals('a/b/c/d/e/file.js', $result);
    }

    // =========================================================================
    // MIME type tests
    // =========================================================================

    public function testMimeTypesPropertyExists(): void
    {
        $reflection = new ReflectionClass($this->controller);
        $property = $reflection->getProperty('mimeTypes');
        $property->setAccessible(true);
        $mimeTypes = $property->getValue($this->controller);

        $this->assertIsArray($mimeTypes);
        $this->assertArrayHasKey('html', $mimeTypes);
        $this->assertArrayHasKey('css', $mimeTypes);
        $this->assertArrayHasKey('js', $mimeTypes);
        $this->assertArrayHasKey('png', $mimeTypes);
        $this->assertArrayHasKey('jpg', $mimeTypes);
        $this->assertArrayHasKey('svg', $mimeTypes);
        $this->assertArrayHasKey('woff2', $mimeTypes);
        $this->assertArrayHasKey('mp4', $mimeTypes);
        $this->assertArrayHasKey('pdf', $mimeTypes);
    }

    public function testMimeTypeForHtml(): void
    {
        $reflection = new ReflectionClass($this->controller);
        $property = $reflection->getProperty('mimeTypes');
        $property->setAccessible(true);
        $mimeTypes = $property->getValue($this->controller);

        $this->assertEquals('text/html', $mimeTypes['html']);
        $this->assertEquals('text/html', $mimeTypes['htm']);
    }

    public function testMimeTypeForCss(): void
    {
        $reflection = new ReflectionClass($this->controller);
        $property = $reflection->getProperty('mimeTypes');
        $property->setAccessible(true);
        $mimeTypes = $property->getValue($this->controller);

        $this->assertEquals('text/css', $mimeTypes['css']);
    }

    public function testMimeTypeForJavascript(): void
    {
        $reflection = new ReflectionClass($this->controller);
        $property = $reflection->getProperty('mimeTypes');
        $property->setAccessible(true);
        $mimeTypes = $property->getValue($this->controller);

        $this->assertEquals('application/javascript', $mimeTypes['js']);
    }

    public function testMimeTypeForImages(): void
    {
        $reflection = new ReflectionClass($this->controller);
        $property = $reflection->getProperty('mimeTypes');
        $property->setAccessible(true);
        $mimeTypes = $property->getValue($this->controller);

        $this->assertEquals('image/png', $mimeTypes['png']);
        $this->assertEquals('image/jpeg', $mimeTypes['jpg']);
        $this->assertEquals('image/jpeg', $mimeTypes['jpeg']);
        $this->assertEquals('image/gif', $mimeTypes['gif']);
        $this->assertEquals('image/svg+xml', $mimeTypes['svg']);
        $this->assertEquals('image/webp', $mimeTypes['webp']);
    }

    public function testMimeTypeForFonts(): void
    {
        $reflection = new ReflectionClass($this->controller);
        $property = $reflection->getProperty('mimeTypes');
        $property->setAccessible(true);
        $mimeTypes = $property->getValue($this->controller);

        $this->assertEquals('font/woff', $mimeTypes['woff']);
        $this->assertEquals('font/woff2', $mimeTypes['woff2']);
        $this->assertEquals('font/ttf', $mimeTypes['ttf']);
    }

    public function testMimeTypeForMedia(): void
    {
        $reflection = new ReflectionClass($this->controller);
        $property = $reflection->getProperty('mimeTypes');
        $property->setAccessible(true);
        $mimeTypes = $property->getValue($this->controller);

        $this->assertEquals('audio/mpeg', $mimeTypes['mp3']);
        $this->assertEquals('video/mp4', $mimeTypes['mp4']);
        $this->assertEquals('video/webm', $mimeTypes['webm']);
        $this->assertEquals('audio/ogg', $mimeTypes['ogg']);
    }

    // =========================================================================
    // Hash validation tests (via regex pattern from serveAction)
    // =========================================================================

    /**
     * @dataProvider validHashProvider
     */
    public function testValidHashFormat(string $hash): void
    {
        $this->assertMatchesRegularExpression('/^[a-f0-9]{40}$/i', $hash);
    }

    /**
     * @dataProvider invalidHashProvider
     */
    public function testInvalidHashFormat(string $hash): void
    {
        $this->assertDoesNotMatchRegularExpression('/^[a-f0-9]{40}$/i', $hash);
    }

    public function validHashProvider(): array
    {
        return [
            'lowercase sha1' => ['da39a3ee5e6b4b0d3255bfef95601890afd80709'],
            'uppercase sha1' => ['DA39A3EE5E6B4B0D3255BFEF95601890AFD80709'],
            'mixed case sha1' => ['Da39A3eE5e6B4b0D3255BfEf95601890AfD80709'],
            'all zeros' => ['0000000000000000000000000000000000000000'],
            'all f' => ['ffffffffffffffffffffffffffffffffffffffff'],
        ];
    }

    public function invalidHashProvider(): array
    {
        return [
            'too short' => ['da39a3ee5e6b4b0d3255bfef9560189'],
            'too long' => ['da39a3ee5e6b4b0d3255bfef95601890afd807090'],
            'contains g' => ['ga39a3ee5e6b4b0d3255bfef95601890afd80709'],
            'contains special char' => ['da39a3ee5e6b4b0d3255bfef95601890afd8070!'],
            'empty string' => [''],
            'path traversal attempt' => ['../../../etc/passwd'],
            'contains spaces' => ['da39a3ee 5e6b4b0d3255bfef95601890afd80709'],
        ];
    }

    // =========================================================================
    // notFound() tests
    // =========================================================================

    public function testNotFoundReturns404(): void
    {
        $response = $this->callProtectedMethod($this->controller, 'notFound', ['Test message']);

        $this->assertEquals(404, $response->getStatusCode());
        $this->assertEquals('Test message', $response->getContent());
        $this->assertStringContainsString('text/plain', $response->getHeaders()->get('Content-Type')->getFieldValue());
    }

    public function testNotFoundDefaultMessage(): void
    {
        $response = $this->callProtectedMethod($this->controller, 'notFound', []);

        $this->assertEquals(404, $response->getStatusCode());
        $this->assertEquals('Not found', $response->getContent());
    }

    // =========================================================================
    // addSecurityHeaders() tests
    // =========================================================================

    public function testAddSecurityHeadersForHtml(): void
    {
        $headers = new \Laminas\Http\Headers();
        $this->callProtectedMethod($this->controller, 'addSecurityHeaders', [$headers, 'text/html']);

        $this->assertNotNull($headers->get('X-Frame-Options'));
        $this->assertEquals('SAMEORIGIN', $headers->get('X-Frame-Options')->getFieldValue());

        $this->assertNotNull($headers->get('X-Content-Type-Options'));
        $this->assertEquals('nosniff', $headers->get('X-Content-Type-Options')->getFieldValue());

        $this->assertNotNull($headers->get('Content-Security-Policy'));
        $this->assertNotNull($headers->get('Referrer-Policy'));
        $this->assertNotNull($headers->get('Permissions-Policy'));
    }

    public function testAddSecurityHeadersForNonHtml(): void
    {
        $headers = new \Laminas\Http\Headers();
        $this->callProtectedMethod($this->controller, 'addSecurityHeaders', [$headers, 'image/png']);

        $this->assertNotNull($headers->get('X-Frame-Options'));
        $this->assertNotNull($headers->get('X-Content-Type-Options'));

        // CSP should NOT be added for non-HTML content
        $this->assertNull($headers->get('Content-Security-Policy'));
    }

    public function testAddSecurityHeadersForCss(): void
    {
        $headers = new \Laminas\Http\Headers();
        $this->callProtectedMethod($this->controller, 'addSecurityHeaders', [$headers, 'text/css']);

        $this->assertNotNull($headers->get('X-Frame-Options'));
        $this->assertNull($headers->get('Content-Security-Policy'));
    }

    public function testAddSecurityHeadersForJavascript(): void
    {
        $headers = new \Laminas\Http\Headers();
        $this->callProtectedMethod($this->controller, 'addSecurityHeaders', [$headers, 'application/javascript']);

        $this->assertNotNull($headers->get('X-Frame-Options'));
        $this->assertNull($headers->get('Content-Security-Policy'));
    }

    public function testCspContainsRequiredDirectives(): void
    {
        $headers = new \Laminas\Http\Headers();
        $this->callProtectedMethod($this->controller, 'addSecurityHeaders', [$headers, 'text/html']);

        $csp = $headers->get('Content-Security-Policy')->getFieldValue();

        $this->assertStringContainsString("default-src 'self'", $csp);
        $this->assertStringContainsString("script-src 'self' 'unsafe-inline' 'unsafe-eval'", $csp);
        $this->assertStringContainsString("style-src 'self' 'unsafe-inline'", $csp);
        $this->assertStringContainsString("img-src 'self' data: blob:", $csp);
        $this->assertStringContainsString("frame-ancestors 'self'", $csp);
        $this->assertStringContainsString("form-action 'none'", $csp);
    }

    public function testSecureModeCspSandboxesTheDocument(): void
    {
        // Default controller is secure mode.
        $headers = new \Laminas\Http\Headers();
        $this->callProtectedMethod($this->controller, 'addSecurityHeaders', [$headers, 'text/html']);

        $csp = $headers->get('Content-Security-Policy')->getFieldValue();
        // The response-level sandbox keeps the document opaque even if opened outside
        // the iframe (new tab / escaped popup / raw URL navigation).
        $this->assertStringContainsString('sandbox allow-scripts allow-popups', $csp);
    }

    public function testLegacyModeCspHasNoSandbox(): void
    {
        $controller = new ContentController($this->testBasePath, 'legacy');
        $headers = new \Laminas\Http\Headers();
        $this->callProtectedMethod($controller, 'addSecurityHeaders', [$headers, 'text/html']);

        $csp = $headers->get('Content-Security-Policy')->getFieldValue();
        $this->assertStringNotContainsString('sandbox', $csp);
        // Legacy still keeps the rest of the policy.
        $this->assertStringContainsString("default-src 'self'", $csp);
    }

    public function testInjectEmbedShimAddsShimInSecureMode(): void
    {
        $html = '<html><head></head><body><p>content</p></body></html>';
        $out = $this->callProtectedMethod($this->controller, 'injectEmbedShim', [$html]);

        $this->assertStringContainsString('exelearning-embed-shim', $out);
        $this->assertStringContainsString('__exeEmbedWhitelist', $out);
        $this->assertStringContainsString('youtube-nocookie.com', $out);
        // The shim source itself is inlined.
        $this->assertStringContainsString('data-exe-embed-id', $out);
        // Injected before the closing body tag.
        $this->assertStringContainsString('</script></body>', $out);
    }

    public function testInjectEmbedShimIsNoopInLegacyMode(): void
    {
        $controller = new ContentController($this->testBasePath, 'legacy');
        $html = '<html><head></head><body><p>content</p></body></html>';
        $out = $this->callProtectedMethod($controller, 'injectEmbedShim', [$html]);

        $this->assertSame($html, $out);
        $this->assertStringNotContainsString('exelearning-embed-shim', $out);
    }

    public function testCspAllowsExternalEmbeds(): void
    {
        $headers = new \Laminas\Http\Headers();
        $this->callProtectedMethod($this->controller, 'addSecurityHeaders', [$headers, 'text/html']);

        $csp = $headers->get('Content-Security-Policy')->getFieldValue();
        // External https: images/video and framed embeds (PDFs, YouTube, Vimeo) are
        // allowed so authors can include external resources.
        $this->assertStringContainsString("img-src 'self' data: blob: https:", $csp);
        $this->assertStringContainsString("media-src 'self' data: blob: https:", $csp);
        $this->assertStringContainsString("frame-src 'self' https:", $csp);
        // …but the exfiltration channel stays closed.
        $this->assertStringContainsString("connect-src 'self'", $csp);
        $this->assertStringNotContainsString("connect-src 'self' https:", $csp);
    }

    // =========================================================================
    // Constructor tests
    // =========================================================================

    public function testConstructorSetsBasePath(): void
    {
        $reflection = new ReflectionClass($this->controller);
        $property = $reflection->getProperty('basePath');
        $property->setAccessible(true);

        $this->assertEquals($this->testBasePath, $property->getValue($this->controller));
    }

    // =========================================================================
    // Additional sanitizePath edge cases
    // =========================================================================

    public function testSanitizePathWithMultipleSlashes(): void
    {
        $result = $this->callProtectedMethod($this->controller, 'sanitizePath', ['css///styles.css']);
        $this->assertEquals('css/styles.css', $result);
    }

    public function testSanitizePathWithOnlyDots(): void
    {
        $result = $this->callProtectedMethod($this->controller, 'sanitizePath', ['./././']);
        $this->assertEquals('index.html', $result);
    }

    public function testSanitizePathWithEncodedDoubleDot(): void
    {
        $result = $this->callProtectedMethod($this->controller, 'sanitizePath', ['%2e%2e/etc/passwd']);
        $this->assertNull($result);
    }

    // =========================================================================
    // serveAction() tests
    // =========================================================================

    public function testServeActionReturns404ForInvalidHash(): void
    {
        $this->controller->setRouteParams(['hash' => 'invalid', 'file' => 'index.html']);

        $response = $this->controller->serveAction();

        $this->assertEquals(404, $response->getStatusCode());
        $this->assertStringContainsString('Invalid content identifier', $response->getContent());
    }

    public function testServeActionReturns404ForEmptyHash(): void
    {
        $this->controller->setRouteParams(['hash' => '', 'file' => 'index.html']);

        $response = $this->controller->serveAction();

        $this->assertEquals(404, $response->getStatusCode());
    }

    public function testServeActionReturns404ForDirectoryTraversal(): void
    {
        $hash = 'da39a3ee5e6b4b0d3255bfef95601890afd80709';
        $this->controller->setRouteParams(['hash' => $hash, 'file' => '../../../etc/passwd']);

        $response = $this->controller->serveAction();

        $this->assertEquals(404, $response->getStatusCode());
        $this->assertStringContainsString('Invalid file path', $response->getContent());
    }

    public function testServeActionReturns404ForNonExistentFile(): void
    {
        $hash = 'da39a3ee5e6b4b0d3255bfef95601890afd80709';

        // Create the hash directory but no files
        mkdir($this->testBasePath . '/' . $hash, 0755, true);

        $this->controller->setRouteParams(['hash' => $hash, 'file' => 'nonexistent.html']);

        $response = $this->controller->serveAction();

        $this->assertEquals(404, $response->getStatusCode());
        $this->assertStringContainsString('File not found', $response->getContent());
    }

    public function testServeActionServesHtmlFile(): void
    {
        $hash = 'da39a3ee5e6b4b0d3255bfef95601890afd80709';
        $hashDir = $this->testBasePath . '/' . $hash;
        mkdir($hashDir, 0755, true);
        file_put_contents($hashDir . '/index.html', '<html><body>Test</body></html>');

        $this->controller->setRouteParams(['hash' => $hash, 'file' => 'index.html']);

        $response = $this->controller->serveAction();

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertStringContainsString('text/html', $response->getHeaders()->get('Content-Type')->getFieldValue());
        $this->assertStringContainsString('<html>', $response->getContent());
        $this->assertNotNull($response->getHeaders()->get('Content-Security-Policy'));
    }

    public function testServeActionServesCssFile(): void
    {
        $hash = 'da39a3ee5e6b4b0d3255bfef95601890afd80709';
        $hashDir = $this->testBasePath . '/' . $hash;
        mkdir($hashDir . '/css', 0755, true);
        file_put_contents($hashDir . '/css/styles.css', 'body { color: red; }');

        $this->controller->setRouteParams(['hash' => $hash, 'file' => 'css/styles.css']);

        $response = $this->controller->serveAction();

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertStringContainsString('text/css', $response->getHeaders()->get('Content-Type')->getFieldValue());
    }

    public function testServeActionServesJsFile(): void
    {
        $hash = 'da39a3ee5e6b4b0d3255bfef95601890afd80709';
        $hashDir = $this->testBasePath . '/' . $hash;
        mkdir($hashDir . '/js', 0755, true);
        file_put_contents($hashDir . '/js/script.js', 'console.log("test");');

        $this->controller->setRouteParams(['hash' => $hash, 'file' => 'js/script.js']);

        $response = $this->controller->serveAction();

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertStringContainsString('javascript', $response->getHeaders()->get('Content-Type')->getFieldValue());
    }

    public function testServeActionServesImageFile(): void
    {
        $hash = 'da39a3ee5e6b4b0d3255bfef95601890afd80709';
        $hashDir = $this->testBasePath . '/' . $hash;
        mkdir($hashDir . '/images', 0755, true);
        // Create a minimal PNG
        file_put_contents($hashDir . '/images/test.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg=='));

        $this->controller->setRouteParams(['hash' => $hash, 'file' => 'images/test.png']);

        $response = $this->controller->serveAction();

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertStringContainsString('image/png', $response->getHeaders()->get('Content-Type')->getFieldValue());
    }

    public function testServeActionDefaultsToIndexHtml(): void
    {
        $hash = 'da39a3ee5e6b4b0d3255bfef95601890afd80709';
        $hashDir = $this->testBasePath . '/' . $hash;
        mkdir($hashDir, 0755, true);
        file_put_contents($hashDir . '/index.html', '<html>Default</html>');

        // Don't pass file param
        $this->controller->setRouteParams(['hash' => $hash]);

        $response = $this->controller->serveAction();

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertStringContainsString('Default', $response->getContent());
    }

    public function testServeActionSetsSecurityHeaders(): void
    {
        $hash = 'da39a3ee5e6b4b0d3255bfef95601890afd80709';
        $hashDir = $this->testBasePath . '/' . $hash;
        mkdir($hashDir, 0755, true);
        file_put_contents($hashDir . '/index.html', '<html>Test</html>');

        $this->controller->setRouteParams(['hash' => $hash, 'file' => 'index.html']);

        $response = $this->controller->serveAction();

        $headers = $response->getHeaders();
        $this->assertNotNull($headers->get('X-Frame-Options'));
        $this->assertNotNull($headers->get('X-Content-Type-Options'));
        $this->assertNotNull($headers->get('Cache-Control'));
    }

    public function testServeActionSetsCacheHeaders(): void
    {
        $hash = 'da39a3ee5e6b4b0d3255bfef95601890afd80709';
        $hashDir = $this->testBasePath . '/' . $hash;
        mkdir($hashDir, 0755, true);
        file_put_contents($hashDir . '/test.css', 'body {}');

        $this->controller->setRouteParams(['hash' => $hash, 'file' => 'test.css']);

        $response = $this->controller->serveAction();

        $cacheControl = $response->getHeaders()->get('Cache-Control')->getFieldValue();
        $this->assertStringContainsString('public', $cacheControl);
        $this->assertStringContainsString('max-age=3600', $cacheControl);
    }

    public function testServeActionSetsContentLength(): void
    {
        $hash = 'da39a3ee5e6b4b0d3255bfef95601890afd80709';
        $hashDir = $this->testBasePath . '/' . $hash;
        mkdir($hashDir, 0755, true);
        $content = 'Test content here';
        file_put_contents($hashDir . '/test.txt', $content);

        $this->controller->setRouteParams(['hash' => $hash, 'file' => 'test.txt']);

        $response = $this->controller->serveAction();

        $contentLength = $response->getHeaders()->get('Content-Length')->getFieldValue();
        $this->assertEquals(strlen($content), (int) $contentLength);
    }

    public function testServeActionUsesOctetStreamForUnknownExtension(): void
    {
        $hash = 'da39a3ee5e6b4b0d3255bfef95601890afd80709';
        $hashDir = $this->testBasePath . '/' . $hash;
        mkdir($hashDir, 0755, true);
        file_put_contents($hashDir . '/data.xyz', 'Some binary data');

        $this->controller->setRouteParams(['hash' => $hash, 'file' => 'data.xyz']);

        $response = $this->controller->serveAction();

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertStringContainsString('application/octet-stream', $response->getHeaders()->get('Content-Type')->getFieldValue());
    }

    public function testServeActionWithUppercaseHash(): void
    {
        $hash = 'DA39A3EE5E6B4B0D3255BFEF95601890AFD80709';
        $hashDir = $this->testBasePath . '/' . $hash;
        mkdir($hashDir, 0755, true);
        file_put_contents($hashDir . '/index.html', '<html>Uppercase</html>');

        $this->controller->setRouteParams(['hash' => $hash, 'file' => 'index.html']);

        $response = $this->controller->serveAction();

        $this->assertEquals(200, $response->getStatusCode());
    }

    public function testServeActionReturns404ForDirectoryAsFile(): void
    {
        $hash = 'da39a3ee5e6b4b0d3255bfef95601890afd80709';
        $hashDir = $this->testBasePath . '/' . $hash;
        mkdir($hashDir . '/subdir', 0755, true);

        $this->controller->setRouteParams(['hash' => $hash, 'file' => 'subdir']);

        $response = $this->controller->serveAction();

        $this->assertEquals(404, $response->getStatusCode());
    }

    public function testServeActionReturns404ForNonExistentHashDirectory(): void
    {
        $hash = 'da39a3ee5e6b4b0d3255bfef95601890afd80709';
        // Don't create the hash directory

        $this->controller->setRouteParams(['hash' => $hash, 'file' => 'index.html']);

        $response = $this->controller->serveAction();

        $this->assertEquals(404, $response->getStatusCode());
    }

    // =========================================================================
    // Active-document (SVG/XML) CSP hardening tests
    // =========================================================================

    public function testIsActiveDocumentTypeDetectsSvgAndXml(): void
    {
        $this->assertTrue($this->callProtectedMethod($this->controller, 'isActiveDocumentType', ['image/svg+xml']));
        $this->assertTrue($this->callProtectedMethod($this->controller, 'isActiveDocumentType', ['application/xml']));
        $this->assertFalse($this->callProtectedMethod($this->controller, 'isActiveDocumentType', ['image/png']));
        $this->assertFalse($this->callProtectedMethod($this->controller, 'isActiveDocumentType', ['text/css']));
    }

    public function testAddSecurityHeadersForSvgUsesSandboxedCsp(): void
    {
        $headers = new \Laminas\Http\Headers();
        $this->callProtectedMethod($this->controller, 'addSecurityHeaders', [$headers, 'image/svg+xml']);

        $csp = $headers->get('Content-Security-Policy');
        $this->assertNotNull($csp, 'SVG must receive a CSP');
        $value = $csp->getFieldValue();
        $this->assertStringContainsString("default-src 'none'", $value);
        $this->assertStringContainsString("script-src 'none'", $value);
        $this->assertStringContainsString('sandbox', $value);
    }

    public function testAddSecurityHeadersForXmlUsesSandboxedCsp(): void
    {
        $headers = new \Laminas\Http\Headers();
        $this->callProtectedMethod($this->controller, 'addSecurityHeaders', [$headers, 'application/xml']);

        $csp = $headers->get('Content-Security-Policy');
        $this->assertNotNull($csp);
        $this->assertStringContainsString("script-src 'none'", $csp->getFieldValue());
    }

    public function testServeActionServesSvgWithSandboxedCsp(): void
    {
        $hash = 'da39a3ee5e6b4b0d3255bfef95601890afd80709';
        $hashDir = $this->testBasePath . '/' . $hash;
        mkdir($hashDir, 0755, true);
        file_put_contents($hashDir . '/evil.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');

        $this->controller->setRouteParams(['hash' => $hash, 'file' => 'evil.svg']);

        $response = $this->controller->serveAction();

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertStringContainsString('image/svg+xml', $response->getHeaders()->get('Content-Type')->getFieldValue());
        $csp = $response->getHeaders()->get('Content-Security-Policy');
        $this->assertNotNull($csp, 'SVG served same-origin must carry a script-free CSP');
        $this->assertStringContainsString("script-src 'none'", $csp->getFieldValue());
        $this->assertStringContainsString('sandbox', $csp->getFieldValue());
    }

    public function testServeActionImageStillHasNoCsp(): void
    {
        $hash = 'da39a3ee5e6b4b0d3255bfef95601890afd80709';
        $hashDir = $this->testBasePath . '/' . $hash;
        mkdir($hashDir, 0755, true);
        file_put_contents($hashDir . '/p.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg=='));

        $this->controller->setRouteParams(['hash' => $hash, 'file' => 'p.png']);
        $response = $this->controller->serveAction();

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertNull($response->getHeaders()->get('Content-Security-Policy'));
    }
}
