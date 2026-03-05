<?php

use LaraBun\Rsc\CallableRegistry;

class InvokableCallable
{
    public function __invoke(string $name): string
    {
        return "hello {$name}";
    }
}

class MultiMethodCallable
{
    public function add(int $a, int $b): int
    {
        return $a + $b;
    }

    public function multiply(int $a, int $b): int
    {
        return $a * $b;
    }

    public static function staticMethod(): void {}
}

test('register and execute a closure', function () {
    $registry = new CallableRegistry(app());
    $registry->register('greet', fn (string $name) => "hi {$name}");

    expect($registry->execute('greet', ['World']))->toBe('hi World');
});

test('register and execute a closure with no args', function () {
    $registry = new CallableRegistry(app());
    $registry->register('ping', fn () => 'pong');

    expect($registry->execute('ping', []))->toBe('pong');
});

test('register and execute an invokable class', function () {
    $registry = new CallableRegistry(app());
    $registry->register('InvokableCallable', InvokableCallable::class);

    expect($registry->execute('InvokableCallable', ['Alice']))->toBe('hello Alice');
});

test('register and execute a class method array', function () {
    $registry = new CallableRegistry(app());
    $registry->register('MultiMethodCallable.add', [MultiMethodCallable::class, 'add']);

    expect($registry->execute('MultiMethodCallable.add', [3, 4]))->toBe(7);
});

test('throws RuntimeException for unknown callable', function () {
    $registry = new CallableRegistry(app());

    $registry->execute('nonexistent', []);
})->throws(RuntimeException::class, 'RSC callable not found: "nonexistent"');

test('hasCallables returns false when empty', function () {
    $registry = new CallableRegistry(app());

    expect($registry->hasCallables())->toBeFalse();
});

test('hasCallables returns true after register', function () {
    $registry = new CallableRegistry(app());
    $registry->register('test', fn () => null);

    expect($registry->hasCallables())->toBeTrue();
});

test('names returns registered callable names', function () {
    $registry = new CallableRegistry(app());
    $registry->register('alpha', fn () => null);
    $registry->register('beta', fn () => null);

    expect($registry->names())->toBe(['alpha', 'beta']);
});

test('names returns empty array when no callables', function () {
    $registry = new CallableRegistry(app());

    expect($registry->names())->toBe([]);
});

test('discoverFrom skips non-existent directory', function () {
    $registry = new CallableRegistry(app());
    $registry->discoverFrom('/nonexistent/path');

    expect($registry->hasCallables())->toBeFalse();
});

test('explicit registration takes precedence over auto-discovered', function () {
    $registry = new CallableRegistry(app());
    $registry->register('InvokableCallable', fn () => 'explicit');

    // discoverFrom would register InvokableCallable as the class, but explicit wins
    $tempDir = sys_get_temp_dir().'/registry-test-'.uniqid();
    mkdir($tempDir, 0755, true);

    file_put_contents($tempDir.'/InvokableCallable.php', <<<'PHP'
<?php
class InvokableCallable
{
    public function __invoke(): string
    {
        return 'discovered';
    }
}
PHP);

    $registry->discoverFrom($tempDir);

    expect($registry->execute('InvokableCallable', []))->toBe('explicit');

    unlink($tempDir.'/InvokableCallable.php');
    rmdir($tempDir);
});

test('instances are cached between executions', function () {
    $registry = new CallableRegistry(app());
    $registry->register('MultiMethodCallable.add', [MultiMethodCallable::class, 'add']);
    $registry->register('MultiMethodCallable.multiply', [MultiMethodCallable::class, 'multiply']);

    expect($registry->execute('MultiMethodCallable.add', [2, 3]))->toBe(5);
    expect($registry->execute('MultiMethodCallable.multiply', [2, 3]))->toBe(6);
});
