<?php

declare(strict_types=1);

namespace Bolzer\SymfonyTypescriptRoutes\Tests;

use Bolzer\SymfonyTypescriptRoutes\Dto\GeneratorConfig;
use Bolzer\SymfonyTypescriptRoutes\Service\GeneratorService;
use PHPUnit\Framework\TestCase;
use Prophecy\PhpUnit\ProphecyTrait;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;
use Symfony\Component\Routing\RouterInterface;

class GenerateTest extends TestCase
{
    use ProphecyTrait;

    private const UPDATE_OUTPUT_FILES = false;

    public function generationServiceDataProvider(): \Generator
    {
        $routeCollection = new RouteCollection();
        $routeCollection->add('user_route', new Route('/user/{id}/notes/{noteId}', host: 'app.development.org', schemes: 'https'));
        $routeCollection->add('user_route_http', new Route('/user/{id}/notes/{noteId}', host: 'app.development.org', schemes: 'http'));
        $routeCollection->add('user_route_without_scheme', new Route('/user/{id}/notes/{noteId}', host: 'app.development.org'));
        $routeCollection->add('users_route_without_requirements_and_defaults', new Route('/users', host: 'app.development.org', schemes: 'https'));
        $routeCollection->add('users_route_with_requirements', new Route(
            path: '/users/{id}/{locale}',
            requirements: [
                'id' => '\d+',
                'locale' => 'en|fr',
            ],
            host: 'app.development.org',
            schemes: 'https'
        ));
        $routeCollection->add('users_route_with_requirements_and_defaults', new Route(
            path: '/users/{id}/{locale}',
            defaults: [
                'locale' => 'en',
            ],
            requirements: [
                'id' => '\d+',
                'locale' => 'en|fr',
            ],
            host: 'app.development.org',
            schemes: 'https'
        ));
        $routeCollection->add('users_route_with_requirements_and_null_defaults', new Route(
            path: '/users/{id}/{locale}',
            defaults: [
                'locale' => null,
            ],
            requirements: [
                'id' => '\d+',
                'locale' => 'en|fr',
            ],
            host: 'app.development.org',
            schemes: 'https'
        ));
        $routeCollection->add('generate_route_with_long_requirement_as_union', new Route(
            path: '/generate/{intent}/{documents}',
            requirements: [
                'intent' => 'new_email|email|print|printdebug|preview|preview_data|preview_text|gct.legalize',
            ],
            host: 'app.development.org',
            schemes: 'https'
        ));
        $routeCollection->add('generate_route_requirement_union_with_regex', new Route(
            path: '/generate/{id}',
            requirements: [
                'id' => 'new|\d+',
            ],
            host: 'app.development.org',
            schemes: 'https'
        ));

        $routeCollection->add('redirect_route_with_internal_defaults', new Route(
            path: '/old/{id}',
            defaults: [
                '_controller' => 'Symfony\\Bundle\\FrameworkBundle\\Controller\\RedirectController',
                'route' => 'new_route',
                'permanent' => true,
                'keepQueryParams' => true,
                'keepRequestMethod' => false,
            ],
            requirements: [
                'id' => '\d+',
            ],
            host: 'app.development.org',
            schemes: 'https'
        ));

        yield ['output.ts', $routeCollection, GeneratorConfig::generateEverything()];
        yield ['output_relative.ts', $routeCollection, GeneratorConfig::generateOnlyRelativeUrls()];
        yield ['output_absolute.ts', $routeCollection, GeneratorConfig::generateOnlyAbsoluteUrls()];
    }

    /** @dataProvider generationServiceDataProvider */
    public function testGenerationService(string $outputFileName, RouteCollection $collection, GeneratorConfig $config): void
    {
        $file = __DIR__ . '/' . $outputFileName;

        $service = new GeneratorService($this->getMockedRouter($collection));
        $result = implode("\n", $service->generate($config));

        if (self::UPDATE_OUTPUT_FILES) {
            \file_put_contents($file, $result);
        }

        static::assertStringEqualsFile(
            $file,
            $result
        );
    }

    public function testGenerationServiceWithAInvalidRouteForRelativeUrlGeneration(): void
    {
        $routeCollection = new RouteCollection();
        $routeCollection->add('user_route', new Route('/user/{id}/notes/{noteId}', host: 'app.development.org'));

        $routes = (new GeneratorService($this->getMockedRouter($routeCollection)))->generate(GeneratorConfig::generateOnlyRelativeUrls());

        self::assertCount(3, $routes);
    }

    /** @depends testGenerationService */
    public function testTSCCompilationOfOutput(): void
    {
        $output = null;
        $code = null;

        exec(__DIR__ . '/../node_modules/.bin/tsc', $output, $code);

        static::assertSame(0, $code);
        static::assertEmpty($output);
    }

    private function getMockedRouter(RouteCollection $collection): RouterInterface
    {
        $mock = $this->prophesize(RouterInterface::class);
        $mock->getRouteCollection()->willReturn($collection);
        return $mock->reveal();
    }
}
