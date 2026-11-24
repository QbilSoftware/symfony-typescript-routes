<?php

declare(strict_types=1);

namespace Bolzer\SymfonyTypescriptRoutes\Tests;

use Bolzer\SymfonyTypescriptRoutes\Extension\TypescriptPathExtension;
use Bolzer\SymfonyTypescriptRoutes\Service\GeneratorService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;

class ExtensionTest extends TestCase
{
    public function testExtension(): void
    {
        $container = new ContainerBuilder();

        (new TypescriptPathExtension())->load([], $container);

        self::assertTrue($container->hasDefinition(GeneratorService::class));
        $definition = $container->getDefinition(GeneratorService::class);
        self::assertTrue($definition->isPublic());
        self::assertTrue($definition->isAutowired());
        self::assertTrue($definition->isAutoconfigured());
    }
}
