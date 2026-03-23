<?php

declare(strict_types=1);

namespace ExeLearningTest\Media\FileRenderer;

use ExeLearning\Media\FileRenderer\ExeLearningRenderer;
use ExeLearning\Service\ElpFileService;
use Omeka\Api\Representation\MediaRepresentation;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Unit tests for ExeLearningRenderer.
 *
 * @covers \ExeLearning\Media\FileRenderer\ExeLearningRenderer
 */
class ExeLearningRendererTest extends TestCase
{
    private ExeLearningRenderer $renderer;
    private ElpFileService $elpService;

    protected function setUp(): void
    {
        $this->elpService = $this->createMock(ElpFileService::class);
        $this->renderer = new ExeLearningRenderer($this->elpService, $this->createMockRequest());
    }

    private function createMockRequest(): \Laminas\Http\Request
    {
        return new \Laminas\Http\Request();
    }

    private function callProtectedMethod(object $object, string $method, array $args = [])
    {
        $reflection = new ReflectionClass($object);
        $method = $reflection->getMethod($method);
        $method->setAccessible(true);
        return $method->invokeArgs($object, $args);
    }

    // =========================================================================
    // isExeLearningFile() tests
    // =========================================================================

    public function testIsExeLearningFileReturnsTrueForElpx(): void
    {
        $media = new MediaRepresentation(
            'http://example.com/file.elpx',
            'Test File',
            'content.elpx'
        );

        $result = $this->callProtectedMethod($this->renderer, 'isExeLearningFile', [$media]);
        $this->assertTrue($result);
    }

    public function testIsExeLearningFileReturnsTrueForZip(): void
    {
        $media = new MediaRepresentation(
            'http://example.com/file.zip',
            'Test File',
            'content.zip'
        );

        $result = $this->callProtectedMethod($this->renderer, 'isExeLearningFile', [$media]);
        $this->assertTrue($result);
    }

    public function testIsExeLearningFileReturnsTrueForUppercaseExtension(): void
    {
        $media = new MediaRepresentation(
            'http://example.com/file.ELPX',
            'Test File',
            'content.ELPX'
        );

        $result = $this->callProtectedMethod($this->renderer, 'isExeLearningFile', [$media]);
        $this->assertTrue($result);
    }

    public function testIsExeLearningFileReturnsFalseForPdf(): void
    {
        $media = new MediaRepresentation(
            'http://example.com/file.pdf',
            'Test File',
            'document.pdf'
        );

        $result = $this->callProtectedMethod($this->renderer, 'isExeLearningFile', [$media]);
        $this->assertFalse($result);
    }

    public function testIsExeLearningFileReturnsFalseForEmptyFilename(): void
    {
        $media = new MediaRepresentation(
            'http://example.com/file.elpx',
            'Test File',
            ''
        );

        $result = $this->callProtectedMethod($this->renderer, 'isExeLearningFile', [$media]);
        $this->assertFalse($result);
    }

    public function testIsExeLearningFileReturnsFalseForJpg(): void
    {
        $media = new MediaRepresentation(
            'http://example.com/image.jpg',
            'Test Image',
            'image.jpg'
        );

        $result = $this->callProtectedMethod($this->renderer, 'isExeLearningFile', [$media]);
        $this->assertFalse($result);
    }

    public function testIsExeLearningFileReturnsFalseForHtml(): void
    {
        $media = new MediaRepresentation(
            'http://example.com/page.html',
            'Test Page',
            'page.html'
        );

        $result = $this->callProtectedMethod($this->renderer, 'isExeLearningFile', [$media]);
        $this->assertFalse($result);
    }

    // =========================================================================
    // getConfig() tests
    // =========================================================================

    public function testGetConfigReturnsDefaults(): void
    {
        // Use stub PhpRenderer which throws exception in getHelperPluginManager
        $view = new \Laminas\View\Renderer\PhpRenderer();

        $config = $this->callProtectedMethod($this->renderer, 'getConfig', [$view]);

        $this->assertIsArray($config);
        $this->assertEquals(600, $config['height']);
        $this->assertTrue($config['showEditButton']);
    }

    // =========================================================================
    // Constructor tests
    // =========================================================================

