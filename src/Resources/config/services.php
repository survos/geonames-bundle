<?php

declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

use Survos\GeonamesBundle\Command\GeoAuthorityCommand;
use Survos\GeonamesBundle\Service\GeoService;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

return static function (ContainerConfigurator $containerConfigurator): void {
    $services = $containerConfigurator->services();
    $services->defaults()
        ->autowire()
        ->autoconfigure();

    $services->set(GeoService::class)
        ->arg('$sqliteDir', '%survos_geonames.sqlite_dir%')
        ->arg('$projectDir', '%kernel.project_dir%');

    $services->set(GeoAuthorityCommand::class);
};
