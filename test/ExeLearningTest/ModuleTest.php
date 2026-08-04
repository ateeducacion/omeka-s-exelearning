<?php

declare(strict_types=1);

namespace ExeLearningTest;

use ExeLearning\Service\ElpFileService;
use ExeLearningTest\Doubles\FakeApiManager;
use ExeLearningTest\Doubles\FakeApiRequest;
use ExeLearningTest\Doubles\FakeApiResponse;
use ExeLearningTest\Doubles\FakeApplication;
use ExeLearningTest\Doubles\FakeConfigController;
use ExeLearningTest\Doubles\FakeElpFileService;
use ExeLearningTest\Doubles\FakeHttpRequest;
use ExeLearningTest\Doubles\FakeItem;
use ExeLearningTest\Doubles\FakeMediaEntity;
use ExeLearningTest\Doubles\FakeSourceOnlyEntity;
use ExeLearningTest\Doubles\RecordingSettings;
use ExeLearningTest\Doubles\RecordingSharedEventManager;
use Laminas\EventManager\Event;
use Laminas\Log\Logger;
use Laminas\View\Renderer\PhpRenderer;
use Omeka\Mvc\Controller\Plugin\Messenger;
use Omeka\Settings\Settings;
use PHPUnit\Framework\TestCase;

/**
 * Covers Module.php: lifecycle hooks, listener registration, the Omeka event
 * handlers and the path/URL helpers.
 *
 * Module was outside the coverage gate until the stubs under test/Stubs/Omeka/
 * made it loadable without an Omeka runtime -- see ADR-0002. Collaborators are
 * registered in a TestServiceLocator under the same service names production
 * uses, so a handler asking for something the test did not provide fails loudly
 * instead of taking a different branch.
 */
class ModuleTest extends TestCase
{
    /** @var string|null */
    private $tmpDir = null;

    protected function setUp(): void
    {
        Messenger::reset();
    }

    protected function tearDown(): void
    {
        if ($this->tmpDir !== null && is_dir($this->tmpDir)) {
            $this->removeTree($this->tmpDir);
        }
        $this->tmpDir = null;
    }

    // ---------------------------------------------------------------- config

    public function testGetConfigReturnsModuleConfiguration(): void
    {
        $module = new TestableModule();
        $config = $module->getConfig();

        $this->assertIsArray($config);
        $this->assertArrayHasKey('controllers', $config);
        $this->assertArrayHasKey('router', $config);
    }

    public function testGetDataPathPointsInsideTheModule(): void
    {
        $module = new TestableModule();
        $this->assertStringEndsWith('/data/exelearning', $module->getDataPath());
    }

    // ------------------------------------------------------------- lifecycle

    public function testInstallWhitelistsExelearningTypesAndCreatesDataDirectory(): void
    {
        $settings = new Settings();
        $services = new TestServiceLocator(['Omeka\Settings' => $settings]);
        $module = new TestableModule($services);
        $module->setDataPathOverride($this->makeTmpDir() . '/data');

        $module->install($services);

        $mediaTypes = $settings->get('media_type_whitelist');
        $this->assertContains('application/zip', $mediaTypes);
        $this->assertContains('application/x-zip-compressed', $mediaTypes);
        $this->assertContains('application/octet-stream', $mediaTypes);

        $extensions = $settings->get('extension_whitelist');
        $this->assertContains('elpx', $extensions);
        $this->assertContains('zip', $extensions);

        $this->assertDirectoryExists($module->getDataPath());
        $this->assertNotEmpty(Messenger::SUCCESS);
    }

    public function testInstallPreservesExistingWhitelistEntriesWithoutDuplicating(): void
    {
        $settings = new Settings();
        $settings->set('media_type_whitelist', ['image/png', 'application/zip']);
        $settings->set('extension_whitelist', ['png', 'elpx']);
        $services = new TestServiceLocator(['Omeka\Settings' => $settings]);
        $module = new TestableModule($services);

        $module->callUpdateWhitelist($services);

        $mediaTypes = $settings->get('media_type_whitelist');
        $this->assertContains('image/png', $mediaTypes, 'pre-existing entry was dropped');
        $this->assertSame(
            1,
            count(array_keys($mediaTypes, 'application/zip', true)),
            'application/zip was added a second time'
        );
        // The list must stay a packed list; Omeka serialises it as a JSON array.
        $this->assertSame(range(0, count($mediaTypes) - 1), array_keys($mediaTypes));
        $this->assertSame(range(0, count($settings->get('extension_whitelist')) - 1), array_keys(
            $settings->get('extension_whitelist')
        ));
    }