    public function testConstructorSetsElpService(): void
    {
        $reflection = new ReflectionClass($this->renderer);
        $property = $reflection->getProperty('elpService');
        $property->setAccessible(true);

        $this->assertSame($this->elpService, $property->getValue($this->renderer));
    }

    // =========================================================================
    // canEdit() tests
    // =========================================================================

    public function testCanEditReturnsFalseOnException(): void
    {
        // Use stub PhpRenderer which throws exception when accessing helpers
        $view = new \Laminas\View\Renderer\PhpRenderer();

        $media = new MediaRepresentation(
            'http://example.com/file.elpx',
            'Test File',
            'content.elpx'
        );

        $result = $this->callProtectedMethod($this->renderer, 'canEdit', [$view, $media]);
        $this->assertFalse($result);
    }

    // =========================================================================
    // File extension edge cases
    // =========================================================================

    /**
     * @dataProvider fileExtensionProvider
     */
    public function testIsExeLearningFileWithVariousExtensions(string $filename, bool $expected): void
    {
        $media = new MediaRepresentation(
            'http://example.com/' . $filename,
            'Test File',
            $filename
        );

        $result = $this->callProtectedMethod($this->renderer, 'isExeLearningFile', [$media]);
        $this->assertEquals($expected, $result);
    }

    public function fileExtensionProvider(): array
    {
        return [
            'elpx lowercase' => ['file.elpx', true],
            'elpx uppercase' => ['FILE.ELPX', true],
            'elpx mixed case' => ['File.ElPx', true],
            'zip lowercase' => ['file.zip', true],
            'zip uppercase' => ['FILE.ZIP', true],
            'pdf' => ['file.pdf', false],
            'doc' => ['file.doc', false],
            'docx' => ['file.docx', false],
            'txt' => ['file.txt', false],
            'no extension' => ['file', false],
            'multiple dots' => ['file.name.elpx', true],
            'hidden file elpx' => ['.hidden.elpx', true],
        ];
    }

    // =========================================================================
    // renderFallback() tests
    // =========================================================================

    public function testRenderFallbackReturnsHtml(): void
    {
        $view = new \Laminas\View\Renderer\PhpRenderer();
        $media = new MediaRepresentation(
            'http://example.com/files/original/test.elpx',
            'Test eXeLearning File',
            'test.elpx',
            1
        );

        $result = $this->callProtectedMethod($this->renderer, 'renderFallback', [$view, $media]);

        $this->assertIsString($result);
        $this->assertStringContainsString('exelearning-fallback', $result);
        $this->assertStringContainsString('Download', $result);
        $this->assertStringContainsString('test.elpx', $result);
    }

    public function testRenderFallbackContainsDownloadLink(): void
    {
        $view = new \Laminas\View\Renderer\PhpRenderer();
        $media = new MediaRepresentation(
            'http://example.com/files/original/content.elpx',
            'My Content',
            'content.elpx',
            42
        );

        $result = $this->callProtectedMethod($this->renderer, 'renderFallback', [$view, $media]);

        $this->assertStringContainsString('href="http://example.com/files/original/content.elpx"', $result);
        $this->assertStringContainsString('download', $result);
    }

    // =========================================================================
    // render() tests - using mocked service
    // =========================================================================

    public function testRenderReturnsHtmlForNonExeLearningFile(): void
    {
        $view = new \Laminas\View\Renderer\PhpRenderer();
        $media = new MediaRepresentation(
            'http://example.com/file.pdf',
            'Test PDF',
            'document.pdf',
            1
        );

        $result = $this->renderer->render($view, $media);

        // Should render fallback since it's not an eXeLearning file
        $this->assertStringContainsString('exelearning-fallback', $result);
    }

    public function testRenderReturnsHtmlForElpxWithoutHash(): void
    {
        // Create a mock ElpFileService that returns no hash
        $elpService = $this->createMock(ElpFileService::class);
        $elpService->method('getMediaHash')->willReturn(null);
        $elpService->method('hasPreview')->willReturn(false);

        $renderer = new ExeLearningRenderer($elpService, $this->createMockRequest());

        $view = new \Laminas\View\Renderer\PhpRenderer();
        $media = new MediaRepresentation(
            'http://example.com/file.elpx',
            'Test File',
            'content.elpx',
            1,
            []
        );

        $result = $renderer->render($view, $media);

        // Should render fallback since there's no hash
        $this->assertStringContainsString('exelearning-fallback', $result);
    }

