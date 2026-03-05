<?php

use LaraBun\BunBridge;
use LaraBun\Rsc\Header;

beforeEach(function () {
    $this->bridgeMock = Mockery::mock(BunBridge::class);
    $this->app->instance(BunBridge::class, $this->bridgeMock);
});

test('error response renders with correct HTTP status code on initial load', function (int $statusCode) {
    $this->bridgeMock
        ->shouldReceive('rscHtmlStream')
        ->once()
        ->andReturnUsing(function () {
            return (function () {
                yield ['clientChunks' => []];
                yield '<div>Error</div>';
                yield ['rscPayload' => ''];
            })();
        });

    Route::get('/test-error', fn () => rsc('Error', ['status' => $statusCode])
        ->status($statusCode));

    $this->get('/test-error')->assertStatus($statusCode);
})->with([404, 403, 419, 500]);

test('error response renders with correct HTTP status code on SPA navigation', function (int $statusCode) {
    $this->bridgeMock
        ->shouldReceive('rscStream')
        ->once()
        ->andReturnUsing(function () {
            return (function () {
                yield [];
                yield 'flight-payload-data';
            })();
        });

    Route::get('/test-error-spa', fn () => rsc('Error', ['status' => $statusCode])
        ->status($statusCode));

    $this->get('/test-error-spa', [Header::X_RSC => '1', Header::X_RSC_VERSION => ''])
        ->assertStatus($statusCode);
})->with([404, 403, 419, 500]);
