<?php

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Route;
use LaraBun\Http\Middleware\ServeStaticRsc;
use LaraBun\Rsc\PageController;
use LaraBun\Rsc\PageDefinition;
use LaraBun\Rsc\PageRouteRegistrar;
use LaraBun\Rsc\PageScanner;

test('registers a static route for root page', function () {
    $registrar = new PageRouteRegistrar(app('router'));

    $registrar->register([
        new PageDefinition(
            componentName: 'app/page',
            urlPattern: '/',
            layouts: ['app/layout'],
            isDynamic: false,
            routeConfigPath: null,
            directoryConfigPaths: [],
        ),
    ]);

    $route = collect(app('router')->getRoutes()->getRoutes())
        ->first(fn ($r) => ($r->defaults['_rsc_component'] ?? null) === 'app/page');

    expect($route)->not->toBeNull()
        ->and($route->uri())->toBe('/')
        ->and($route->defaults['_rsc_layouts'])->toBe(['app/layout'])
        ->and($route->getActionName())->toContain('PageController');

    // Static pages get ServeStaticRsc middleware
    $middleware = $route->gatherMiddleware();
    expect($middleware)->toContain(ServeStaticRsc::class);
});

test('registers a dynamic route with static middleware', function () {
    $registrar = new PageRouteRegistrar(app('router'));

    $registrar->register([
        new PageDefinition(
            componentName: 'app/docs/[slug]/page',
            urlPattern: 'docs/{slug}',
            layouts: ['app/layout'],
            isDynamic: true,
            routeConfigPath: null,
            directoryConfigPaths: [],
        ),
    ]);

    $route = collect(app('router')->getRoutes()->getRoutes())
        ->first(fn ($r) => ($r->defaults['_rsc_component'] ?? null) === 'app/docs/[slug]/page');

    expect($route)->not->toBeNull()
        ->and($route->uri())->toBe('docs/{slug}');

    $middleware = $route->gatherMiddleware();
    expect($middleware)->toContain(ServeStaticRsc::class);
});

test('auto-generates route names', function () {
    $registrar = new PageRouteRegistrar(app('router'));

    $registrar->register([
        new PageDefinition(
            componentName: 'app/page',
            urlPattern: '/',
            layouts: [],
            isDynamic: false,
            routeConfigPath: null,
            directoryConfigPaths: [],
        ),
        new PageDefinition(
            componentName: 'app/about/page',
            urlPattern: 'about',
            layouts: [],
            isDynamic: false,
            routeConfigPath: null,
            directoryConfigPaths: [],
        ),
        new PageDefinition(
            componentName: 'app/docs/[slug]/page',
            urlPattern: 'docs/{slug}',
            layouts: [],
            isDynamic: true,
            routeConfigPath: null,
            directoryConfigPaths: [],
        ),
    ]);

    expect(app('router')->getRoutes()->getByName('rsc.index'))->not->toBeNull()
        ->and(app('router')->getRoutes()->getByName('rsc.about'))->not->toBeNull()
        ->and(app('router')->getRoutes()->getByName('rsc.docs.slug'))->not->toBeNull();
});

test('applies catch-all constraint', function () {
    $registrar = new PageRouteRegistrar(app('router'));

    $registrar->register([
        new PageDefinition(
            componentName: 'app/blog/[...path]/page',
            urlPattern: 'blog/{path}',
            layouts: [],
            isDynamic: true,
            routeConfigPath: null,
            directoryConfigPaths: [],
        ),
    ]);

    $route = collect(app('router')->getRoutes()->getRoutes())
        ->first(fn ($r) => ($r->defaults['_rsc_component'] ?? null) === 'app/blog/[...path]/page');

    expect($route->wheres['path'] ?? null)->toBe('.*');
});

