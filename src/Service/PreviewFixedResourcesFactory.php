<?php
declare(strict_types=1);

namespace ExeLearning\Service;

use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;

/**
 * Builds the fixed-resource resolver against the installed static editor.
 *
 * The manifest ships inside the editor distribution, which since ADR-0001 is a
 * release artifact bundled under {module}/dist/static, so its ids resolve under
 * that root.
 */
class PreviewFixedResourcesFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $container, $requestedName, array $options = null)
    {
        return new PreviewFixedResources(EditorBundle::getPath());
    }
}
