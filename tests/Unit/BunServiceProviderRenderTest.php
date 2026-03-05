<?php

use LaraBun\BunServiceProvider;

test('renderRscScripts returns empty HtmlString when no chunks', function () {
    $result = BunServiceProvider::renderRscScripts('some-payload', []);

    expect((string) $result)->toBe('');
});

test('renderRscScripts generates script tags for chunks', function () {
    $result = BunServiceProvider::renderRscScripts('flight-data', [
        '/build/rsc/chunk-a.js',
        '/build/rsc/chunk-b.js',
    ]);

    $html = (string) $result;

    expect($html)->toContain('window.__RSC_PAYLOAD__')
        ->and($html)->toContain('window.__RSC_MODULES__')
        ->and($html)->toContain('window.__webpack_require__')
        ->and($html)->toContain('window.__webpack_chunk_load__')
        ->and($html)->toContain('<script type="module" src="/build/rsc/chunk-a.js"></script>')
        ->and($html)->toContain('<script type="module" src="/build/rsc/chunk-b.js"></script>');
});

test('renderRscScripts encodes payload as JSON', function () {
    $payload = 'M1:{"id":"chunk-1","chunks":[],"name":"default"}';
    $result = BunServiceProvider::renderRscScripts($payload, ['/chunk.js']);

    $html = (string) $result;

    // The payload should be JSON-encoded (not raw)
    expect($html)->toContain('window.__RSC_PAYLOAD__');
    // JSON_HEX_TAG ensures </ is escaped to prevent script injection
    expect($html)->not->toContain('\u003C/script');
});

test('renderRscScripts escapes chunk URLs', function () {
    $result = BunServiceProvider::renderRscScripts('data', [
        '/build/rsc/chunk with spaces.js',
    ]);

    $html = (string) $result;

    expect($html)->toContain('chunk with spaces.js');
});

test('renderRscScripts generates single chunk correctly', function () {
    $result = BunServiceProvider::renderRscScripts('data', ['/build/rsc/main.js']);

    $html = (string) $result;

    expect($html)->toContain('<script type="module" src="/build/rsc/main.js"></script>');
});
