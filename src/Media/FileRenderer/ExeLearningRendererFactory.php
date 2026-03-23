<?php
declare(strict_types=1);

namespace ExeLearning\Media\FileRenderer;

use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;
use ExeLearning\Service\ElpFileService;

class ExeLearningRendererFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, array $options = null)
    {
        $elpService = $services->get(ElpFileService::class);
        $request = $services->get('Request');
        return new ExeLearningRenderer($elpService, $request);
    }
}