    public function testCreateDataDirectoryIsIdempotent(): void
    {
        $module = new TestableModule();
        $dir = $this->makeTmpDir() . '/nested/data';
        $module->setDataPathOverride($dir);

        $module->callCreateDataDirectory();
        $this->assertDirectoryExists($dir);

        // Second call must not warn or fail on the existing directory.
        $module->callCreateDataDirectory();
        $this->assertDirectoryExists($dir);
    }

    public function testUninstallDropsLegacyEditorInstallerSettings(): void
    {
        $settings = new RecordingSettings();
        $services = new TestServiceLocator(['Omeka\Settings' => $settings]);
        $module = new TestableModule($services);

        $module->uninstall($services);

        $this->assertContains('exelearning_editor_installed_version', $settings->deleted);
        $this->assertContains('exelearning_editor_install_error', $settings->deleted);
        $this->assertCount(8, $settings->deleted);
    }

    public function testUpgradeDropsTheSameLegacySettings(): void
    {
        $settings = new RecordingSettings();
        $services = new TestServiceLocator(['Omeka\Settings' => $settings]);
        $module = new TestableModule($services);

        $module->upgrade('1.0.0', '1.1.0', $services);

        $this->assertCount(8, $settings->deleted);
    }

    // -------------------------------------------------------------- listeners

    public function testAttachListenersRegistersEveryOmekaHook(): void
    {
        $module = new TestableModule();
        $shared = new RecordingSharedEventManager();

        $module->attachListeners($shared);

        $registered = array_map(
            function (array $row) {
                return $row['identifier'] . '::' . $row['event'];
            },
            $shared->attached
        );

        $this->assertSame([
            'Omeka\Api\Adapter\MediaAdapter::api.hydrate.post',
            'Omeka\Api\Adapter\MediaAdapter::api.create.post',
            'Omeka\Api\Adapter\MediaAdapter::api.delete.pre',
            'Omeka\Controller\Admin\Media::view.show.after',
            '*::view.layout',
            'Omeka\Controller\Site\Item::view.show.after',
            'Omeka\Api\Representation\MediaRepresentation::rep.resource.json',
        ], $registered);

        foreach ($shared->attached as $row) {
            $this->assertIsCallable($row['listener']);
            $this->assertSame($module, $row['listener'][0]);
        }
    }

    // ------------------------------------------------------------- helpers

    /**
     * @dataProvider extensionProvider
     */
    public function testIsExeLearningFileRecognisesSupportedExtensions(
        string $filename,
        bool $expected
    ): void {
        $module = new TestableModule();
        $this->assertSame($expected, $module->callIsExeLearningFile($this->makeMedia($filename)));
    }

    /**
     * @return array<string, array{0: string, 1: bool}>
     */
    public function extensionProvider(): array
    {
        return [
            'elpx' => ['course.elpx', true],
            'zip' => ['course.zip', true],
            'uppercase elpx' => ['COURSE.ELPX', true],
            'mixed case zip' => ['Course.Zip', true],
            'pdf' => ['course.pdf', false],
            'no extension' => ['course', false],
            'empty filename' => ['', false],
            'elpx in the middle' => ['course.elpx.pdf', false],
        ];
    }

