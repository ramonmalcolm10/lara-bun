<?php

use LaraBun\BunBridge;

it('parses megabytes', function () {
    expect(BunBridge::parseSize('25mb'))->toBe(25 * 1024 * 1024);
    expect(BunBridge::parseSize('100mb'))->toBe(100 * 1024 * 1024);
    expect(BunBridge::parseSize('1MB'))->toBe(1024 * 1024);
});

it('parses kilobytes', function () {
    expect(BunBridge::parseSize('512kb'))->toBe(512 * 1024);
    expect(BunBridge::parseSize('1KB'))->toBe(1024);
});

it('parses gigabytes', function () {
    expect(BunBridge::parseSize('1gb'))->toBe(1024 * 1024 * 1024);
});

it('parses plain bytes', function () {
    expect(BunBridge::parseSize('1048576b'))->toBe(1048576);
    expect(BunBridge::parseSize('1024'))->toBe(1024);
});

it('falls back to 1mb for invalid input', function () {
    expect(BunBridge::parseSize('invalid'))->toBe(1024 * 1024);
    expect(BunBridge::parseSize(''))->toBe(1024 * 1024);
});
