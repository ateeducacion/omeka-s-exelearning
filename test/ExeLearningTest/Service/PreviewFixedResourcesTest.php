<?php

declare(strict_types=1);

namespace ExeLearningTest\Service;

use ExeLearning\Service\PreviewFixedResources;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the fixed-resource resolver (serving contract v2, layer 1).
 *
 * @covers \ExeLearning\Service\PreviewFixedResources
 */
class PreviewFixedResourcesTest extends TestCase
{
    private string $distRoot;

    protected function setUp(): void
    {
        $this->distRoot = sys_get_temp_dir() . '/exe-fixed-' . uniqid();
        mkdir($this->distRoot . '/bundles', 0755, true);
        mkdir($this->distRoot . '/libs/jquery', 0755, true);
        file_put_contents($this->distRoot . '/libs/jquery/jquery.min.js', 'window.jQuery=function(){};');
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->distRoot);
        if (is_dir($this->distRoot . '-out')) {
            $this->removeDir($this->distRoot . '-out');
        }
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (array_diff(scandir($dir), ['.', '..']) as $entry) {
            $path = $dir . '/' . $entry;
            is_dir($path) ? $this->removeDir($path) : unlink($path);
        }
        rmdir($dir);
    }

    private function writeManifest(array $resources): void
    {
        file_put_contents(
            $this->distRoot . '/bundles/preview-fixed-resources.json',
            json_encode(['schemaVersion' => 1, 'buildVersion' => '4.0.0', 'resources' => $resources])
        );
    }

    public function testResolvesAKnownResource(): void
    {
        $this->writeManifest([
            'libs/jquery/jquery.min.js' => ['path' => 'libs/jquery/jquery.min.js', 'size' => 26],
        ]);
        $fixed = new PreviewFixedResources($this->distRoot);

        $this->assertTrue($fixed->hasResource('libs/jquery/jquery.min.js'));
        $this->assertSame('window.jQuery=function(){};', $fixed->getResource('libs/jquery/jquery.min.js'));
        $this->assertSame(['libs/jquery/jquery.min.js'], $fixed->manifestIds());
    }

    public function testUnknownIdIsAMiss(): void
    {
        $this->writeManifest([
            'libs/jquery/jquery.min.js' => ['path' => 'libs/jquery/jquery.min.js', 'size' => 26],
        ]);
        $fixed = new PreviewFixedResources($this->distRoot);

        $this->assertFalse($fixed->hasResource('libs/unknown.js'));
        $this->assertNull($fixed->getResource('libs/unknown.js'));
    }

    public function testAbsentManifestDisablesTheFixedLayer(): void
    {
        // No manifest written.
        $fixed = new PreviewFixedResources($this->distRoot);

        $this->assertSame([], $fixed->manifestIds());
        $this->assertFalse($fixed->hasResource('libs/jquery/jquery.min.js'));
        $this->assertNull($fixed->getResource('libs/jquery/jquery.min.js'));
    }

    public function testInvalidManifestSchemaDisablesTheFixedLayer(): void
    {
        file_put_contents(
            $this->distRoot . '/bundles/preview-fixed-resources.json',
            json_encode(['schemaVersion' => 99, 'resources' => ['x' => ['path' => 'libs/jquery/jquery.min.js']]])
        );
        $fixed = new PreviewFixedResources($this->distRoot);

        $this->assertFalse($fixed->hasResource('x'));
    }

    public function testMalformedManifestJsonDisablesTheFixedLayer(): void
    {
        file_put_contents($this->distRoot . '/bundles/preview-fixed-resources.json', '{not json');
        $fixed = new PreviewFixedResources($this->distRoot);

        $this->assertSame([], $fixed->manifestIds());
    }

    public function testEntriesWithoutAStringPathAreIgnored(): void
    {
        $this->writeManifest([
            'good' => ['path' => 'libs/jquery/jquery.min.js', 'size' => 26],
            'bad' => ['size' => 10],
            'alsoBad' => 'not-an-array',
        ]);
        $fixed = new PreviewFixedResources($this->distRoot);

        $this->assertTrue($fixed->hasResource('good'));
        $this->assertFalse($fixed->hasResource('bad'));
        $this->assertFalse($fixed->hasResource('alsoBad'));
    }

    public function testRejectsManifestPathEscapingTheRoot(): void
    {
        // A tampered manifest that points outside the distribution root must not
        // resolve, even though the id exists.
        $secret = $this->distRoot . '-out';
        mkdir($secret, 0755, true);
        file_put_contents($secret . '/secret.txt', 'TOP-SECRET');
        $this->writeManifest([
            'escape' => ['path' => '../' . basename($secret) . '/secret.txt', 'size' => 10],
        ]);
        $fixed = new PreviewFixedResources($this->distRoot);

        $this->assertTrue($fixed->hasResource('escape'));
        $this->assertNull($fixed->getResource('escape'));
    }

    public function testRejectsAbsoluteManifestPath(): void
    {
        $this->writeManifest([
            'abs' => ['path' => '/etc/hosts', 'size' => 10],
        ]);
        $fixed = new PreviewFixedResources($this->distRoot);

        $this->assertNull($fixed->getResource('abs'));
    }

    public function testMissingFileOnDiskIsAMiss(): void
    {
        $this->writeManifest([
            'gone' => ['path' => 'libs/removed.js', 'size' => 10],
        ]);
        $fixed = new PreviewFixedResources($this->distRoot);

        $this->assertTrue($fixed->hasResource('gone'));
        $this->assertNull($fixed->getResource('gone'));
    }

    public function testRejectsEmptyManifestPath(): void
    {
        $this->writeManifest([
            'empty' => ['path' => '', 'size' => 0],
        ]);
        $fixed = new PreviewFixedResources($this->distRoot);

        $this->assertTrue($fixed->hasResource('empty'));
        $this->assertNull($fixed->getResource('empty'));
    }

    public function testReturnsNullWhenDistRootDoesNotExist(): void
    {
        // Manifest lives outside a distribution root that does not exist: the id
        // is known but the containment realpath() of the root fails, so no bytes
        // resolve.
        $manifest = $this->distRoot . '/bundles/preview-fixed-resources.json';
        $this->writeManifest([
            'libs/jquery/jquery.min.js' => ['path' => 'libs/jquery/jquery.min.js', 'size' => 26],
        ]);
        $fixed = new PreviewFixedResources('/no/such/dist/root', $manifest);

        $this->assertTrue($fixed->hasResource('libs/jquery/jquery.min.js'));
        $this->assertNull($fixed->getResource('libs/jquery/jquery.min.js'));
    }

    public function testManifestPathAccessor(): void
    {
        $fixed = new PreviewFixedResources('/some/root/');
        $this->assertSame('/some/root/bundles/preview-fixed-resources.json', $fixed->manifestPath());

        $custom = new PreviewFixedResources('/some/root', '/elsewhere/manifest.json');
        $this->assertSame('/elsewhere/manifest.json', $custom->manifestPath());
    }
}
