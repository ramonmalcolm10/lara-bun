<?php

use LaraBun\BunBridge;
use LaraBun\Rsc\Header;

beforeEach(function () {
    $this->bridgeMock = Mockery::mock(BunBridge::class);
    $this->app->instance(BunBridge::class, $this->bridgeMock);
});

test('rsc response passes layouts to BunBridge rscHtmlStream on initial load', function () {
    $this->bridgeMock
        ->shouldReceive('rscHtmlStream')
        ->once()
        ->withArgs(function (string $component, array $props, array $layouts) {
            return $component === 'Dashboard'
                && $props === ['userId' => 1]
                && $layouts === [
                    ['component' => 'AppLayout', 'props' => []],
                ];
        })
        ->andReturnUsing(function () {
            return (function () {
                yield ['clientChunks' => []];
                yield '<div>Dashboard</div>';
                yield ['rscPayload' => ''];
            })();
        });

    Route::get('/test-layout', fn () => rsc('Dashboard', ['userId' => 1])
        ->layout('AppLayout'));

    $this->get('/test-layout')->assertStatus(200);
});

test('rsc response passes nested layouts to BunBridge rscHtmlStream', function () {
    $this->bridgeMock
        ->shouldReceive('rscHtmlStream')
        ->once()
        ->withArgs(function (string $component, array $props, array $layouts) {
            return $component === 'Dashboard'
                && $layouts === [
                    ['component' => 'AppLayout', 'props' => []],
                    ['component' => 'DashboardLayout', 'props' => ['sidebar' => true]],
                ];
        })
        ->andReturnUsing(function () {
            return (function () {
                yield ['clientChunks' => []];
                yield '<div>Dashboard</div>';
                yield ['rscPayload' => ''];
            })();
        });

    Route::get('/test-nested-layout', fn () => rsc('Dashboard', ['userId' => 1])
        ->layout('AppLayout')
        ->layout('DashboardLayout', ['sidebar' => true]));

    $this->get('/test-nested-layout')->assertStatus(200);
});

test('rsc response passes layouts to BunBridge rscStream on SPA navigation', function () {
    $this->bridgeMock
        ->shouldReceive('rscStream')
        ->once()
        ->withArgs(function (string $component, array $props, array $layouts) {
            return $component === 'Profile'
                && $layouts === [
                    ['component' => 'AppLayout', 'props' => ['title' => 'Profile']],
                ];
        })
        ->andReturnUsing(function () {
            return (function () {
                yield [];
                yield 'flight-payload-data';
            })();
        });

    Route::get('/test-layout-spa', fn () => rsc('Profile')
        ->layout('AppLayout', ['title' => 'Profile']));

    $this->get('/test-layout-spa', [Header::X_RSC => '1', Header::X_RSC_VERSION => ''])
        ->assertStatus(200)
        ->assertHeader('Content-Type', 'text/x-component; charset=utf-8');
});

test('rsc response passes empty layouts when none are specified', function () {
    $this->bridgeMock
        ->shouldReceive('rscHtmlStream')
        ->once()
        ->withArgs(function (string $component, array $props, array $layouts) {
            return $layouts === [];
        })
        ->andReturnUsing(function () {
            return (function () {
                yield ['clientChunks' => []];
                yield '<div>Page</div>';
                yield ['rscPayload' => ''];
            })();
        });

    Route::get('/test-no-layout', fn () => rsc('Page'));

    $this->get('/test-no-layout')->assertStatus(200);
});

test('rsc helper returns response that supports layout chaining', function () {
    $response = rsc('Dashboard', ['userId' => 1])
        ->layout('AppLayout')
        ->layout('DashboardLayout');

    expect($response)->toBeInstanceOf(\LaraBun\Rsc\RscResponse::class);

    $layouts = (new ReflectionProperty($response, 'layouts'))->getValue($response);

    expect($layouts)->toHaveCount(2)
        ->and($layouts[0]['component'])->toBe('AppLayout')
        ->and($layouts[1]['component'])->toBe('DashboardLayout');
});

test('duplicate layouts are deduplicated when passed to BunBridge', function () {
    $this->bridgeMock
        ->shouldReceive('rscHtmlStream')
        ->once()
        ->withArgs(function (string $component, array $props, array $layouts) {
            return count($layouts) === 1
                && $layouts[0]['component'] === 'AppLayout';
        })
        ->andReturnUsing(function () {
            return (function () {
                yield ['clientChunks' => []];
                yield '<div>Page</div>';
                yield ['rscPayload' => ''];
            })();
        });

    Route::get('/test-dedup-layout', fn () => rsc('Page')
        ->layout('AppLayout')
        ->layout('AppLayout'));

    $this->get('/test-dedup-layout')->assertStatus(200);
});