test('applies route.php middleware', function () {
    $configDir = sys_get_temp_dir().'/rsc-config-test-'.uniqid();
    mkdir($configDir, 0755, true);

    file_put_contents($configDir.'/route.php', <<<'PHP'
<?php
use LaraBun\Rsc\PageRoute;

return PageRoute::make()->middleware(['auth', 'verified']);
PHP);

    $registrar = new PageRouteRegistrar(app('router'));

    $registrar->register([
        new PageDefinition(
            componentName: 'app/docs/page',
            urlPattern: 'docs',
            layouts: [],
            isDynamic: false,
            routeConfigPath: $configDir.'/route.php',
            directoryConfigPaths: [],
        ),
    ]);

    $route = collect(app('router')->getRoutes()->getRoutes())
        ->first(fn ($r) => ($r->defaults['_rsc_component'] ?? null) === 'app/docs/page');

    $middleware = $route->gatherMiddleware();
    expect($middleware)->toContain('auth')
        ->and($middleware)->toContain('verified');

    unlink($configDir.'/route.php');
    rmdir($configDir);
});

test('merges directory-level and page-level middleware', function () {
    $dirConfigDir = sys_get_temp_dir().'/rsc-dir-config-'.uniqid();
    $pageConfigDir = sys_get_temp_dir().'/rsc-page-config-'.uniqid();
    mkdir($dirConfigDir, 0755, true);
    mkdir($pageConfigDir, 0755, true);

    file_put_contents($dirConfigDir.'/route.php', <<<'PHP'
<?php
use LaraBun\Rsc\PageRoute;

return PageRoute::make()->middleware(['auth']);
PHP);

    file_put_contents($pageConfigDir.'/route.php', <<<'PHP'
<?php
use LaraBun\Rsc\PageRoute;

return PageRoute::make()->middleware(['verified']);
PHP);

    $registrar = new PageRouteRegistrar(app('router'));

    $registrar->register([
        new PageDefinition(
            componentName: 'app/docs/[slug]/page',
            urlPattern: 'docs/{slug}',
            layouts: [],
            isDynamic: true,
            routeConfigPath: $pageConfigDir.'/route.php',
            directoryConfigPaths: [$dirConfigDir.'/route.php'],
        ),
    ]);

    $route = collect(app('router')->getRoutes()->getRoutes())
        ->first(fn ($r) => ($r->defaults['_rsc_component'] ?? null) === 'app/docs/[slug]/page');

    $middleware = $route->gatherMiddleware();
    expect($middleware)->toContain('auth')
        ->and($middleware)->toContain('verified');

    unlink($dirConfigDir.'/route.php');
    rmdir($dirConfigDir);
    unlink($pageConfigDir.'/route.php');
    rmdir($pageConfigDir);
});

test('scanner and registrar work end-to-end', function () {
    $appDir = sys_get_temp_dir().'/rsc-e2e-'.uniqid();
    mkdir($appDir, 0755, true);
    mkdir($appDir.'/about', 0755, true);
    mkdir($appDir.'/docs/[slug]', 0755, true);
    mkdir($appDir.'/(marketing)/pricing', 0755, true);

    file_put_contents($appDir.'/layout.tsx', '// root layout');
    file_put_contents($appDir.'/page.tsx', '// root page');
    file_put_contents($appDir.'/about/page.tsx', '// about page');
    file_put_contents($appDir.'/docs/[slug]/page.tsx', '// doc page');
    file_put_contents($appDir.'/(marketing)/pricing/page.tsx', '// pricing page');

    $scanner = new PageScanner($appDir);
    $scanner->scan();
    $pages = $scanner->getPages();

    expect($pages)->toHaveCount(4);

    $urls = collect($pages)->pluck('urlPattern')->sort()->values()->all();
    expect($urls)->toContain('/')
        ->and($urls)->toContain('about')
        ->and($urls)->toContain('docs/{slug}')
        ->and($urls)->toContain('pricing');

    // Clean up
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($appDir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );

    foreach ($iterator as $file) {
        $file->isDir() ? rmdir($file->getRealPath()) : unlink($file->getRealPath());
    }

    rmdir($appDir);
});
