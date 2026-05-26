<?php
declare(strict_types=1);

namespace ExeLearningTest\Service;

use ExeLearning\Service\DownloadFormats;
use PHPUnit\Framework\TestCase;

final class DownloadFormatsTest extends TestCase
{
    public function testAllReturnsCanonicalRegistry(): void
    {
        $all = DownloadFormats::all();
        $ids = array_map(static fn ($f) => $f['id'], $all);

        $this->assertSame(['elpx', 'html5', 'scorm12', 'ims', 'epub3'], $ids);

        foreach ($all as $fmt) {
            $this->assertArrayHasKey('id', $fmt);
            $this->assertArrayHasKey('label', $fmt);
            $this->assertArrayHasKey('suffix', $fmt);
            $this->assertArrayHasKey('mime', $fmt);
            $this->assertArrayHasKey('client', $fmt);
        }
    }

    public function testSuffixesMatchSpecification(): void
    {
        $bySuffix = [];
        foreach (DownloadFormats::all() as $fmt) {
            $bySuffix[$fmt['id']] = $fmt['suffix'];
        }
        $this->assertSame('.elpx', $bySuffix['elpx']);
        $this->assertSame('_web.zip', $bySuffix['html5']);
        $this->assertSame('_scorm.zip', $bySuffix['scorm12']);
        $this->assertSame('_ims.zip', $bySuffix['ims']);
        $this->assertSame('.epub', $bySuffix['epub3']);
    }

    public function testDefaultIdsMatchAll(): void
    {
        $this->assertSame(
            ['elpx', 'html5', 'scorm12', 'ims', 'epub3'],
            DownloadFormats::defaultIds()
        );
    }

    public function testSanitizeAcceptsArrayAndPreservesCanonicalOrder(): void
    {
        $this->assertSame(
            ['elpx', 'epub3'],
            DownloadFormats::sanitize(['epub3', 'elpx'])
        );
    }

    public function testSanitizeDropsUnknownIds(): void
    {
        $this->assertSame(
            ['html5', 'ims'],
            DownloadFormats::sanitize(['html5', 'unknown', 'ims', 'scorm2004'])
        );
    }

    public function testSanitizeAcceptsCommaSeparatedString(): void
    {
        $this->assertSame(
            ['html5', 'scorm12'],
            DownloadFormats::sanitize('scorm12, html5 , unknown')
        );
    }

    public function testSanitizeFallsBackToDefaultsForInvalidInput(): void
    {
        $this->assertSame(DownloadFormats::defaultIds(), DownloadFormats::sanitize(null));
        $this->assertSame(DownloadFormats::defaultIds(), DownloadFormats::sanitize(42));
    }

    public function testGetReturnsFormatById(): void
    {
        $fmt = DownloadFormats::get('epub3');
        $this->assertNotNull($fmt);
        $this->assertSame('epub3', $fmt['id']);
        $this->assertSame('.epub', $fmt['suffix']);
        $this->assertSame('application/epub+zip', $fmt['mime']);
    }

    public function testGetReturnsNullForUnknownId(): void
    {
        $this->assertNull(DownloadFormats::get('does-not-exist'));
    }

    public function testFromSettingsFallsBackToDefaultsWhenSettingsNull(): void
    {
        $this->assertSame(DownloadFormats::defaultIds(), DownloadFormats::fromSettings(null));
    }

    public function testFromSettingsReadsStoredArray(): void
    {
        $settings = new class () {
            public function get($key, $default = null)
            {
                return $key === 'exelearning_download_formats' ? ['epub3', 'html5'] : $default;
            }
        };
        $this->assertSame(['html5', 'epub3'], DownloadFormats::fromSettings($settings));
    }

    public function testFromSettingsDecodesJsonStringValue(): void
    {
        $settings = new class () {
            public function get($key, $default = null)
            {
                return $key === 'exelearning_download_formats'
                    ? json_encode(['ims', 'scorm12'])
                    : $default;
            }
        };
        $this->assertSame(['scorm12', 'ims'], DownloadFormats::fromSettings($settings));
    }

    public function testFromSettingsFallsBackOnMissingKey(): void
    {
        $settings = new class () {
            public function get($key, $default = null)
            {
                return $default;
            }
        };
        $this->assertSame(DownloadFormats::defaultIds(), DownloadFormats::fromSettings($settings));
    }

    public function testRenderSplitButtonReturnsEmptyForEmptyList(): void
    {
        $view = $this->makeView();
        $media = $this->makeMedia();
        $this->assertSame('', DownloadFormats::renderSplitButton($view, $media, []));
    }

