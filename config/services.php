<?php

declare(strict_types=1);

use OpenStudio\QueryBuilderBundle\Form\QueryBuilderType;
use OpenStudio\QueryBuilderBundle\Service\FieldNormalizer;
use OpenStudio\QueryBuilderBundle\Service\FormOptionsNormalizer;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\param;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

return static function (ContainerConfigurator $container): void {
    $services = $container->services()
        ->defaults()
        ->autowire()
        ->autoconfigure();

    $services->set(FieldNormalizer::class);

    $services->set(FormOptionsNormalizer::class)
        ->arg('$fieldNormalizer', service(FieldNormalizer::class));

    $services->set(QueryBuilderType::class)
        ->arg('$formOptionsNormalizer', service(FormOptionsNormalizer::class))
        ->arg('$defaultLocale', param('kernel.default_locale'));
};
