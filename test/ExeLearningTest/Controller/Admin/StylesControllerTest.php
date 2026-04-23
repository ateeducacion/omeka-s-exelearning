<?php
declare(strict_types=1);

namespace ExeLearningTest\Controller\Admin;

use ExeLearning\Controller\Admin\StylesController;
use ExeLearning\Service\StylesService;
use Omeka\Settings\Settings;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * @covers \ExeLearning\Controller\Admin\StylesController
 */
class StylesControllerTest extends TestCase
{
    private string $tmpRoot;
    private StylesService $svc;
    private StylesController $controller;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpRoot = sys_get_temp_dir() . '/exelearning-adm-' . uniqid();
        mkdir($this->tmpRoot . '/files', 0755, true);
        mkdir($this->tmpRoot . '/module', 0755, true);
        $this->svc = new StylesService(new Settings(), $this->tmpRoot . '/files', $this->tmpRoot . '/module');
        $this->controller = new StylesController($this->svc);
    }

    protected function tearDown(): void
    {
        StylesService::recursiveDelete($this->tmpRoot);
        parent::tearDown();
    }

    public function testProcessUploadsReturnsEmptySummaryWithNoFiles(): void
    {
        $summary = $this->invokePrivate('processUploads', [null]);
        $this->assertSame([], $summary['installed']);
        $this->assertSame([], $summary['errors']);
    }

    public function testProcessUploadsNormalizesSingleFileUpload(): void
    {
        $zip = $this->makeZip('acme', 'body{}');
        $summary = $this->invokePrivate('processUploads', [[
            'tmp_name' => $zip,
            'name'     => 'acme.zip',
            'error'    => UPLOAD_ERR_OK,
        ]]);
        $this->assertSame(['Acme'], $summary['installed']);
        $this->assertSame([], $summary['errors']);
        @unlink($zip);
    }

    public function testProcessUploadsNormalizesMultiFileUpload(): void
    {
        $zip1 = $this->makeZip('alpha', 'a{}');
        $zip2 = $this->makeZip('beta', 'b{}');
        $summary = $this->invokePrivate('processUploads', [[
            'tmp_name' => [$zip1, $zip2],
            'name'     => ['alpha.zip', 'beta.zip'],
            'error'    => [UPLOAD_ERR_OK, UPLOAD_ERR_OK],
        ]]);
        $this->assertCount(2, $summary['installed']);
        $this->assertEmpty($summary['errors']);
        @unlink($zip1);
        @unlink($zip2);
    }

    public function testProcessUploadsRecordsErrorsForBrokenUploads(): void
    {
        $summary = $this->invokePrivate('processUploads', [[
            'tmp_name' => ['', ''],
            'name'     => ['a.zip', 'b.zip'],
            'error'    => [UPLOAD_ERR_NO_FILE, UPLOAD_ERR_PARTIAL],
        ]]);
        $this->assertEmpty($summary['installed']);
        $this->assertCount(2, $summary['errors']);
        foreach ($summary['errors'] as $msg) {
            $this->assertStringStartsWith('Upload failed:', $msg);
        }
    }

    public function testProcessUploadsCapturesServiceFailures(): void
    {
        // Invalid ZIP (no config.xml).
        $zip = tempnam(sys_get_temp_dir(), 'bad') . '.zip';
        @unlink($zip);
        $z = new \ZipArchive();
        $z->open($zip, \ZipArchive::CREATE);
        $z->addFromString('style.css', '.x{}');
        $z->close();

        $summary = $this->invokePrivate('processUploads', [[
            'tmp_name' => $zip,
            'name'     => 'bad.zip',
            'error'    => UPLOAD_ERR_OK,
        ]]);
        $this->assertEmpty($summary['installed']);
        $this->assertCount(1, $summary['errors']);
        $this->assertStringContainsString('bad.zip', $summary['errors'][0]);
        @unlink($zip);
    }

    // ------------------------------------------------------------------
    // Action-level tests via a controller subclass that overrides the
    // Laminas MVC plugin helpers. We keep them deliberately small — the
    // real work happens in the service, which is exercised elsewhere.
    // ------------------------------------------------------------------

    public function testIndexActionDeniesWhenUserIsNotAllowed(): void
    {
        $ctrl = new TestableStylesController($this->svc, false);
        $result = $ctrl->indexAction();
        $this->assertSame(['redirect' => 'admin'], $ctrl->lastRedirect);
    }

    public function testIndexActionRendersViewWhenAllowed(): void
    {
        $ctrl = new TestableStylesController($this->svc, true);
        $ctrl->stubRequestIsPost = false;
        $result = $ctrl->indexAction();
        $this->assertInstanceOf(\Laminas\View\Model\ViewModel::class, $result);
        $vars = $result->getVariables();
        $this->assertArrayHasKey('form', $vars);
        $this->assertArrayHasKey('builtins', $vars);
        $this->assertArrayHasKey('uploaded', $vars);
        $this->assertSame('exelearning/admin/styles/index', $result->getTemplate());
    }

    public function testToggleUploadedActionRoutesThroughService(): void
    {
        $zip = $this->makeZip('acme', 'body{}');
        $this->svc->installFromZip($zip, 'acme.zip');
        $this->assertTrue($this->svc->getRegistry()['uploaded']['acme']['enabled']);

        $ctrl = new TestableStylesController($this->svc, true);
        $ctrl->stubRequestIsPost = true;
        $ctrl->stubPost = ['slug' => 'acme', 'enabled' => 0];
        $ctrl->toggleUploadedAction();

        $this->assertFalse($this->svc->getRegistry()['uploaded']['acme']['enabled']);
        $this->assertSame(['redirect' => 'admin/exelearning-styles'], $ctrl->lastRedirect);
        @unlink($zip);
    }

    public function testToggleUploadedActionShortCircuitsOnGet(): void
    {
        $ctrl = new TestableStylesController($this->svc, true);
        $ctrl->stubRequestIsPost = false;
        $ctrl->toggleUploadedAction();
        $this->assertSame(['redirect' => 'admin/exelearning-styles'], $ctrl->lastRedirect);
    }

    public function testToggleBuiltinActionPropagatesToService(): void
    {
        $ctrl = new TestableStylesController($this->svc, true);
        $ctrl->stubRequestIsPost = true;
        $ctrl->stubPost = ['id' => 'zen', 'enabled' => 0];
        $ctrl->toggleBuiltinAction();
        $this->assertSame(['zen'], $this->svc->getRegistry()['disabled_builtins']);
    }

    public function testDeleteActionRemovesUploadedAndFlashes(): void
    {
        $zip = $this->makeZip('goodbye', 'x{}');
        $this->svc->installFromZip($zip, 'goodbye.zip');
        $this->assertArrayHasKey('goodbye', $this->svc->getRegistry()['uploaded']);

        $ctrl = new TestableStylesController($this->svc, true);
        $ctrl->stubRequestIsPost = true;
        $ctrl->stubPost = ['slug' => 'goodbye'];
        $ctrl->deleteAction();

        $this->assertArrayNotHasKey('goodbye', $this->svc->getRegistry()['uploaded']);
        $this->assertContains('Style deleted.', $ctrl->messengerSuccess);
        @unlink($zip);
    }

    public function testDeleteActionDeniedWhenNotAllowed(): void
    {
        $ctrl = new TestableStylesController($this->svc, false);
        $ctrl->stubRequestIsPost = true;
        $ctrl->stubPost = ['slug' => 'whatever'];
        $ctrl->deleteAction();
        $this->assertSame(['redirect' => 'admin/exelearning-styles'], $ctrl->lastRedirect);
    }

    public function testToggleBlockImportActionPropagates(): void
    {
        $ctrl = new TestableStylesController($this->svc, true);
        $ctrl->stubRequestIsPost = true;
        $ctrl->stubPost = ['enabled' => 1];
        $ctrl->toggleBlockImportAction();
        $this->assertTrue($this->svc->isImportBlocked());

        $ctrl->stubPost = ['enabled' => 0];
        $ctrl->toggleBlockImportAction();
        $this->assertFalse($this->svc->isImportBlocked());
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function invokePrivate(string $name, array $args)
    {
        $ref = new ReflectionClass($this->controller);
        $method = $ref->getMethod($name);
        $method->setAccessible(true);
        return $method->invokeArgs($this->controller, $args);
    }

    private function makeZip(string $slug, string $cssBody = 'body{}'): string
    {
        $path = tempnam(sys_get_temp_dir(), 'admstyle') . '.zip';
        @unlink($path);
        $zip = new \ZipArchive();
        $zip->open($path, \ZipArchive::CREATE);
        $zip->addFromString('config.xml',
            '<?xml version="1.0"?><theme><name>' . $slug . '</name>'
            . '<title>' . ucfirst($slug) . '</title><version>1.0</version></theme>'
        );
        $zip->addFromString('style.css', $cssBody);
        $zip->close();
        return $path;
    }
}
