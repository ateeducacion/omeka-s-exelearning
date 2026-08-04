<?php

declare(strict_types=1);

namespace ExeLearningTest;

use ExeLearning\Module;
use Laminas\ServiceManager\ServiceLocatorInterface;

/**
 * Test seam over the module class.
 *
 * Module extends Omeka's AbstractModule, whose service locator is normally
 * injected by the module manager during bootstrap. Tests have no bootstrap, so
 * this subclass takes a container directly and promotes the protected helpers
 * to public visibility. Same pattern as TestableStylesController.
 *
 * No production behaviour is overridden: every method below either forwards to
 * its parent or sets state the parent would otherwise receive from Omeka.
 */
class TestableModule extends Module
{
    /** @var string|null Overrides the on-disk data directory during tests. */
    private $dataPathOverride = null;

    public function __construct(?ServiceLocatorInterface $services = null)
    {
        if ($services !== null) {
            $this->setServiceLocator($services);
        }
    }

    /**
     * Point getDataPath() at a temporary directory so cleanup tests never
     * touch the checkout's own data/exelearning tree.
     */
    public function setDataPathOverride(?string $path): void
    {
        $this->dataPathOverride = $path;
    }

    public function getDataPath(): string
    {
        return $this->dataPathOverride ?? parent::getDataPath();
    }

    public function callUpdateWhitelist(ServiceLocatorInterface $services): void
    {
        $this->updateWhitelist($services);
    }

    public function callCreateDataDirectory(): void
    {
        $this->createDataDirectory();
    }

    public function callRemoveEditorInstallerSettings(ServiceLocatorInterface $services): void
    {
        $this->removeEditorInstallerSettings($services);
    }

    public function callGetExeLearningItemIds(): array
    {
        return $this->getExeLearningItemIds();
    }

    public function callDeleteDirectory(string $dir): void
    {
        $this->deleteDirectory($dir);
    }

    public function callBuildContentUrl(string $hash): string
    {
        return $this->buildContentUrl($hash);
    }

    public function callExtractBasePath(string $uriPath): string
    {
        return $this->extractBasePath($uriPath);
    }

    /**
     * @param mixed $media
     */
    public function callIsTeacherModeVisible($media): bool
    {
        return $this->isTeacherModeVisible($media);
    }

    /**
     * @param mixed $media
     */
    public function callBuildContentPath(string $hash, $media): string
    {
        return $this->buildContentPath($hash, $media);
    }

    /**
     * @param mixed $media
     */
    public function callIsExeLearningFile($media): bool
    {
        return $this->isExeLearningFile($media);
    }

    /**
     * @param \Laminas\View\Renderer\PhpRenderer $renderer
     */
    public function callRenderStylesSection($renderer): string
    {
        return $this->renderStylesSection($renderer);
    }

    /**
     * @param \Laminas\View\Renderer\PhpRenderer $renderer
     */
    public function callRenderEditorStatusSection($renderer): string
    {
        return $this->renderEditorStatusSection($renderer);
    }
}