    /**
     * @dataProvider basePathProvider
     */
    public function testExtractBasePathStripsFromTheFirstOmekaRouteSegment(
        string $uriPath,
        string $expected
    ): void {
        $module = new TestableModule();
        $this->assertSame($expected, $module->callExtractBasePath($uriPath));
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public function basePathProvider(): array
    {
        return [
            'admin at root' => ['/admin/media/1', ''],
            'site at root' => ['/s/mysite/item/3', ''],
            'api at root' => ['/api/items', ''],
            'playground prefix' => ['/playground/abc-123/php83/admin/media/1', '/playground/abc-123/php83'],
            'subdirectory install' => ['/omeka/admin/item', '/omeka'],
            'no known marker' => ['/some/other/path', ''],
            'admin wins when first' => ['/base/admin/x/s/y', '/base'],
        ];
    }

    public function testBuildContentUrlOmitsDefaultPorts(): void
    {
        $module = new TestableModule($this->servicesWithRequest('https', 'example.org', 443, '/admin/media/1'));
        $this->assertSame(
            'https://example.org/exelearning/content/abc/index.html',
            $module->callBuildContentUrl('abc')
        );

        $module = new TestableModule($this->servicesWithRequest('http', 'example.org', 80, '/admin/media/1'));
        $this->assertSame(
            'http://example.org/exelearning/content/abc/index.html',
            $module->callBuildContentUrl('abc')
        );
    }

    public function testBuildContentUrlKeepsNonDefaultPortAndBasePath(): void
    {
        $module = new TestableModule(
            $this->servicesWithRequest('http', 'localhost', 8080, '/omeka/admin/media/1')
        );
        $this->assertSame(
            'http://localhost:8080/omeka/exelearning/content/deadbeef/index.html',
            $module->callBuildContentUrl('deadbeef')
        );
    }

    public function testBuildContentUrlHandlesAbsentPort(): void
    {
        $module = new TestableModule($this->servicesWithRequest('https', 'example.org', null, '/api/items'));
        $this->assertSame(
            'https://example.org/exelearning/content/h/index.html',
            $module->callBuildContentUrl('h')
        );
    }

    /**
     * @dataProvider teacherModeProvider
     * @param mixed $stored
     */
    public function testIsTeacherModeVisibleReadsThePerMediaFlag($stored, bool $expected): void
    {
        $module = new TestableModule();
        $data = $stored === null ? [] : ['exelearning_teacher_mode_visible' => $stored];
        $this->assertSame(
            $expected,
            $module->callIsTeacherModeVisible($this->makeMedia('a.elpx', 1, $data))
        );
    }

    /**
     * @return array<string, array{0: mixed, 1: bool}>
     */
    public function teacherModeProvider(): array
    {
        return [
            'unset' => [null, false],
            'string zero' => ['0', false],
            'literal false' => ['false', false],
            'literal no' => ['no', false],
            'string one' => ['1', true],
            'integer one' => [1, true],
            'literal yes' => ['yes', true],
        ];
    }

    public function testBuildContentPathAppendsTeacherParameterOnlyWhenEnabled(): void
    {
        $module = new TestableModule();

        $off = $this->makeMedia('a.elpx', 1, ['exelearning_teacher_mode_visible' => '0']);
        $this->assertSame('/exelearning/content/h1/index.html', $module->callBuildContentPath('h1', $off));

        $on = $this->makeMedia('a.elpx', 1, ['exelearning_teacher_mode_visible' => '1']);
        $this->assertSame(
            '/exelearning/content/h1/index.html?exe-teacher=1',
            $module->callBuildContentPath('h1', $on)
        );
    }

    public function testDeleteDirectoryRemovesNestedContent(): void
    {
        $root = $this->makeTmpDir();
        mkdir($root . '/a/b', 0777, true);
        file_put_contents($root . '/a/top.txt', 'x');
        file_put_contents($root . '/a/b/deep.txt', 'y');

        $module = new TestableModule();
        $module->callDeleteDirectory($root . '/a');

        $this->assertDirectoryDoesNotExist($root . '/a');
    }

    public function testDeleteDirectoryIgnoresMissingPath(): void
    {
        $module = new TestableModule();
        $module->callDeleteDirectory($this->makeTmpDir() . '/never-created');
        $this->addToAssertionCount(1);
    }

    public function testGetExeLearningItemIdsReturnsIntegers(): void
    {
        $connection = new class {
            /** @var string */
            public $sql = '';
            public function query(string $sql)
            {
                $this->sql = $sql;
                return new class {
                    public function fetchAll($mode = null): array
                    {
                        return ['3', '7', 11];
                    }
                };
            }
        };
        $module = new TestableModule(new TestServiceLocator([
            'Omeka\Connection' => $connection,
            'Omeka\Logger' => new Logger(),
        ]));

        $this->assertSame([3, 7, 11], $module->callGetExeLearningItemIds());
        $this->assertStringContainsString('.elpx', $connection->sql);
    }

    public function testGetExeLearningItemIdsLogsAndReturnsEmptyOnFailure(): void
    {
        $connection = new class {
            public function query(string $sql)
            {
                throw new \RuntimeException('database is gone');
            }
        };
        $logger = new Logger();
        $module = new TestableModule(new TestServiceLocator([
            'Omeka\Connection' => $connection,
            'Omeka\Logger' => $logger,
        ]));

        $this->assertSame([], $module->callGetExeLearningItemIds());
        $this->assertStringContainsString('database is gone', $this->lastMessage($logger, 'err'));
    }

    // -------------------------------------------------------- event handlers

    public function testHandleMediaJsonLdAddsScreenshotAndContentUrls(): void
    {
        $media = $this->makeMedia('course.elpx');
        $elp = new FakeElpFileService('hash123', true, true, true);
        $module = new TestableModule(new TestServiceLocator([ElpFileService::class => $elp]));

        $event = new Event('rep.resource.json', $media, ['jsonLd' => ['@id' => 'x']]);
        $module->handleMediaJsonLd($event);

        $jsonLd = $event->getParam('jsonLd');
        $this->assertSame('x', $jsonLd['@id'], 'existing keys must be preserved');
        $this->assertSame(
            '/exelearning/content/hash123/' . ElpFileService::SCREENSHOT_FILENAME,
            $jsonLd['o-module-exelearning:screenshot']
        );
        $this->assertSame(
            '/exelearning/content/hash123/index.html',
            $jsonLd['o-module-exelearning:content']
        );
    }

    public function testHandleMediaJsonLdOmitsKeysWhenAssetsAreAbsent(): void
    {
        $elp = new FakeElpFileService('hash123', false, false, true);
        $module = new TestableModule(new TestServiceLocator([ElpFileService::class => $elp]));

        $event = new Event('rep.resource.json', $this->makeMedia('course.elpx'), ['jsonLd' => []]);
        $module->handleMediaJsonLd($event);

        $this->assertSame([], $event->getParam('jsonLd'));
    }

    public function testHandleMediaJsonLdIgnoresNonExeLearningMedia(): void
    {
        // No ElpFileService registered: reaching for one would throw, which is
        // exactly the assertion -- the guard must return before that.
        $module = new TestableModule(new TestServiceLocator([]));

        $event = new Event('rep.resource.json', $this->makeMedia('photo.png'), ['jsonLd' => ['a' => 1]]);
        $module->handleMediaJsonLd($event);

        $this->assertSame(['a' => 1], $event->getParam('jsonLd'));
    }

    public function testHandleMediaJsonLdIgnoresMissingTargetAndHashlessMedia(): void
    {
        $module = new TestableModule(new TestServiceLocator([]));
        $event = new Event('rep.resource.json', null, ['jsonLd' => []]);
        $module->handleMediaJsonLd($event);
        $this->assertSame([], $event->getParam('jsonLd'));

        $elp = new FakeElpFileService(null, true, true, true);
        $module = new TestableModule(new TestServiceLocator([ElpFileService::class => $elp]));
        $event = new Event('rep.resource.json', $this->makeMedia('c.elpx'), ['jsonLd' => []]);
        $module->handleMediaJsonLd($event);
        $this->assertSame([], $event->getParam('jsonLd'));
    }

    public function testHandleAdminMediaShowRendersThePartialForProcessedMedia(): void
    {
        $media = $this->makeMedia('course.elpx', 5, ['exelearning_teacher_mode_visible' => '1']);
        $elp = new FakeElpFileService('abc', true, true, true);
        $view = new PhpRenderer();
        $view->resource = $media;

        $module = new TestableModule(new TestServiceLocator([
            'Omeka\Logger' => new Logger(),
            ElpFileService::class => $elp,
        ]));

        ob_start();
        $module->handleAdminMediaShow(new Event('view.show.after', $view));
        $output = (string) ob_get_clean();

        $this->assertStringContainsString('partial:exelearning/admin/media-show', $output);
        $this->assertSame('exelearning/admin/media-show', $view->partials[0]['name']);
        $this->assertSame(
            '/exelearning/content/abc/index.html?exe-teacher=1',
            $view->partials[0]['vars']['contentPath']
        );
    }

    public function testHandleAdminMediaShowAutoProcessesUnprocessedMedia(): void
    {
        $elp = new FakeElpFileService(null, false, false, false);
        $elp->processResult = ['hash' => 'fresh', 'hasPreview' => true];
        $view = new PhpRenderer();
        $view->resource = $this->makeMedia('course.elpx', 9);

        $module = new TestableModule(new TestServiceLocator([
            'Omeka\Logger' => new Logger(),
            ElpFileService::class => $elp,
        ]));

        ob_start();
        $module->handleAdminMediaShow(new Event('view.show.after', $view));
        ob_end_clean();

        $this->assertSame(1, $elp->processCalls);
        $this->assertSame(
            '/exelearning/content/fresh/index.html',
            $view->partials[0]['vars']['contentPath']
        );
    }

    public function testHandleAdminMediaShowSwallowsProcessingFailures(): void
    {
        $elp = new FakeElpFileService(null, false, false, false);
        $elp->processException = new \RuntimeException('corrupt archive');
        $logger = new Logger();
        $view = new PhpRenderer();
        $view->resource = $this->makeMedia('course.elpx', 9);

        $module = new TestableModule(new TestServiceLocator([
            'Omeka\Logger' => $logger,
            ElpFileService::class => $elp,
        ]));

        ob_start();
        $module->handleAdminMediaShow(new Event('view.show.after', $view));
        $output = (string) ob_get_clean();

        $this->assertSame('', $output);
        $this->assertSame([], $view->partials);
        $this->assertStringContainsString('corrupt archive', $this->lastMessage($logger, 'err'));
    }

    public function testHandleAdminMediaShowSkipsNonExeLearningAndPreviewlessMedia(): void
    {
        $view = new PhpRenderer();
        $view->resource = $this->makeMedia('photo.png');
        $module = new TestableModule(new TestServiceLocator([]));

        ob_start();
        $module->handleAdminMediaShow(new Event('view.show.after', $view));
        $this->assertSame('', (string) ob_get_clean());

        // Processed, but the package carries no index.html to show.
        $view = new PhpRenderer();
        $view->resource = $this->makeMedia('course.elpx');
        $module = new TestableModule(new TestServiceLocator([
            'Omeka\Logger' => new Logger(),
            ElpFileService::class => new FakeElpFileService('abc', false, false, true),
        ]));

        ob_start();
        $module->handleAdminMediaShow(new Event('view.show.after', $view));
        $this->assertSame('', (string) ob_get_clean());
    }

    public function testHandlePublicItemShowRendersEveryExeLearningMediaInTheItem(): void
    {
        $exe = $this->makeMedia('course.elpx', 1);
        $other = $this->makeMedia('photo.png', 2);
        $view = new PhpRenderer();
        $view->item = new FakeItem([$exe, $other]);

        $module = new TestableModule(new TestServiceLocator([
            'Omeka\Logger' => new Logger(),
            ElpFileService::class => new FakeElpFileService('h', true, true, true),
        ]));

        ob_start();
        $module->handlePublicItemShow(new Event('view.show.after', $view));
        ob_end_clean();

        $this->assertCount(1, $view->partials, 'only the eXeLearning media may render');
        $this->assertSame('exelearning/public/item-show', $view->partials[0]['name']);
    }

    public function testHandlePublicItemShowContinuesAfterAProcessingFailure(): void
    {
        $view = new PhpRenderer();
        $view->item = new FakeItem([$this->makeMedia('a.elpx', 1), $this->makeMedia('b.elpx', 2)]);

        $elp = new FakeElpFileService(null, false, false, false);
        $elp->processException = new \RuntimeException('boom');
        $logger = new Logger();

        $module = new TestableModule(new TestServiceLocator([
            'Omeka\Logger' => $logger,
            ElpFileService::class => $elp,
        ]));

        ob_start();
        $module->handlePublicItemShow(new Event('view.show.after', $view));
        ob_end_clean();

        $this->assertSame([], $view->partials);
        // Both media were attempted: the failure of the first must not abort.
        $this->assertSame(2, $elp->processCalls);
    }

    public function testHandlePublicItemShowAutoProcessesAndUsesTheFreshResult(): void
    {
        $view = new PhpRenderer();
        $view->item = new FakeItem([$this->makeMedia('course.elpx', 1)]);

        $elp = new FakeElpFileService('stale', false, false, false);
        $elp->processResult = ['hash' => 'fresh', 'hasPreview' => true];

        $module = new TestableModule(new TestServiceLocator([
            'Omeka\Logger' => new Logger(),
            ElpFileService::class => $elp,
        ]));

        ob_start();
        $module->handlePublicItemShow(new Event('view.show.after', $view));
        ob_end_clean();

        $this->assertSame(1, $elp->processCalls);
        $this->assertSame(
            '/exelearning/content/fresh/index.html',
            $view->partials[0]['vars']['contentPath'],
            'the hash returned by processing must win over the stale one'
        );
    }

    public function testHandlePublicItemShowSkipsAlreadyProcessedMediaWithoutAPreview(): void
    {
        $view = new PhpRenderer();
        $view->item = new FakeItem([$this->makeMedia('course.elpx', 1)]);

        // Processed, so no re-extraction, but the package has no index.html.
        $elp = new FakeElpFileService('abc', false, false, true);

        $module = new TestableModule(new TestServiceLocator([
            'Omeka\Logger' => new Logger(),
            ElpFileService::class => $elp,
        ]));

        ob_start();
        $module->handlePublicItemShow(new Event('view.show.after', $view));
        $output = (string) ob_get_clean();

        $this->assertSame('', $output);
        $this->assertSame([], $view->partials);
        $this->assertSame(0, $elp->processCalls, 'a processed package must not be re-extracted');
    }

    public function testHandlePublicItemShowIgnoresAnItemlessView(): void
    {
        $view = new PhpRenderer();
        $module = new TestableModule(new TestServiceLocator([]));

        ob_start();
        $module->handlePublicItemShow(new Event('view.show.after', $view));
        $this->assertSame('', (string) ob_get_clean());
    }

    public function testHandleViewLayoutInjectsScriptsOnAdminRoutes(): void
    {
        $view = new PhpRenderer();
        $view->basePath = '/omeka';
        $connection = new class {
            public function query(string $sql)
            {
                return new class {
                    public function fetchAll($mode = null): array
                    {
                        return ['4'];
                    }
                };
            }
        };

        $module = new TestableModule(new TestServiceLocator([
            'Application' => new FakeApplication('admin/default'),
            'Omeka\Connection' => $connection,
            'Omeka\Logger' => new Logger(),
        ]));

        $module->handleViewLayout(new Event('view.layout', $view));

        $this->assertSame(
            ['/omeka/modules/ExeLearning/asset/js/exelearning-thumbnail.js'],
            $view->headScript()->files
        );
        $scripts = $view->headScript()->scripts;
        $this->assertStringContainsString('data-exelearning-thumbnail', $scripts[0]);
        $this->assertStringContainsString('/omeka/modules/ExeLearning/asset/thumbnails/elpx.png', $scripts[0]);
        $this->assertStringContainsString('window.exelearningItemIds = [4]', $scripts[0]);
        $this->assertStringContainsString('/omeka/api/exelearning/elp-data/', $scripts[1]);
        $this->assertStringContainsString('exelearning_teacher_mode_visible', $scripts[1]);
    }

    public function testHandleViewLayoutSkipsPublicRoutesAndUnroutedRequests(): void
    {
        $view = new PhpRenderer();
        $module = new TestableModule(new TestServiceLocator([
            'Application' => new FakeApplication('site/resource-id'),
        ]));
        $module->handleViewLayout(new Event('view.layout', $view));
        $this->assertSame([], $view->headScript()->files);

        $view = new PhpRenderer();
        $module = new TestableModule(new TestServiceLocator([
            'Application' => new FakeApplication(null),
        ]));
        $module->handleViewLayout(new Event('view.layout', $view));
        $this->assertSame([], $view->headScript()->files);
    }

    public function testHandleMediaHydrateSetsRendererAndPersistsTeacherMode(): void
    {
        $entity = new FakeMediaEntity('course.elpx');
        $request = new FakeApiRequest(['exelearning_teacher_mode_visible' => '1']);
        $module = new TestableModule();

        $module->handleMediaHydrate(new Event('api.hydrate.post', null, [
            'entity' => $entity,
            'request' => $request,
        ]));

        $this->assertSame('exelearning_renderer', $entity->renderer);
        $this->assertSame('1', $entity->data['exelearning_teacher_mode_visible']);
    }

    public function testHandleMediaHydrateUsesTheLastValueOfACheckboxPair(): void
    {
        // Omeka posts a hidden 0 followed by the checkbox's 1; the last wins.
        $entity = new FakeMediaEntity('course.elpx');
        $module = new TestableModule();

        $module->handleMediaHydrate(new Event('api.hydrate.post', null, [
            'entity' => $entity,
            'request' => new FakeApiRequest(['exelearning_teacher_mode_visible' => ['0', '1']]),
        ]));

        $this->assertSame('1', $entity->data['exelearning_teacher_mode_visible']);
    }

    /**
     * @dataProvider falsyTeacherModeProvider
     * @param mixed $posted
     */
    public function testHandleMediaHydrateStoresZeroForFalsyValues($posted): void
    {
        $entity = new FakeMediaEntity('course.elpx');
        $module = new TestableModule();

        $module->handleMediaHydrate(new Event('api.hydrate.post', null, [
            'entity' => $entity,
            'request' => new FakeApiRequest(['exelearning_teacher_mode_visible' => $posted]),
        ]));

        $this->assertSame('0', $entity->data['exelearning_teacher_mode_visible']);
    }

    /**
     * @return array<string, array{0: mixed}>
     */
    public function falsyTeacherModeProvider(): array
    {
        return [
            'zero' => ['0'],
            'false' => ['false'],
            'no' => ['no'],
            'off' => ['off'],
            'empty' => [''],
        ];
    }

    public function testHandleMediaHydrateFallsBackToSourceWhenFilenameIsAbsent(): void
    {
        $entity = new FakeSourceOnlyEntity('course.zip');
        $module = new TestableModule();

        $module->handleMediaHydrate(new Event('api.hydrate.post', null, ['entity' => $entity]));

        $this->assertSame('exelearning_renderer', $entity->renderer);
    }

    public function testHandleMediaHydrateLeavesOtherFileTypesAlone(): void
    {
        $entity = new FakeMediaEntity('photo.png');
        $module = new TestableModule();

        $module->handleMediaHydrate(new Event('api.hydrate.post', null, [
            'entity' => $entity,
            'request' => new FakeApiRequest(['exelearning_teacher_mode_visible' => '1']),
        ]));

        $this->assertNull($entity->renderer);
        $this->assertSame([], $entity->data);
    }

    public function testHandleMediaHydrateIgnoresAnEntityWithoutAFilename(): void
    {
        $entity = new FakeMediaEntity('');
        $module = new TestableModule();

        $module->handleMediaHydrate(new Event('api.hydrate.post', null, ['entity' => $entity]));

        $this->assertNull($entity->renderer);
    }

    public function testHandleMediaCreateProcessesTheUploadedFile(): void
    {
        $media = $this->makeMedia('course.elpx', 12);
        $elp = new FakeElpFileService(null, false, false, false);
        $elp->processResult = ['hash' => 'abc', 'hasPreview' => true];

        $module = new TestableModule(new TestServiceLocator([
            'Omeka\Logger' => new Logger(),
            'Omeka\ApiManager' => new FakeApiManager($media),
            ElpFileService::class => $elp,
        ]));

        $module->handleMediaCreate($this->createEvent($media, 12));

        $this->assertSame(1, $elp->processCalls);
    }

    public function testHandleMediaCreateSkipsNonExeLearningMedia(): void
    {
        $media = $this->makeMedia('photo.png', 13);
        $elp = new FakeElpFileService(null, false, false, false);

        $module = new TestableModule(new TestServiceLocator([
            'Omeka\Logger' => new Logger(),
            'Omeka\ApiManager' => new FakeApiManager($media),
            ElpFileService::class => $elp,
        ]));

        $module->handleMediaCreate($this->createEvent($media, 13));

        $this->assertSame(0, $elp->processCalls);
    }

    public function testHandleMediaCreateLogsWhenTheRepresentationCannotBeRead(): void
    {
        $logger = new Logger();
        $api = new FakeApiManager(null);
        $api->exception = new \RuntimeException('no such media');

        $module = new TestableModule(new TestServiceLocator([
            'Omeka\Logger' => $logger,
            'Omeka\ApiManager' => $api,
        ]));

        $module->handleMediaCreate($this->createEvent(null, 99));

        $this->assertStringContainsString('no such media', $this->lastMessage($logger, 'err'));
    }

    public function testHandleMediaCreateLogsProcessingFailures(): void
    {
        $media = $this->makeMedia('course.elpx', 14);
        $elp = new FakeElpFileService(null, false, false, false);
        $elp->processException = new \RuntimeException('extraction failed');
        $logger = new Logger();

        $module = new TestableModule(new TestServiceLocator([
            'Omeka\Logger' => $logger,
            'Omeka\ApiManager' => new FakeApiManager($media),
            ElpFileService::class => $elp,
        ]));

        $module->handleMediaCreate($this->createEvent($media, 14));

        $errors = array_column(array_filter($logger->getMessages(), function (array $m) {
            return $m['level'] === 'err';
        }), 'message');
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('extraction failed', implode("\n", $errors));
    }

    public function testHandleMediaDeleteRemovesTheExtractionDirectory(): void
    {
        $root = $this->makeTmpDir();
        mkdir($root . '/hash-1/sub', 0777, true);
        file_put_contents($root . '/hash-1/sub/f.txt', 'x');

        $module = new TestableModule(new TestServiceLocator(['Omeka\Logger' => new Logger()]));
        $module->setDataPathOverride($root);

        $entity = new FakeMediaEntity('course.elpx', 21, ['exelearning_extracted_hash' => 'hash-1']);
        $module->handleMediaDelete(new Event('api.delete.pre', null, ['entity' => $entity]));

        $this->assertDirectoryDoesNotExist($root . '/hash-1');
    }

    public function testHandleMediaDeleteIgnoresIrrelevantEntities(): void
    {
        $logger = new Logger();
        $module = new TestableModule(new TestServiceLocator(['Omeka\Logger' => $logger]));
        $module->setDataPathOverride($this->makeTmpDir());

        // No entity at all.
        $module->handleMediaDelete(new Event('api.delete.pre', null, ['entity' => null]));
        // A media that is not an eXeLearning package.
        $module->handleMediaDelete(new Event('api.delete.pre', null, [
            'entity' => new FakeMediaEntity('photo.png', 22, ['exelearning_extracted_hash' => 'nope']),
        ]));
        // An eXeLearning media that was never extracted.
        $module->handleMediaDelete(new Event('api.delete.pre', null, [
            'entity' => new FakeMediaEntity('course.elpx', 23, []),
        ]));
        // A filename-less entity.
        $module->handleMediaDelete(new Event('api.delete.pre', null, [
            'entity' => new FakeMediaEntity('', 24, []),
        ]));

        $this->assertSame([], array_filter($logger->getMessages(), function (array $m) {
            return $m['level'] === 'err';
        }));
    }

    public function testHandleMediaDeleteLogsUnexpectedFailures(): void
    {
        $logger = new Logger();
        $module = new TestableModule(new TestServiceLocator(['Omeka\Logger' => $logger]));

        $entity = new class {
            public function getId(): int
            {
                throw new \RuntimeException('entity detached');
            }
        };
        $module->handleMediaDelete(new Event('api.delete.pre', null, ['entity' => $entity]));

        $this->assertStringContainsString('entity detached', $this->lastMessage($logger, 'err'));
    }

    // ------------------------------------------------------------ config form

    public function testGetConfigFormRendersSettingsAndStylesSection(): void
    {
        $settings = new Settings();
        $settings->set('exelearning_viewer_height', 800);
        $settings->set('exelearning_download_formats', json_encode(['elpx', 'html5']));

        $module = new TestableModule(new TestServiceLocator(['Omeka\Settings' => $settings]));
        $html = $module->getConfigForm(new PhpRenderer());

        $this->assertStringContainsString('exelearning-styles-link', $html);
        $this->assertStringContainsString('<!--formCollection-->', $html);
    }

    public function testGetConfigFormToleratesAnUnparseableStoredFormatList(): void
    {
        $settings = new Settings();
        $settings->set('exelearning_download_formats', 'not json at all');

        $module = new TestableModule(new TestServiceLocator(['Omeka\Settings' => $settings]));
        $html = $module->getConfigForm(new PhpRenderer());

        $this->assertStringContainsString('exelearning-styles-link', $html);
    }

    public function testRenderStylesSectionLinksToTheStylesPage(): void
    {
        $module = new TestableModule();
        $html = $module->callRenderStylesSection(new PhpRenderer());

        $this->assertStringContainsString('admin/exelearning-styles', $html);
        $this->assertStringContainsString('Open styles page', $html);
    }

    /**
     * The complementary branch (bundle present -> empty string) would need a
     * real dist/static/ in the checkout. Fabricating one risks deleting a
     * developer's actual build in tearDown, so it is left to the packaging
     * checks in `make package` instead. See ADR-0001 and ADR-0002.
     */
    public function testRenderEditorStatusSectionWarnsWhenTheBundleIsMissing(): void
    {
        if (\ExeLearning\Service\EditorBundle::isAvailable()) {
            $this->markTestSkipped('This checkout has a built editor bundle; nothing to warn about.');
        }

        $module = new TestableModule();
        $html = $module->callRenderEditorStatusSection(new PhpRenderer());

        $this->assertStringContainsString('exelearning-editor-status', $html);
        $this->assertStringContainsString('does not include the embedded editor', $html);
    }

    public function testHandleConfigFormPersistsSanitizedValues(): void
    {
        $settings = new Settings();
        $module = new TestableModule(new TestServiceLocator(['Omeka\Settings' => $settings]));

        $module->handleConfigForm(new FakeConfigController([
            'exelearning_viewer_height' => '750',
            'exelearning_download_formats' => ['elpx', 'bogus-format', 'epub3'],
        ]));

        $this->assertSame(750, $settings->get('exelearning_viewer_height'));
        $stored = $settings->get('exelearning_download_formats');
        $this->assertContains('elpx', $stored);
        $this->assertNotContains('bogus-format', $stored, 'unknown format ids must be dropped');
    }

    public function testHandleConfigFormFallsBackToDefaultHeight(): void
    {
        $settings = new Settings();
        $module = new TestableModule(new TestServiceLocator(['Omeka\Settings' => $settings]));

        $module->handleConfigForm(new FakeConfigController([]));

        $this->assertSame(600, $settings->get('exelearning_viewer_height'));
    }

    // ----------------------------------------------------------------- helpers

    /**
     * @param array<string, mixed> $mediaData
     */
    private function makeMedia(string $filename, int $id = 1, array $mediaData = []): object
    {
        return new \Omeka\Api\Representation\MediaRepresentation(
            'http://example.test/files/' . $filename,
            $filename,
            $filename,
            $id,
            $mediaData
        );
    }

    /**
     * @param object|null $media
     */
    private function createEvent($media, int $mediaId): Event
    {
        return new Event('api.create.post', null, [
            'response' => new FakeApiResponse(new FakeMediaEntity('x', $mediaId)),
        ]);
    }

    private function servicesWithRequest(
        string $scheme,
        string $host,
        ?int $port,
        string $path
    ): TestServiceLocator {
        return new TestServiceLocator(['Request' => new FakeHttpRequest($scheme, $host, $port, $path)]);
    }

    private function lastMessage(Logger $logger, string $level): string
    {
        $matching = array_values(array_filter($logger->getMessages(), function (array $m) use ($level) {
            return $m['level'] === $level;
        }));
        $this->assertNotEmpty($matching, 'no ' . $level . ' message was logged');
        return (string) end($matching)['message'];
    }

    private function makeTmpDir(): string
    {
        if ($this->tmpDir === null) {
            $this->tmpDir = sys_get_temp_dir() . '/exe-module-test-' . uniqid('', true);
            mkdir($this->tmpDir, 0777, true);
        }
        return $this->tmpDir;
    }

    private function removeTree(string $dir): void
    {
        foreach (array_diff(scandir($dir) ?: [], ['.', '..']) as $entry) {
            $path = $dir . '/' . $entry;
            is_dir($path) ? $this->removeTree($path) : @unlink($path);
        }
        @rmdir($dir);
    }
}
