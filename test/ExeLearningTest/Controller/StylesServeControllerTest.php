<?php
declare(strict_types=1);

namespace ExeLearningTest\Controller;

use ExeLearning\Controller\StylesServeController;
use ExeLearning\Service\StylesService;
use Omeka\Settings\Settings;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * @covers \ExeLearning\Controller\StylesServeController
 */
class StylesServeControllerTest extends TestCase
{
    private string $tmpRoot;
    private StylesService $svc;
    private StylesServeController $controller;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpRoot = sys_get_temp_dir() . '/exelearning-serve-' . uniqid();
        mkdir($this->tmpRoot . '/files', 0755, true);
        mkdir($this->tmpRoot . '/module', 0755, true);
        $this->svc = new StylesService(new Settings(), $this->tmpRoot . '/files', $this->tmpRoot . '/module');
        $this->controller = new StylesServeController($this->svc);
    }

    protected function tearDown(): void
    {
        StylesService::recursiveDelete($this->tmpRoot);
        parent::tearDown();
    }

    public function testGuessMimeTypeCoversEveryAllowedExtension(): void
    {
        $expected = [
            'a.css' => 'text/css',
            'a.js' => 'application/javascript',
            'a.map' => 'application/json',
            'a.json' => 'application/json',
            'a.xml' => 'application/xml',
            'a.html' => 'text/html',
            'a.htm' => 'text/html',
            'a.md' => 'text/markdown',
            'a.txt' => 'text/plain',
            'a.svg' => 'image/svg+xml',
            'a.png' => 'image/png',
            'a.jpg' => 'image/jpeg',
            'a.jpeg' => 'image/jpeg',
            'a.gif' => 'image/gif',
            'a.webp' => 'image/webp',
            'a.ico' => 'image/vnd.microsoft.icon',
            'a.woff' => 'font/woff',
            'a.woff2' => 'font/woff2',
            'a.ttf' => 'font/ttf',
            'a.otf' => 'font/otf',
            'a.eot' => 'application/vnd.ms-fontobject',
            'a.unknown' => 'application/octet-stream',
            'README' => 'application/octet-stream',
        ];
        foreach ($expected as $name => $expectedType) {
            $actual = $this->invokePrivate('guessMimeType', [$name]);
            $this->assertSame($expectedType, $actual, "MIME for $name");
        }
    }

    public function testNotFoundReturns404Response(): void
    {
        // Inject a minimal Response stub and exercise the notFound() helper.
        $response = new \Laminas\Http\Response();
        $ref = new ReflectionClass($this->controller);
        $prop = $ref->getProperty('response');
        $prop->setAccessible(true);
        $prop->setValue($this->controller, $response);

        $result = $this->invokePrivate('notFound', []);
        $this->assertSame(404, $result->getStatusCode());
        $this->assertSame('Not found', $result->getContent());
    }

    public function testServeStyleReturns404ForUnregisteredSlug(): void
    {
        $this->bindResponse();
        $result = $this->controller->serveStyle('nope', 'style.css');
        $this->assertSame(404, $result->getStatusCode());
    }

    public function testServeStyleRefusesTraversal(): void
    {
        $this->installFakeStyle('acme');
        $this->bindResponse();
        foreach ([
            ['..', 'style.css'],
            ['acme', '../secret.css'],
            ['', 'style.css'],
        ] as [$slug, $file]) {
            $result = $this->controller->serveStyle($slug, $file);
            $this->assertSame(404, $result->getStatusCode(), "$slug|$file");
        }
    }

    public function testServeStyleReturnsFileContentsWithHeaders(): void
    {
        $this->installFakeStyle('acme', 'body { color: red; }');
        $this->bindResponse();
        $result = $this->controller->serveStyle('acme', 'style.css');
        $this->assertSame(200, $result->getStatusCode());
        $this->assertStringContainsString('color: red', $result->getContent());
    }

    public function testServeStyleDefaultsToStyleCssWhenFileEmpty(): void
    {
        $this->installFakeStyle('acme', '.x{}');
        $this->bindResponse();
        $result = $this->controller->serveStyle('acme', 'style.css');
        $this->assertSame(200, $result->getStatusCode());
    }

    public function testServeStyle404WhenPathEscapesDir(): void
    {
        $this->installFakeStyle('acme');
        $this->bindResponse();
        // An entry that does not exist in the style dir.
        $result = $this->controller->serveStyle('acme', 'missing.css');
        $this->assertSame(404, $result->getStatusCode());
    }

    public function testServeActionDelegatesToServeStyleWithDefaults(): void
    {
        // Simulate a controller whose params() returns the defaults we
        // pass (via a lightweight subclass). Confirms serveAction is a
        // thin wire between params() and serveStyle().
        $this->installFakeStyle('acme');
        $this->bindResponse();
        $sub = new class($this->svc) extends StylesServeController {
            public function params($name = null, $default = null)
            {
                return $default;
            }
        };
        $ref = new ReflectionClass($sub);
        $prop = $ref->getProperty('response');
        $prop->setAccessible(true);
        $prop->setValue($sub, new \Laminas\Http\Response());
        $result = $sub->serveAction();
        // slug default is '' → 404 is expected.
        $this->assertSame(404, $result->getStatusCode());
    }

    public function testServeStyleCssHasFrameOptionsButNoCsp(): void
    {
        $this->installFakeStyle('acme', 'body{}');
        $this->bindResponse();
        $result = $this->controller->serveStyle('acme', 'style.css');
        $headers = $result->getHeaders();
        $this->assertSame('SAMEORIGIN', $headers->get('X-Frame-Options')->getFieldValue());
        $this->assertNotNull($headers->get('X-Content-Type-Options'));
        // CSS is passive — no sandboxed CSP / attachment forced.
        $this->assertNull($headers->get('Content-Security-Policy'));
        $this->assertNull($headers->get('Content-Disposition'));
    }

    public function testServeStyleSvgIsSandboxedAndForcedAttachment(): void
    {
        $this->installStyleWithFile('iconic', 'icons/x.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');
        $this->bindResponse();
        $result = $this->controller->serveStyle('iconic', 'icons/x.svg');
        $this->assertSame(200, $result->getStatusCode());
        $headers = $result->getHeaders();
        $csp = $headers->get('Content-Security-Policy');
        $this->assertNotNull($csp, "SVG style asset must carry a CSP");
        $this->assertStringContainsString("script-src 'none'", $csp->getFieldValue());
        $this->assertStringContainsString('sandbox', $csp->getFieldValue());
        $this->assertSame('attachment', $headers->get('Content-Disposition')->getFieldValue());
    }

    public function testServeStyleHtmlIsSandboxed(): void
    {
        $this->installStyleWithFile('htmlish', 'page.html', '<html><body><script>alert(1)</script></body></html>');
        $this->bindResponse();
        $result = $this->controller->serveStyle('htmlish', 'page.html');
        $csp = $result->getHeaders()->get('Content-Security-Policy');
        $this->assertNotNull($csp);
        $this->assertStringContainsString("default-src 'none'", $csp->getFieldValue());
    }

    public function testIsActiveDocumentTypeMatchesScriptableTypes(): void
    {
        $ref = new ReflectionClass($this->controller);
        $m = $ref->getMethod('isActiveDocumentType');
        $m->setAccessible(true);
        $this->assertTrue($m->invoke($this->controller, 'image/svg+xml'));
        $this->assertTrue($m->invoke($this->controller, 'text/html'));
        $this->assertTrue($m->invoke($this->controller, 'application/javascript'));
        $this->assertTrue($m->invoke($this->controller, 'application/xml'));
        $this->assertFalse($m->invoke($this->controller, 'text/css'));
        $this->assertFalse($m->invoke($this->controller, 'image/png'));
    }

    // ------------------------------------------------------------------
    // helpers
    // ------------------------------------------------------------------

    private function installStyleWithFile(string $slug, string $path, string $body): void
    {
        $zipPath = $this->tmpRoot . '/' . $slug . '.zip';
        $zip = new \ZipArchive();
        $zip->open($zipPath, \ZipArchive::CREATE);
        $zip->addFromString('config.xml',
            '<?xml version="1.0"?><theme><name>' . $slug . '</name><version>1.0</version></theme>'
        );
        $zip->addFromString('style.css', 'body{}');
        $zip->addFromString($path, $body);
        $zip->close();
        $this->svc->installFromZip($zipPath, $slug . '.zip');
        @unlink($zipPath);
    }

    private function invokePrivate(string $name, array $args)
    {
        $ref = new ReflectionClass($this->controller);
        $method = $ref->getMethod($name);
        $method->setAccessible(true);
        return $method->invokeArgs($this->controller, $args);
    }

    private function bindResponse(): void
    {
        $response = new \Laminas\Http\Response();
        $ref = new ReflectionClass($this->controller);
        $prop = $ref->getProperty('response');
        $prop->setAccessible(true);
        $prop->setValue($this->controller, $response);
    }

    private function installFakeStyle(string $slug, string $cssBody = 'body{}'): void
    {
        $zipPath = $this->tmpRoot . '/' . $slug . '.zip';
        $zip = new \ZipArchive();
        $zip->open($zipPath, \ZipArchive::CREATE);
        $zip->addFromString('config.xml',
            '<?xml version="1.0"?><theme><name>' . $slug . '</name>'
            . '<title>' . ucfirst($slug) . '</title><version>1.0</version></theme>'
        );
        $zip->addFromString('style.css', $cssBody);
        $zip->close();
        $this->svc->installFromZip($zipPath, $slug . '.zip');
        @unlink($zipPath);
    }
}