    public function testRenderSplitButtonRendersPrimaryOnly(): void
    {
        $view = $this->makeView();
        $media = $this->makeMedia();
        $html = DownloadFormats::renderSplitButton($view, $media, ['elpx']);

        $this->assertStringContainsString('data-format="elpx"', $html);
        $this->assertStringContainsString('data-suffix=".elpx"', $html);
        $this->assertStringContainsString('class="exelearning-download__primary"', $html);
        $this->assertStringNotContainsString('exelearning-download__toggle', $html);
        $this->assertStringNotContainsString('exelearning-download__menu', $html);
    }

    public function testRenderSplitButtonRendersDropdownForMultipleFormats(): void
    {
        $view = $this->makeView();
        $media = $this->makeMedia();
        $html = DownloadFormats::renderSplitButton($view, $media, ['html5', 'scorm12', 'epub3']);

        // Primary = first
        $this->assertMatchesRegularExpression('/exelearning-download__primary[^>]*data-format="html5"/', $html);
        // Other two appear in the menu list
        $this->assertStringContainsString('<ul class="exelearning-download__menu"', $html);
        $this->assertStringContainsString('data-format="scorm12"', $html);
        $this->assertStringContainsString('data-format="epub3"', $html);
        $this->assertStringContainsString('data-mime="application/epub+zip"', $html);
        $this->assertStringContainsString('data-suffix="_scorm.zip"', $html);
    }

    public function testRenderSplitButtonExposesMediaDataAttributes(): void
    {
        $view = $this->makeView();
        $media = $this->makeMedia(7, 'my-project.elpx', 'http://example.com/files/my-project.elpx');
        $html = DownloadFormats::renderSplitButton($view, $media, ['elpx', 'html5']);

        $this->assertStringContainsString('data-media-id="7"', $html);
        $this->assertStringContainsString('data-elp-url="http://example.com/files/my-project.elpx"', $html);
        $this->assertStringContainsString('data-slug="my-project"', $html);
    }

    public function testRenderSplitButtonFallsBackToMediaIdSlugWhenFilenameMissing(): void
    {
        $view = $this->makeView();
        $media = $this->makeMedia(99, '');
        $html = DownloadFormats::renderSplitButton($view, $media, ['elpx']);

        $this->assertStringContainsString('data-slug="media-99"', $html);
    }

    public function testEnqueueDownloadAssetsEnqueuesScriptAndI18n(): void
    {
        $view = $this->makeView();
        DownloadFormats::enqueueDownloadAssets($view);

        // Calling twice should not re-enqueue, but it should not error.
        DownloadFormats::enqueueDownloadAssets($view);

        $appended = $view->headScriptCalls;
        $this->assertGreaterThanOrEqual(2, count($appended));
        $hasFile = false;
        $hasInline = false;
        foreach ($appended as $call) {
            if ($call['kind'] === 'file' && str_contains($call['value'], 'omeka-exe-download.js')) {
                $hasFile = true;
            }
            if ($call['kind'] === 'script' && str_contains($call['value'], 'OMEKA_EXE_DOWNLOAD_I18N')) {
                $hasInline = true;
            }
        }
        $this->assertTrue($hasFile, 'omeka-exe-download.js was not enqueued');
        $this->assertTrue($hasInline, 'i18n inline script was not enqueued');
    }

    /**
     * Minimal view double satisfying the helper API we exercise.
     */
    private function makeView(): object
    {
        return new class () {
            public array $headScriptCalls = [];
            public function escapeHtml($v): string
            {
                return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
            }
            public function escapeHtmlAttr($v): string
            {
                return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
            }
            public function translate($v): string
            {
                return (string) $v;
            }
            public function assetUrl(string $path, string $module): string
            {
                return '/modules/' . $module . '/asset/' . ltrim($path, '/');
            }
            public function headScript(): object
            {
                $self = $this;
                return new class ($self) {
                    private $parent;
                    public function __construct($p)
                    {
                        $this->parent = $p;
                    }
                    public function appendFile(string $url): void
                    {
                        $this->parent->headScriptCalls[] = ['kind' => 'file', 'value' => $url];
                    }
                    public function appendScript(string $script): void
                    {
                        $this->parent->headScriptCalls[] = ['kind' => 'script', 'value' => $script];
                    }
                };
            }
        };
    }

    /**
     * Minimal media double exposing the API used by renderSplitButton.
     */
    private function makeMedia(int $id = 1, string $filename = 'test.elpx', string $url = 'http://example.com/original/file.elpx'): object
    {
        return new class ($id, $filename, $url) {
            public function __construct(private int $id_, private string $filename_, private string $url_)
            {
            }
            public function id(): int
            {
                return $this->id_;
            }
            public function filename(): string
            {
                return $this->filename_;
            }
            public function originalUrl(): string
            {
                return $this->url_;
            }
        };
    }
}