    public function testRenderReturnsIframeForValidElpx(): void
    {
        $hash = 'da39a3ee5e6b4b0d3255bfef95601890afd80709';

        // Create a mock ElpFileService that returns valid data
        $elpService = $this->createMock(ElpFileService::class);
        $elpService->method('getMediaHash')->willReturn($hash);
        $elpService->method('hasPreview')->willReturn(true);

        $renderer = new ExeLearningRenderer($elpService, $this->createMockRequest());

        $view = new \Laminas\View\Renderer\PhpRenderer();
        $media = new MediaRepresentation(
            'http://example.com/file.elpx',
            'Test eXeLearning Content',
            'content.elpx',
            1,
            [
                'exelearning_extracted_hash' => $hash,
                'exelearning_has_preview' => '1',
            ]
        );

        $result = $renderer->render($view, $media);

        // Should render with iframe
        $this->assertStringContainsString('exelearning-viewer', $result);
        $this->assertStringContainsString('<iframe', $result);
        $this->assertStringContainsString('sandbox=', $result);
        $this->assertStringContainsString('Test eXeLearning Content', $result);
    }

    public function testRenderIncludesSecuritySandbox(): void
    {
        $hash = 'da39a3ee5e6b4b0d3255bfef95601890afd80709';

        $elpService = $this->createMock(ElpFileService::class);
        $elpService->method('getMediaHash')->willReturn($hash);
        $elpService->method('hasPreview')->willReturn(true);

        $renderer = new ExeLearningRenderer($elpService, $this->createMockRequest());

        $view = new \Laminas\View\Renderer\PhpRenderer();
        $media = new MediaRepresentation(
            'http://example.com/file.elpx',
            'Test',
            'test.elpx',
            1,
            [
                'exelearning_extracted_hash' => $hash,
                'exelearning_has_preview' => '1',
            ]
        );

        $result = $renderer->render($view, $media);

        // Check that security sandbox attributes are present
        $this->assertStringContainsString('sandbox="allow-scripts allow-popups allow-popups-to-escape-sandbox"', $result);
        $this->assertStringContainsString('referrerpolicy="no-referrer"', $result);
    }

    public function testRenderIncludesDownloadButton(): void
    {
        $hash = 'da39a3ee5e6b4b0d3255bfef95601890afd80709';

        $elpService = $this->createMock(ElpFileService::class);
        $elpService->method('getMediaHash')->willReturn($hash);
        $elpService->method('hasPreview')->willReturn(true);

        $renderer = new ExeLearningRenderer($elpService, $this->createMockRequest());

        $view = new \Laminas\View\Renderer\PhpRenderer();
        $media = new MediaRepresentation(
            'http://example.com/original/file.elpx',
            'Test',
            'test.elpx',
            1,
            [
                'exelearning_extracted_hash' => $hash,
                'exelearning_has_preview' => '1',
            ]
        );

        $result = $renderer->render($view, $media);

        $this->assertStringContainsString('exelearning-download-btn', $result);
        $this->assertStringContainsString('Download', $result);
    }

    public function testRenderIncludesFullscreenButton(): void
    {
        $hash = 'da39a3ee5e6b4b0d3255bfef95601890afd80709';

        $elpService = $this->createMock(ElpFileService::class);
        $elpService->method('getMediaHash')->willReturn($hash);
        $elpService->method('hasPreview')->willReturn(true);

        $renderer = new ExeLearningRenderer($elpService, $this->createMockRequest());

        $view = new \Laminas\View\Renderer\PhpRenderer();
        $media = new MediaRepresentation(
            'http://example.com/file.elpx',
            'Test',
            'test.elpx',
            1,
            [
                'exelearning_extracted_hash' => $hash,
                'exelearning_has_preview' => '1',
            ]
        );

        $result = $renderer->render($view, $media);

        $this->assertStringContainsString('exelearning-fullscreen-btn', $result);
        $this->assertStringContainsString('Fullscreen', $result);
    }

