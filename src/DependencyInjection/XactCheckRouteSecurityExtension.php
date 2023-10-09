<?php

declare(strict_types=1);

namespace Xact\CheckRouteSecurity\DependencyInjection;

use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;
use Symfony\Component\HttpKernel\DependencyInjection\ConfigurableExtension;

class XactCheckRouteSecurityExtension extends ConfigurableExtension
{
    /**
     * @param mixed[] $mergedConfig
     */
    public function loadInternal(array $mergedConfig, ContainerBuilder $container): void
    {
        $loader = new YamlFileLoader($container, new FileLocator(__DIR__ . '/../../config'));
        $loader->load('services.yaml');

        $container->setParameter('xact_check_route_security.exclude_routes', $mergedConfig['exclude_routes']);
    }
}
