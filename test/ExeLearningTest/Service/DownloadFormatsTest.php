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
}
