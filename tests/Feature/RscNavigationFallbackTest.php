<?php

use Illuminate\Support\Facades\Route;
use LaraBun\BunBridge;
use LaraBun\Rsc\Header;

beforeEach(function () {
    $this->bridgeMock = Mockery::mock(BunBridge::class);
    $this->app->instance(BunBridge::class, $this->bridgeMock);
});

test('non-RSC route does not return text/x-component content type on SPA navigation', function () {
    Route::get('/blade-page', fn () => response('Hello from Blade'));

    $response = $this->get('/blade-page', [
        Header::X_RSC => 'true',
        Header::X_RSC_VERSION => '',
    ]);

    $response->assertStatus(200);

    $contentType = $response->headers->get('Content-Type');
    expect($contentType)->not->toContain('text/x-component');
});

test('RSC route returns text/x-component on SPA navigation', function () {
    $this->bridgeMock
        ->shouldReceive('rscStream')
        ->once()
        ->andReturnUsing(function () {
            return (function () {
                yield [];
                yield 'flight-payload';
            })();
        });

    Route::get('/rsc-page', fn () => rsc('TestPage'));

    $response = $this->get('/rsc-page', [
        Header::X_RSC => 'true',
        Header::X_RSC_VERSION => '',
    ]);

    $response->assertStatus(200)
        ->assertHeader('Content-Type', 'text/x-component; charset=utf-8');
});

test('non-RSC JSON route does not return text/x-component on SPA navigation', function () {
    Route::get('/api-route', fn () => response()->json(['data' => 'value']));

    $response = $this->get('/api-route', [
        Header::X_RSC => 'true',
        Header::X_RSC_VERSION => '',
    ]);

    $response->assertStatus(200);

    $contentType = $response->headers->get('Content-Type');
    expect($contentType)->toContain('application/json')
        ->and($contentType)->not->toContain('text/x-component');
});

test('redirect to non-RSC route returns X-RSC-Redirect header without text/x-component', function () {
    $this->bridgeMock
        ->shouldReceive('rscAction')
        ->once()
        ->andReturnUsing(function () {
            return (function () {
                throw new \LaraBun\Rsc\RscRedirectException('/blade-page');
                yield; // @phpstan-ignore deadCode.unreachable
            })();
        });

    $response = $this->post('/_rsc/action', [], [
        \LaraBun\Rsc\Header::X_RSC_ACTION => 'myAction',
        \LaraBun\Rsc\Header::X_RSC_CONTENT_TYPE => 'text/plain',
        'Content-Type' => 'application/octet-stream',
    ]);

    $response->assertStatus(302)
        ->assertHeader('X-RSC-Redirect', '/blade-page');

    $contentType = $response->headers->get('Content-Type') ?? '';
    expect($contentType)->not->toContain('text/x-component');
});
