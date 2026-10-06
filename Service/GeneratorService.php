<?php

declare(strict_types=1);

namespace Bolzer\SymfonyTypescriptRoutes\Service;

use Bolzer\SymfonyTypescriptRoutes\Dto\GeneratorConfig;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouterInterface;

final readonly class GeneratorService
{
    public function __construct(
        private RouterInterface $router,
    ) {}

    /** @return array<string> */
    public function generate(GeneratorConfig $config): array
    {
        $this->assertValidConfiguration($config);

        $buffer = [
            ...\array_values($this->getTypescriptUtilityFunctions()),
        ];

        foreach ($this->router->getRouteCollection()->all() as $name => $route) {
            $buffer[] = $this->buildFunctionForRoute($config, $name, $route);
        }

        return $buffer;
    }

    /** @return array<string> */
    private function getTypescriptUtilityFunctions(): array
    {
        return [
            'const replaceRouteParams = (rawRoute: string, routeParams: Record<string, string|number|null>): string => {Object.entries(routeParams).forEach(([key, value]) => rawRoute = rawRoute.replace(`{${key}}`, value as string)); return rawRoute;}',
            'const appendQueryParams = (route: string, queryParams?: Record<string, string|number>): string => queryParams ? route + "?" + new URLSearchParams(queryParams as Record<string, string>).toString() : route;',
        ];
    }

    private function buildFunctionForRoute(GeneratorConfig $config, string $routeName, Route $route): string
    {
        $relativeRouteVariables = $this->retrieveVariablesFromRoutePath($route);
        $absoluteRouteVariables = $this->retrieveVariablesFromAbsoluteRoutePath($route);

        $buffer = [
            'export const ',
            $this->sanitizeRouteFunctionName($routeName),
            ' = (): { ',
        ];

        if ($config->isGenerateRelativeUrls()) {
            $buffer = array_merge($buffer, [
                'relative: (',
                $this->createRouteParamFunctionArgument($route, $relativeRouteVariables),
                $this->createQueryParamFunctionArgument(),
                ') => string, ',
            ]);
        }

        if ($config->isGenerateAbsoluteUrls()) {
            $buffer = array_merge($buffer, [
                'absolute: (',
                $this->createRouteParamFunctionArgument($route, $absoluteRouteVariables),
                $this->createQueryParamFunctionArgument(),
                ') => string',
            ]);
        }

        $buffer = array_merge($buffer, [
            '} => {',
            'return {',
        ]);

        if ($config->isGenerateRelativeUrls()) {
            $buffer = array_merge($buffer, [
                'relative: (',
                $this->createRouteParamFunctionArgument($route, $relativeRouteVariables),
                $this->createQueryParamFunctionArgument(),
                '): string => ' . $this->createFunctionCallForRelativePath($route, $relativeRouteVariables) . ', ',
            ]);
        }

        if ($config->isGenerateAbsoluteUrls()) {
            $buffer = array_merge($buffer, [
                'absolute: (',
                $this->createRouteParamFunctionArgument($route, $absoluteRouteVariables),
                $this->createQueryParamFunctionArgument(),
                '): string => ' . $this->createFunctionCallForAbsolutePath($route, $absoluteRouteVariables),
            ]);
        }

        $buffer = array_merge($buffer, [
            '}',
            '};',
        ]);

        return \implode('', $buffer);
    }

    /** @return array<string> */
    private function retrieveVariablesFromRoutePath(Route $route): array
    {
        $matches = [];

        preg_match_all(
            '/{(.*?)}/m',
            $route->getPath(),
            $matches,
            PREG_SET_ORDER,
            0
        );

        if (!$matches) {
            return [];
        }

        $buffer = [];
        foreach ($matches as $match) {
            $buffer[] = $match[1];
        }

        return $buffer;
    }

    /** @return array<string> */
    private function retrieveVariablesFromAbsoluteRoutePath(Route $route): array
    {
        $availableSchemes = $route->getSchemes();

        $usedScheme = '{scheme}';

        if (\in_array('http', $availableSchemes, true)) {
            $usedScheme = 'http';
        }

        if (\in_array('https', $availableSchemes, true)) {
            $usedScheme = 'https';
        }

        $url = \sprintf('%s://%s%s', $usedScheme, $route->getHost(), $route->getPath());

        $matches = [];

        preg_match_all(
            '/{(.*?)}/m',
            $url,
            $matches,
            PREG_SET_ORDER,
            0
        );

        if (!$matches) {
            return [];
        }

        return array_map(static fn (array $match) => $match[1], $matches);
    }

    private function sanitizeRouteFunctionName(string $routeName): string
    {
        $sanitizedPath = preg_replace('/\W/', '_', $routeName);

        if (\str_starts_with($sanitizedPath, '_')) {
            return 'path' . $sanitizedPath;
        }

        return 'path_' . preg_replace('/\W/', '_', $routeName);
    }

    private function createRouteParamFunctionArgument(Route $route, array $variables): string
    {
        if (!$variables) {
            return '';
        }

        return 'routeParams: {' . \implode(', ', array_map(
            function (string $variable) use ($route) {
                if (array_key_exists($variable, $route->getDefaults())) {
                    return sprintf('%s?:%s', $variable, $this->guessTypeOfPathVariable($route, $variable));
                }

                return sprintf('%s:%s', $variable, $this->guessTypeOfPathVariable($route, $variable));
            },
            $variables
        )) . '}, ';
    }

    private function createQueryParamFunctionArgument(): string
    {
        return 'queryParams?: Record<string, string>';
    }

    private function createFunctionCallForRelativePath(Route $route, array $variables): string
    {
        if ($variables) {
            return \implode('', [
                'appendQueryParams(',
                "replaceRouteParams('",
                $route->getPath(),
                sprintf("', %s", $this->createRouteParamsMergeExpressionForDefaults($route, $variables)),
                '), queryParams',
                ')',
            ]);
        }

        return \implode('', [
            "appendQueryParams('",
            $route->getPath(),
            "', queryParams",
            ')',
        ]);
    }

    private function createFunctionCallForAbsolutePath(Route $route, array $variables): string
    {
        $absolutePath = sprintf('%s://%s%s', $this->retrieveSchemeFromRoute($route), $route->getHost(), $route->getPath());

        if ($variables) {
            return \implode('', [
                'appendQueryParams(',
                "replaceRouteParams('",
                $absolutePath,
                sprintf("', %s", $this->createRouteParamsMergeExpressionForDefaults($route, $variables)),
                '), queryParams',
                ')',
            ]);
        }

        return \implode('', [
            "appendQueryParams('",
            $absolutePath,
            "', queryParams",
            ')',
        ]);
    }

    private function guessTypeOfPathVariable(Route $route, string $variable): string
    {
        $requirement = $route->getRequirement($variable);

        if ($requirement === null) {
            return 'string';
        }

        if ($this->isDigitRequirement($requirement)) {
            return 'number';
        }

        if ($this->isUnionRequirement($requirement)) {
            return $this->deriveUnionExpressionForTypescript($requirement);
        }

        return 'string';
    }

    private function isDigitRequirement(string $requirement): bool
    {
        return $requirement === '\d+';
    }

    private function isUnionRequirement(string $requirement): bool
    {
        return str_contains($requirement, '|');
    }

    private function isPhpRegexExpression(string $requirement): bool
    {
        return str_starts_with($requirement, '\\');
    }

    /**
     * Only defaults that fill a placeholder in the path belong in the route params. A route also carries
     * internal defaults such as `_controller`, or the `route`/`permanent` options of a RedirectController,
     * and those would break the generated signature.
     *
     * @param array<string> $variables
     */
    private function createRouteParamsMergeExpressionForDefaults(Route $route, array $variables): string
    {
        $defaults = array_intersect_key($route->getDefaults(), array_flip($variables));

        if (!$defaults) {
            return 'routeParams';
        }

        return sprintf('{...%s, ...routeParams}', json_encode($defaults, JSON_THROW_ON_ERROR));
    }

    private function deriveUnionExpressionForTypescript(string $requirement): string
    {
        $matches = explode('|', $requirement);

        if (count($matches) < 2) {
            throw new \LogicException('At this point union parts must be greater than 2!');
        }

        $transformedMatches = array_map(function ($match) {
            if ($this->isDigitRequirement($match)) {
                return 'number';
            }

            if ($this->isPhpRegexExpression($match)) {
                return 'string';
            }

            return $match;
        }, $matches);

        return implode('|', array_map(function (string $matchFromBuffer) {
            if ($matchFromBuffer === 'string' || $matchFromBuffer === 'number') {
                return sprintf('%s', $matchFromBuffer);
            }

            return sprintf("'%s'", $matchFromBuffer);
        }, $transformedMatches));
    }

    private function retrieveSchemeFromRoute(Route $route): string
    {
        $availableSchemes = $route->getSchemes();

        $usedScheme = '{scheme}';

        if (\in_array('http', $availableSchemes, true)) {
            $usedScheme = 'http';
        }

        if (\in_array('https', $availableSchemes, true)) {
            $usedScheme = 'https';
        }

        return $usedScheme;
    }

    private function assertValidConfiguration(GeneratorConfig $config): void
    {
        if (!$config->isGenerateAbsoluteUrls() && !$config->isGenerateRelativeUrls()) {
            throw new \InvalidArgumentException('Configuration invalid. You should not set generateAbsoluteUrls and generateRelativeUrls to false.');
        }
    }
}