    // =========================================================================
    // Edge cases
    // =========================================================================

    public function testRenderHandlesExceptionGracefully(): void
    {
        // Create a mock that throws an exception
        $elpService = $this->createMock(ElpFileService::class);
        $elpService->method('getMediaHash')->willThrowException(new \Exception('Test error'));

        $renderer = new ExeLearningRenderer($elpService, $this->createMockRequest());

        $view = new \Laminas\View\Renderer\PhpRenderer();
        $media = new MediaRepresentation(
            'http://example.com/file.elpx',
            'Test',
            'test.elpx',
            1
        );

        $result = $renderer->render($view, $media);

        // Should gracefully fall back to download link
        $this->assertStringContainsString('exelearning-fallback', $result);
    }

    // =========================================================================
    // canEdit() additional tests
    // =========================================================================

    public function testCanEditReturnsTrueWhenUserAllowed(): void
    {
        // Create mock acl that returns true
        $mockAcl = new class {
            public function userIsAllowed($resource, $privilege): bool {
                return true;
            }
        };

        // Create mock plugin manager that returns the acl
        $mockPluginManager = new class($mockAcl) {
            private $acl;
            public function __construct($acl) { $this->acl = $acl; }
            public function get($name) {
                if ($name === 'acl') return $this->acl;
                throw new \Exception("Unknown helper: $name");
            }
        };

        // Create mock view
        $view = new class($mockPluginManager) extends \Laminas\View\Renderer\PhpRenderer {
            private $pm;
            public function __construct($pm) { $this->pm = $pm; }
            public function getHelperPluginManager() { return $this->pm; }
        };

        $media = new MediaRepresentation(
            'http://example.com/file.elpx',
            'Test File',
            'content.elpx',
            1
        );

        $result = $this->callProtectedMethod($this->renderer, 'canEdit', [$view, $media]);
        $this->assertTrue($result);
    }

    public function testCanEditReturnsFalseWhenUserNotAllowed(): void
    {
        // Create mock acl that returns false
        $mockAcl = new class {
            public function userIsAllowed($resource, $privilege): bool {
                return false;
            }
        };

        $mockPluginManager = new class($mockAcl) {
            private $acl;
            public function __construct($acl) { $this->acl = $acl; }
            public function get($name) {
                if ($name === 'acl') return $this->acl;
                throw new \Exception("Unknown helper: $name");
            }
        };

        $view = new class($mockPluginManager) extends \Laminas\View\Renderer\PhpRenderer {
            private $pm;
            public function __construct($pm) { $this->pm = $pm; }
            public function getHelperPluginManager() { return $this->pm; }
        };

        $media = new MediaRepresentation(
            'http://example.com/file.elpx',
            'Test File',
            'content.elpx',
            1
        );

        $result = $this->callProtectedMethod($this->renderer, 'canEdit', [$view, $media]);
        $this->assertFalse($result);
    }

    // =========================================================================
    // getConfig() additional tests
    // =========================================================================

    public function testGetConfigUsesSettingsWhenAvailable(): void
    {
        // Create mock setting helper
        $mockSetting = new class {
            public function __invoke($key, $default = null) {
                $settings = [
                    'exelearning_viewer_height' => 800,
                    'exelearning_show_edit_button' => false,
                ];
                return $settings[$key] ?? $default;
            }
        };

        $mockPluginManager = new class($mockSetting) {
            private $setting;
            public function __construct($setting) { $this->setting = $setting; }
            public function get($name) {
                if ($name === 'setting') return $this->setting;
                throw new \Exception("Unknown helper: $name");
            }
        };

        $view = new class($mockPluginManager) extends \Laminas\View\Renderer\PhpRenderer {
            private $pm;
            public function __construct($pm) { $this->pm = $pm; }
            public function getHelperPluginManager() { return $this->pm; }
        };

        $config = $this->callProtectedMethod($this->renderer, 'getConfig', [$view]);

        $this->assertEquals(800, $config['height']);
        $this->assertFalse($config['showEditButton']);
    }

    // =========================================================================
    // render() with hasPreview=false tests
    // =========================================================================

