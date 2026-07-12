<?php

declare(strict_types=1);

namespace ExeLearningTest\Service;

use ExeLearning\Service\PreviewFixedResources;
use ExeLearning\Service\PreviewSessionStore;
use ExeLearning\Service\PreviewSessionStoreFactory;
use Interop\Container\ContainerInterface;
use PHPUnit\Framework\TestCase;

/**
 * @covers \ExeLearning\Service\PreviewSessionStoreFactory
 */
class PreviewSessionStoreFactoryTest extends TestCase
{
    public function testDefaultStoreLivesUnderThePrivateSystemTempDirectory(): void
    {
        $store = (new PreviewSessionStoreFactory())(
            $this->container([], new PreviewFixedResources(sys_get_temp_dir())),
            PreviewSessionStore::class
        );
        $path = $this->basePath($store);
        $expectedPrefix = rtrim(sys_get_temp_dir(), '/\\') . '/omeka-s-exelearning-preview-';

        self::assertStringStartsWith($expectedPrefix, $path);
        self::assertStringNotContainsString(rtrim((string) OMEKA_PATH, '/\\') . '/files', $path);
    }

    public function testConfiguredPrivateStorePathOverridesTheDefault(): void
    {
        $configured = sys_get_temp_dir() . '/custom-omeka-preview-store/';
        $store = (new PreviewSessionStoreFactory())(
            $this->container(
                ['exelearning' => ['preview_store_path' => $configured]],
                new PreviewFixedResources(sys_get_temp_dir())
            ),
            PreviewSessionStore::class
        );

        self::assertSame(rtrim($configured, '/\\'), $this->basePath($store));
    }

    private function basePath(PreviewSessionStore $store): string
    {
        $property = new \ReflectionProperty($store, 'basePath');
        $property->setAccessible(true);
        return (string) $property->getValue($store);
    }

    private function container(array $config, PreviewFixedResources $fixed): ContainerInterface
    {
        return new class ($config, $fixed) implements ContainerInterface {
            private array $config;
            private PreviewFixedResources $fixed;

            public function __construct(array $config, PreviewFixedResources $fixed)
            {
                $this->config = $config;
                $this->fixed = $fixed;
            }

            public function get($id)
            {
                if ($id === 'Config') {
                    return $this->config;
                }
                if ($id === PreviewFixedResources::class) {
                    return $this->fixed;
                }
                throw new \RuntimeException('Unexpected service: ' . (string) $id);
            }

            public function has($id)
            {
                return $id === 'Config' || $id === PreviewFixedResources::class;
            }
        };
    }
}
