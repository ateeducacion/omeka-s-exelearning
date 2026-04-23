<?php
declare(strict_types=1);

namespace ExeLearningTest\Service;

use ExeLearning\Service\StylesService;
use Omeka\Settings\Settings;
use PHPUnit\Framework\TestCase;
use ZipArchive;

/**
 * Unit tests for StylesService.
 *
 * @covers \ExeLearning\Service\StylesService
 */
class StylesServiceTest extends TestCase
{
    private string $tmpRoot;
    private StylesService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpRoot = sys_get_temp_dir() . '/exelearning-styles-' . uniqid();
        mkdir($this->tmpRoot . '/files', 0755, true);
        mkdir($this->tmpRoot . '/module', 0755, true);
        $settings = new Settings();
        $this->svc = new StylesService(
            $settings,
            $this->tmpRoot . '/files',
            $this->tmpRoot . '/module'
        );
    }

    protected function tearDown(): void
    {
        StylesService::recursiveDelete($this->tmpRoot);
        parent::tearDown();
    }

    public function testRegistryDefaultsToEmptyShape(): void
    {
        $registry = $this->svc->getRegistry();
        $this->assertSame([], $registry['uploaded']);
        $this->assertSame([], $registry['disabled_builtins']);
    }

    public function testImportIsAllowedByDefault(): void
    {
        $this->assertFalse($this->svc->isImportBlocked());
        $this->svc->setImportBlocked(true);
        $this->assertTrue($this->svc->isImportBlocked());
    }

    public function testSetBuiltinEnabledTogglesDisabledList(): void
    {
        $this->svc->setBuiltinEnabled('zen', false);
        $this->assertSame(['zen'], $this->svc->getRegistry()['disabled_builtins']);
        $this->svc->setBuiltinEnabled('zen', false);
        $this->assertSame(['zen'], $this->svc->getRegistry()['disabled_builtins']);
        $this->svc->setBuiltinEnabled('zen', true);
        $this->assertSame([], $this->svc->getRegistry()['disabled_builtins']);
    }

    public function testValidateZipRejectsMissingConfig(): void
    {
        $zip = $this->makeZip(['style.css' => '.x{}']);
        $this->expectException(\RuntimeException::class);
        try {
            $this->svc->validateZip($zip);
        } finally {
            @unlink($zip);
        }
    }

    public function testValidateZipRejectsTraversalEntry(): void
    {
        $zip = $this->makeZip([
            'config.xml' => $this->configXml('acme'),
            '../evil.css' => 'pwn',
        ]);
        $this->expectException(\RuntimeException::class);
        try {
            $this->svc->validateZip($zip);
        } finally {
            @unlink($zip);
        }
    }

    public function testValidateZipRejectsDisallowedExtension(): void
    {
        $zip = $this->makeZip([
            'config.xml' => $this->configXml('acme'),
            'evil.php'   => '<?php',
        ]);
        $this->expectException(\RuntimeException::class);
        try {
            $this->svc->validateZip($zip);
        } finally {
            @unlink($zip);
        }
    }

    public function testValidateAcceptsValidRootPackage(): void
    {
        $zip = $this->makeZip([
            'config.xml' => $this->configXml('acme-2026', 'Acme 2026', '1.0.0'),
            'style.css'  => 'body{}',
        ]);
        $result = $this->svc->validateZip($zip);
        $this->assertSame('acme-2026', $result['config']['name']);
        $this->assertSame('', $result['prefix']);
        @unlink($zip);
    }

    public function testInstallExtractsAndRegisters(): void
    {
        $zip = $this->makeZip([
            'config.xml' => $this->configXml('acme', 'Acme', '1.0.0'),
            'style.css'  => 'body{color:red}',
        ]);
        $entry = $this->svc->installFromZip($zip, 'acme.zip');
        $this->assertSame('acme', $entry['name']);
        $this->assertTrue($entry['enabled']);
        $this->assertContains('style.css', $entry['css_files']);

        $this->assertFileExists($this->svc->getStorageDir() . '/acme/style.css');
        $this->assertArrayHasKey('acme', $this->svc->getRegistry()['uploaded']);
        @unlink($zip);
    }

    public function testInstallAllocatesUniqueSlugOnCollision(): void
    {
        $z1 = $this->makeZip([
            'config.xml' => $this->configXml('duo'),
            'style.css'  => 'a{}',
        ]);
        $z2 = $this->makeZip([
            'config.xml' => $this->configXml('duo'),
            'style.css'  => 'b{}',
        ]);
        $a = $this->svc->installFromZip($z1);
        $b = $this->svc->installFromZip($z2);
        $this->assertSame('duo', $a['name']);
        $this->assertSame('duo-2', $b['name']);
        @unlink($z1);
        @unlink($z2);
    }

    public function testDeleteClearsFilesAndRegistry(): void
    {
        $zip = $this->makeZip([
            'config.xml' => $this->configXml('bye'),
            'style.css'  => 'x{}',
        ]);
        $this->svc->installFromZip($zip);
        $dir = $this->svc->getStorageDir() . '/bye';
        $this->assertDirectoryExists($dir);

        $this->assertTrue($this->svc->deleteUploaded('bye'));
        $this->assertDirectoryDoesNotExist($dir);
        $this->assertArrayNotHasKey('bye', $this->svc->getRegistry()['uploaded']);
        @unlink($zip);
    }

    public function testBuildOverrideRespectsEnabledFlagAndImportPolicy(): void
    {
        $zip = $this->makeZip([
            'config.xml' => $this->configXml('seen'),
            'style.css'  => 'a{}',
        ]);
        $this->svc->installFromZip($zip);
        $this->svc->setBuiltinEnabled('zen', false);
        $this->svc->setImportBlocked(true);

        $override = $this->svc->buildThemeRegistryOverride('https://example.test');
        $this->assertSame(['zen'], $override['disabledBuiltins']);
        $this->assertTrue($override['blockImportInstall']);
        $this->assertSame('base', $override['fallbackTheme']);
        $this->assertCount(1, $override['uploaded']);
        $this->assertSame('seen', $override['uploaded'][0]['id']);
        $this->assertSame(
            'https://example.test/exelearning/styles/seen',
            $override['uploaded'][0]['url']
        );

        $this->svc->setUploadedEnabled('seen', false);
        $override = $this->svc->buildThemeRegistryOverride();
        $this->assertCount(0, $override['uploaded']);
        @unlink($zip);
    }

    public function testExtractThemesFromBundleAcceptsBothShapes(): void
    {
        $flat = ['themes' => [['name' => 'a']]];
        $nested = ['themes' => ['themes' => [['name' => 'b']]]];
        $this->assertCount(1, $this->svc->extractThemesFromBundle($flat));
        $this->assertCount(1, $this->svc->extractThemesFromBundle($nested));
        $this->assertSame([], $this->svc->extractThemesFromBundle([]));
        $this->assertSame([], $this->svc->extractThemesFromBundle(['themes' => 'nope']));
        // Rows without a name are skipped.
        $this->assertSame([], $this->svc->extractThemesFromBundle(['themes' => [[], ['title' => 'x']]]));
    }

    public function testListBuiltinThemesReadsBundleWhenPresent(): void
    {
        $dataDir = $this->tmpRoot . '/module/dist/static/data';
        mkdir($dataDir, 0755, true);
        file_put_contents($dataDir . '/bundle.json', json_encode([
            'themes' => ['themes' => [
                ['name' => 'base', 'title' => 'Default', 'version' => '2025'],
                ['name' => 'neo', 'title' => 'Neo'],
            ]],
        ]));
        $themes = $this->svc->listBuiltinThemes();
        $this->assertCount(2, $themes);
        $this->assertSame('base', $themes[0]['id']);
        $this->assertSame('Default', $themes[0]['title']);
    }

    public function testListBuiltinThemesReturnsEmptyWhenBundleMissing(): void
    {
        $this->assertSame([], $this->svc->listBuiltinThemes());
    }

    public function testListBuiltinThemesReturnsEmptyOnMalformedJson(): void
    {
        $dataDir = $this->tmpRoot . '/module/dist/static/data';
        mkdir($dataDir, 0755, true);
        file_put_contents($dataDir . '/bundle.json', '{not json');
        $this->assertSame([], $this->svc->listBuiltinThemes());
    }

    public function testAllocateUniqueSlugSuffixesAroundBuiltinsAndUploads(): void
    {
        $dataDir = $this->tmpRoot . '/module/dist/static/data';
        mkdir($dataDir, 0755, true);
        file_put_contents($dataDir . '/bundle.json', json_encode([
            'themes' => ['themes' => [['name' => 'acme']]],
        ]));
        // Bound against a built-in name -> suffix -2.
        $slug = $this->svc->allocateUniqueSlug('acme');
        $this->assertSame('acme-2', $slug);

        // After installing 'acme-2', the next collision goes to -3.
        $zip = $this->makeZip(['config.xml' => $this->configXml('acme-2'), 'style.css' => 'x{}']);
        $this->svc->installFromZip($zip);
        $this->assertSame('acme-3', $this->svc->allocateUniqueSlug('acme'));
        @unlink($zip);
    }

    public function testNormalizeSlugSanitizesInput(): void
    {
        $this->assertSame('a-b-c', StylesService::normalizeSlug('A B C'));
        $this->assertSame('style', StylesService::normalizeSlug('  '));
        $this->assertSame('a', StylesService::normalizeSlug('---a---'));
    }

    public function testIsUnsafeZipEntryDetectsEveryBadShape(): void
    {
        foreach (['', '\\a', '/absolute', 'http://x', '../x', 'a/../b'] as $bad) {
            $this->assertTrue(StylesService::isUnsafeZipEntry($bad), "should reject: $bad");
        }
        foreach (['style.css', 'icons/a.png', 'sub/dir/file.css'] as $ok) {
            $this->assertFalse(StylesService::isUnsafeZipEntry($ok), "should accept: $ok");
        }
    }

    public function testIsAllowedFilenameRejectsExtensionlessAndPhp(): void
    {
        $this->assertFalse(StylesService::isAllowedFilename('README'));
        $this->assertFalse(StylesService::isAllowedFilename('evil.php'));
        $this->assertTrue(StylesService::isAllowedFilename('dir/'));
        $this->assertTrue(StylesService::isAllowedFilename('style.css'));
        $this->assertTrue(StylesService::isAllowedFilename('icons/a.svg'));
    }

    public function testParseConfigXmlRejectsInvalidXml(): void
    {
        $this->expectException(\RuntimeException::class);
        StylesService::parseConfigXml('<<bad xml');
    }

    public function testParseConfigXmlRequiresName(): void
    {
        $this->expectException(\RuntimeException::class);
        StylesService::parseConfigXml('<?xml version="1.0"?><theme></theme>');
    }

    public function testValidateZipRejectsOversize(): void
    {
        // Make a zip with large-content to exceed the cap via a reflective
        // tweak of the service's default max.
        $zip = $this->makeZip([
            'config.xml' => $this->configXml('big'),
            'style.css'  => str_repeat('x', 100),
        ]);
        // Force max size to 50 bytes via an anonymous subclass.
        $svc = new class(new Settings(), $this->tmpRoot . '/files', $this->tmpRoot . '/module') extends StylesService {
            public function getMaxZipSize(): int
            {
                return 50;
            }
        };
        $this->expectException(\RuntimeException::class);
        try {
            $svc->validateZip($zip);
        } finally {
            @unlink($zip);
        }
    }

    public function testValidateZipAcceptsSingleRootFolder(): void
    {
        $zip = $this->makeZip([
            'acme/config.xml' => $this->configXml('acme'),
            'acme/style.css'  => 'body{}',
        ]);
        $result = $this->svc->validateZip($zip);
        $this->assertSame('acme/', $result['prefix']);
        @unlink($zip);
    }

    public function testValidateZipRejectsMultipleConfigs(): void
    {
        $zip = $this->makeZip([
            'a/config.xml' => $this->configXml('a'),
            'b/config.xml' => $this->configXml('b'),
            'a/style.css'  => 'x{}',
            'b/style.css'  => 'y{}',
        ]);
        $this->expectException(\RuntimeException::class);
        try {
            $this->svc->validateZip($zip);
        } finally {
            @unlink($zip);
        }
    }

    public function testInstallFromFolderedZipExtractsOnlyFiles(): void
    {
        $zip = $this->makeZip([
            'acme/config.xml' => $this->configXml('acme'),
            'acme/style.css'  => 'body{}',
            'acme/img/bg.png' => 'fake-png',
        ]);
        $entry = $this->svc->installFromZip($zip);
        $this->assertFileExists($this->svc->getStorageDir() . '/acme/style.css');
        $this->assertFileExists($this->svc->getStorageDir() . '/acme/img/bg.png');
        $this->assertFileDoesNotExist($this->svc->getStorageDir() . '/acme/acme/style.css');
        @unlink($zip);
    }

    public function testInstallRejectsWhenNoCssIsPresent(): void
    {
        $zip = $this->makeZip([
            'config.xml' => $this->configXml('nocss'),
            'info.md'    => 'no css here',
        ]);
        $this->expectException(\RuntimeException::class);
        try {
            $this->svc->installFromZip($zip);
        } finally {
            @unlink($zip);
        }
    }

    public function testBuildOverrideHonorsBaseUrl(): void
    {
        $zip = $this->makeZip([
            'config.xml' => $this->configXml('acme'),
            'style.css'  => 'x{}',
        ]);
        $this->svc->installFromZip($zip);
        $override = $this->svc->buildThemeRegistryOverride('https://host');
        $this->assertSame('https://host/exelearning/styles/acme', $override['uploaded'][0]['url']);

        $override2 = $this->svc->buildThemeRegistryOverride('');
        $this->assertSame('/exelearning/styles/acme', $override2['uploaded'][0]['url']);
        @unlink($zip);
    }

    public function testGetStorageAndStyleDirResolveCorrectly(): void
    {
        $this->assertStringEndsWith('/exelearning-styles', $this->svc->getStorageDir());
        $this->assertStringEndsWith('/exelearning-styles/acme', $this->svc->getStyleDir('acme'));
        $this->assertStringEndsWith('/exelearning-styles/style', $this->svc->getStyleDir(''));
    }

    public function testRecursiveDeleteHandlesMissingPath(): void
    {
        // Should not throw.
        StylesService::recursiveDelete($this->tmpRoot . '/does-not-exist');
        $this->assertTrue(true);
    }

    public function testSetUploadedEnabledReturnsFalseForUnknownSlug(): void
    {
        $this->assertFalse($this->svc->setUploadedEnabled('missing', true));
    }

    public function testDeleteUploadedReturnsFalseForUnknownSlug(): void
    {
        $this->assertFalse($this->svc->deleteUploaded('missing'));
    }

    public function testGetStyleUrlPathIsSlugScopedAndEncoded(): void
    {
        $this->assertSame('/exelearning/styles/weird-name', $this->svc->getStyleUrlPath('weird name'));
        $this->assertSame('/exelearning/styles/acme', $this->svc->getStyleUrlPath('ACME'));
    }

    public function testListUploadedStylesSkipsNonArrayEntries(): void
    {
        // Inject a malformed entry and confirm the listing filters it out.
        $settings = new Settings();
        $svc = new StylesService($settings, $this->tmpRoot . '/files', $this->tmpRoot . '/module');
        $settings->set(StylesService::SETTING_REGISTRY, json_encode([
            'uploaded' => [
                'good' => ['title' => 'Good', 'enabled' => true, 'css_files' => ['style.css']],
                'bad'  => 'this is not an array',
            ],
        ]));
        $list = $svc->listUploadedStyles();
        $this->assertCount(1, $list);
        $this->assertSame('good', $list[0]['id']);
    }

    public function testBuildOverrideSkipsNonArrayOrDisabledEntries(): void
    {
        $settings = new Settings();
        $svc = new StylesService($settings, $this->tmpRoot . '/files', $this->tmpRoot . '/module');
        $settings->set(StylesService::SETTING_REGISTRY, json_encode([
            'uploaded' => [
                'off' => ['title' => 'Off', 'enabled' => false],
                'bad' => 'scalar',
                'on'  => ['title' => 'On', 'enabled' => true, 'css_files' => ['style.css']],
            ],
        ]));
        $override = $svc->buildThemeRegistryOverride();
        $this->assertCount(1, $override['uploaded']);
        $this->assertSame('on', $override['uploaded'][0]['id']);
    }

    public function testGetRegistryGracefullyHandlesGarbageSettingValue(): void
    {
        $settings = new Settings();
        $svc = new StylesService($settings, $this->tmpRoot . '/files', $this->tmpRoot . '/module');
        // Not JSON at all.
        $settings->set(StylesService::SETTING_REGISTRY, 'not json');
        $r = $svc->getRegistry();
        $this->assertSame([], $r['uploaded']);
        $this->assertSame([], $r['disabled_builtins']);

        // JSON but not an object.
        $settings->set(StylesService::SETTING_REGISTRY, '[1,2,3]');
        $r = $svc->getRegistry();
        $this->assertSame([], $r['uploaded']);
        $this->assertSame([], $r['disabled_builtins']);

        // JSON object with wrong-typed fields.
        $settings->set(StylesService::SETTING_REGISTRY, '{"uploaded":"nope","disabled_builtins":"nope"}');
        $r = $svc->getRegistry();
        $this->assertSame([], $r['uploaded']);
        $this->assertSame([], $r['disabled_builtins']);
    }

    public function testValidateZipRejectsEmptyFile(): void
    {
        $empty = tempnam(sys_get_temp_dir(), 'empty') . '.zip';
        file_put_contents($empty, '');
        $this->expectException(\RuntimeException::class);
        try {
            $this->svc->validateZip($empty);
        } finally {
            @unlink($empty);
        }
    }

    public function testValidateZipRejectsMissingFile(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->svc->validateZip($this->tmpRoot . '/does-not-exist.zip');
    }

    public function testValidateZipRejectsNonZipPayload(): void
    {
        $notzip = tempnam(sys_get_temp_dir(), 'notzip') . '.zip';
        file_put_contents($notzip, 'this is not a zip archive');
        $this->expectException(\RuntimeException::class);
        try {
            $this->svc->validateZip($notzip);
        } finally {
            @unlink($notzip);
        }
    }

    public function testInstallSurvivesConfigXmlWithMinimumFields(): void
    {
        $zip = $this->makeZip([
            'config.xml' => '<?xml version="1.0"?><theme><name>min</name></theme>',
            'style.css'  => 'x{}',
        ]);
        $entry = $this->svc->installFromZip($zip);
        $this->assertSame('min', $entry['name']);
        // No title in config -> derived from name.
        $this->assertSame('min', $entry['title']);
        $this->assertSame('', $entry['version']);
        @unlink($zip);
    }

    public function testHashZipPrefixesSha256(): void
    {
        $zip = $this->makeZip([
            'config.xml' => $this->configXml('hashy'),
            'style.css'  => 'x{}',
        ]);
        $entry = $this->svc->installFromZip($zip);
        $this->assertStringStartsWith('sha256:', $entry['checksum']);
        $this->assertSame(64, strlen(substr($entry['checksum'], 7)));
        @unlink($zip);
    }

    public function testFindCssFilesPrioritizesStyleCss(): void
    {
        $zip = $this->makeZip([
            'config.xml'  => $this->configXml('multi'),
            'style.css'   => '/* primary */',
            'extra.css'   => '/* extra */',
            'zzz-last.css' => '/* sort-after-extra */',
        ]);
        $entry = $this->svc->installFromZip($zip);
        $this->assertSame('style.css', $entry['css_files'][0]);
        $this->assertCount(3, $entry['css_files']);
        @unlink($zip);
    }

    public function testFindCssFilesStillWorksWithoutStyleCss(): void
    {
        $zip = $this->makeZip([
            'config.xml' => $this->configXml('alt'),
            'main.css'   => '/* main */',
        ]);
        $entry = $this->svc->installFromZip($zip);
        $this->assertSame(['main.css'], $entry['css_files']);
        @unlink($zip);
    }

    public function testValidateRejectsArchiveWhoseConfigIsNotAtPrefix(): void
    {
        $zip = $this->makeZip([
            'acme/config.xml' => $this->configXml('acme'),
            'outside.css'     => 'leak',
        ]);
        $this->expectException(\RuntimeException::class);
        try {
            $this->svc->validateZip($zip);
        } finally {
            @unlink($zip);
        }
    }

    public function testRecursiveDeleteRemovesNestedFilesAndDirs(): void
    {
        $root = $this->tmpRoot . '/delete-target';
        mkdir($root . '/inner/deep', 0755, true);
        file_put_contents($root . '/a.txt', 'a');
        file_put_contents($root . '/inner/b.txt', 'b');
        file_put_contents($root . '/inner/deep/c.txt', 'c');
        $this->assertDirectoryExists($root);
        StylesService::recursiveDelete($root);
        $this->assertDirectoryDoesNotExist($root);
    }

    // ---------- helpers ----------

    private function makeZip(array $entries): string
    {
        $path = tempnam(sys_get_temp_dir(), 'omkstyle') . '.zip';
        @unlink($path);
        $zip = new ZipArchive();
        if ($zip->open($path, ZipArchive::CREATE) !== true) {
            $this->fail('Could not create test zip');
        }
        foreach ($entries as $name => $contents) {
            $zip->addFromString($name, $contents);
        }
        $zip->close();
        return $path;
    }

    private function configXml(string $name, string $title = '', string $version = '1.0.0'): string
    {
        $title = $title === '' ? ucfirst($name) : $title;
        return '<?xml version="1.0"?>'
            . '<theme>'
            . '<name>' . htmlspecialchars($name) . '</name>'
            . '<title>' . htmlspecialchars($title) . '</title>'
            . '<version>' . htmlspecialchars($version) . '</version>'
            . '<author>Test</author>'
            . '<license>CC-BY-SA</license>'
            . '<description>Test theme.</description>'
            . '</theme>';
    }
}