    public function testRenderFallbackWhenHasHashButNoPreview(): void
    {
        $hash = 'da39a3ee5e6b4b0d3255bfef95601890afd80709';

        $elpService = $this->createMock(ElpFileService::class);
        $elpService->method('getMediaHash')->willReturn($hash);
        $elpService->method('hasPreview')->willReturn(false);

        $renderer = new ExeLearningRenderer($elpService, $this->createMockRequest());

        $view = new \Laminas\View\Renderer\PhpRenderer();
        $media = new MediaRepresentation(
            'http://example.com/file.elpx',
            'Test',
            'content.elpx',
            1
        );

        $result = $renderer->render($view, $media);

        // Should render fallback
        $this->assertStringContainsString('exelearning-fallback', $result);
    }

    // =========================================================================
    // buildContentUrl() tests
    // =========================================================================

    public function testBuildContentUrlIncludesNonStandardPort(): void
    {
        $uri = new class extends \Laminas\Uri\Http {
            public function getPort(): ?int { return 8080; }
        };
        $request = new class($uri) extends \Laminas\Http\Request {
            private $customUri;
            public function __construct($uri) { $this->customUri = $uri; }
            public function getUri(): \Laminas\Uri\Http { return $this->customUri; }
        };

        $renderer = new ExeLearningRenderer($this->elpService, $request);

        $url = $this->callProtectedMethod($renderer, 'buildContentUrl', ['abc123def456789012345678901234567890abcd']);

        $this->assertStringContainsString(':8080', $url);
        $this->assertStringContainsString('/exelearning/content/abc123def456789012345678901234567890abcd/index.html', $url);
    }

    public function testBuildContentUrlStripsPlaygroundPrefixFromUriPath(): void
    {
        $uri = new class extends \Laminas\Uri\Http {
            public function getPath(): string { return '/omeka-s-playground/playground/abc123/php83/admin/media/3'; }
        };
        $request = new class($uri) extends \Laminas\Http\Request {
            private $customUri;
            public function __construct($uri) { $this->customUri = $uri; }
            public function getUri(): \Laminas\Uri\Http { return $this->customUri; }
        };

        $renderer = new ExeLearningRenderer($this->elpService, $request);
        $url = $this->callProtectedMethod($renderer, 'buildContentUrl', ['abc123def456789012345678901234567890abcd']);

        $this->assertStringContainsString('/omeka-s-playground/playground/abc123/php83/exelearning/content/', $url);
        $this->assertStringNotContainsString('/admin/', $url);
    }

    public function testExtractBasePathWithAdminRoute(): void
    {
        $basePath = $this->callProtectedMethod($this->renderer, 'extractBasePath', ['/playground/uuid/php83/admin/media/3']);
        $this->assertSame('/playground/uuid/php83', $basePath);
    }

    public function testExtractBasePathWithPublicRoute(): void
    {
        $basePath = $this->callProtectedMethod($this->renderer, 'extractBasePath', ['/playground/uuid/php83/s/mysite/item/1']);
        $this->assertSame('/playground/uuid/php83', $basePath);
    }

    public function testExtractBasePathWithNoKnownMarker(): void
    {
        $basePath = $this->callProtectedMethod($this->renderer, 'extractBasePath', ['/some/unknown/path']);
        $this->assertSame('', $basePath);
    }

    // =========================================================================
    // isTeacherModeVisible() tests
    // =========================================================================

    public function testIsTeacherModeVisibleReturnsFalseWhenSetToZero(): void
    {
        $media = new MediaRepresentation(
            'http://example.com/file.elpx',
            'Test',
            'test.elpx',
            1,
            ['exelearning_teacher_mode_visible' => '0']
        );

        $result = $this->callProtectedMethod($this->renderer, 'isTeacherModeVisible', [$media]);
        $this->assertFalse($result);
    }

    public function testIsTeacherModeVisibleReturnsTrueWhenSetToOne(): void
    {
        $media = new MediaRepresentation(
            'http://example.com/file.elpx',
            'Test',
            'test.elpx',
            1,
            ['exelearning_teacher_mode_visible' => '1']
        );

        $result = $this->callProtectedMethod($this->renderer, 'isTeacherModeVisible', [$media]);
        $this->assertTrue($result);
    }
}
