<?php

use LaraBun\Rsc\PageDefinition;

test('constructor sets all properties', function () {
    $page = new PageDefinition(
        componentName: 'app/docs/[slug]/page',
        urlPattern: 'docs/{slug}',
        layouts: ['app/layout', 'app/docs/layout'],
        isDynamic: true,
        routeConfigPath: '/path/to/route.php',
        directoryConfigPaths: ['/path/to/parent/route.php'],
    );

    expect($page->componentName)->toBe('app/docs/[slug]/page')
        ->and($page->urlPattern)->toBe('docs/{slug}')
        ->and($page->layouts)->toBe(['app/layout', 'app/docs/layout'])
        ->and($page->isDynamic)->toBeTrue()
        ->and($page->routeConfigPath)->toBe('/path/to/route.php')
        ->and($page->directoryConfigPaths)->toBe(['/path/to/parent/route.php']);
});

test('static page has isDynamic false', function () {
    $page = new PageDefinition(
        componentName: 'app/about/page',
        urlPattern: 'about',
        layouts: [],
        isDynamic: false,
        routeConfigPath: null,
        directoryConfigPaths: [],
    );

    expect($page->isDynamic)->toBeFalse()
        ->and($page->routeConfigPath)->toBeNull()
        ->and($page->directoryConfigPaths)->toBe([]);
});

test('root page definition', function () {
    $page = new PageDefinition(
        componentName: 'app/page',
        urlPattern: '/',
        layouts: ['app/layout'],
        isDynamic: false,
        routeConfigPath: null,
        directoryConfigPaths: [],
    );

    expect($page->componentName)->toBe('app/page')
        ->and($page->urlPattern)->toBe('/');
});
