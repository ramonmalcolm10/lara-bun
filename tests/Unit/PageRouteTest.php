<?php

use LaraBun\Rsc\PageRoute;

test('make creates a new instance', function () {
    $route = PageRoute::make();

    expect($route)->toBeInstanceOf(PageRoute::class);
});

test('middleware accepts a string', function () {
    $route = PageRoute::make()->middleware('auth');

    expect($route->getMiddleware())->toBe(['auth']);
});

test('middleware accepts an array', function () {
    $route = PageRoute::make()->middleware(['auth', 'verified']);

    expect($route->getMiddleware())->toBe(['auth', 'verified']);
});

test('can sets ability and model', function () {
    $route = PageRoute::make()->can('view', 'Post');

    expect($route->getAbility())->toBe('view')
        ->and($route->getAbilityModel())->toBe('Post');
});

test('can sets ability without model', function () {
    $route = PageRoute::make()->can('admin');

    expect($route->getAbility())->toBe('admin')
        ->and($route->getAbilityModel())->toBeNull();
});

test('staticPaths accepts an array', function () {
    $route = PageRoute::make()->staticPaths(['hello', 'world']);

    expect($route->getStaticPaths())->toBe(['hello', 'world']);
});

test('staticPaths accepts a closure', function () {
    $route = PageRoute::make()->staticPaths(fn () => ['a', 'b']);

    expect($route->getStaticPaths())->toBeInstanceOf(Closure::class);
});

test('viewData accepts a closure', function () {
    $callback = fn (string $slug) => ['title' => $slug];
    $route = PageRoute::make()->viewData($callback);

    expect($route->getViewData())->toBe($callback);
});

test('name sets the route name', function () {
    $route = PageRoute::make()->name('docs.show');

    expect($route->getName())->toBe('docs.show');
});

test('where sets parameter constraints', function () {
    $route = PageRoute::make()
        ->where('slug', '[a-z-]+')
        ->where('id', '\d+');

    expect($route->getWhereConstraints())->toBe([
        'slug' => '[a-z-]+',
        'id' => '\d+',
    ]);
});

test('methods are chainable', function () {
    $route = PageRoute::make()
        ->middleware(['auth'])
        ->can('view', 'Post')
        ->staticPaths(['a'])
        ->viewData(fn () => [])
        ->name('test')
        ->where('id', '\d+');

    expect($route)->toBeInstanceOf(PageRoute::class);
});

test('defaults are empty', function () {
    $route = PageRoute::make();

    expect($route->getMiddleware())->toBe([])
        ->and($route->getAbility())->toBeNull()
        ->and($route->getAbilityModel())->toBeNull()
        ->and($route->getStaticPaths())->toBeNull()
        ->and($route->getViewData())->toBeNull()
        ->and($route->getName())->toBeNull()
        ->and($route->getWhereConstraints())->toBe([]);
});

test('forceDynamic sets isDynamic', function () {
    $route = PageRoute::make()->forceDynamic();

    expect($route->isDynamic())->toBeTrue();
});

test('forceStatic sets isForceStatic', function () {
    $route = PageRoute::make()->forceStatic();

    expect($route->isForceStatic())->toBeTrue();
});

test('domain sets the domain value', function () {
    $route = PageRoute::make()->domain('admin.example.com');

    expect($route->getDomain())->toBe('admin.example.com');
});

test('domain defaults to null', function () {
    $route = PageRoute::make();

    expect($route->getDomain())->toBeNull();
});

test('isDynamic defaults to false', function () {
    $route = PageRoute::make();

    expect($route->isDynamic())->toBeFalse();
});

test('isForceStatic defaults to false', function () {
    $route = PageRoute::make();

    expect($route->isForceStatic())->toBeFalse();
});
