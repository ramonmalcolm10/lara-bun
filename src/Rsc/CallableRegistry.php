<?php

namespace LaraBun\Rsc;

use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Contracts\Container\Container;
use Illuminate\Pipeline\Pipeline;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use LaraBun\Rsc\Attributes\Authenticated;
use LaraBun\Rsc\Attributes\Can;
use LaraBun\Rsc\Attributes\Middleware;
use ReflectionClass;
use ReflectionMethod;
use RuntimeException;

class CallableRegistry
{
    /** @var array<string, array{class-string, string}|class-string|Closure> */
    private array $callables = [];

    /** @var array<string, object> */
    private array $instances = [];

    /** @var array<string, array{authenticated: Authenticated[], can: Can[], middleware: string[]}> */
    private array $attributeCache = [];

    public function __construct(private Container $container) {}

    /**
     * Register a callable by name.
     *
     * @param  array{class-string, string}|class-string|Closure  $callable
     */
    public function register(string $name, array|string|Closure $callable): void
    {
        $this->callables[$name] = $callable;
    }

    /**
     * Auto-discover public methods from classes in the given directory.
     *
     * Discovered names follow the pattern: ClassName.methodName
     * Invokable classes are also registered as: ClassName
     *
     * Explicit registrations take precedence over auto-discovered names.
     */
    public function discoverFrom(string $directory): void
    {
        if (! is_dir($directory)) {
            return;
        }

        $files = glob($directory.'/*.php');

        if ($files === false) {
            return;
        }

        foreach ($files as $file) {
            $className = $this->resolveClassName($file);

            if ($className === null || ! class_exists($className)) {
                continue;
            }

            $reflection = new ReflectionClass($className);

            if ($reflection->isAbstract() || $reflection->isInterface()) {
                continue;
            }

            $shortName = $reflection->getShortName();

            foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
                if ($method->isStatic() || $method->isConstructor()) {
                    continue;
                }

                $name = $method->getName() === '__invoke'
                    ? $shortName
                    : "{$shortName}.{$method->getName()}";

                if (! isset($this->callables[$name])) {
                    $this->callables[$name] = $method->getName() === '__invoke'
                        ? $className
                        : [$className, $method->getName()];
                }
            }
        }
    }

    /**
     * Execute a registered callable by name.
     *
     * @throws AuthenticationException
     * @throws AuthorizationException
     */
    public function execute(string $name, array $args): mixed
    {
        if (! isset($this->callables[$name])) {
            throw new RuntimeException("RSC callable not found: \"{$name}\"");
        }

        $callable = $this->callables[$name];

        if ($callable instanceof Closure) {
            return $callable(...$args);
        }

        if (is_string($callable)) {
            $this->authorize($callable, '__invoke');
            $instance = $this->resolveInstance($callable);

            return $instance(...$args);
        }

        if (is_array($callable)) {
            [$class, $method] = $callable;
            $this->authorize($class, $method);
            $instance = $this->resolveInstance($class);

            return $instance->{$method}(...$args);
        }

        throw new RuntimeException("Invalid callable configuration for \"{$name}\"");
    }

    /**
     * Run authorization checks declared via attributes on the class and method.
     *
     * @throws AuthenticationException
     * @throws AuthorizationException
     */
    private function authorize(string $class, string $method): void
    {
        $cacheKey = "{$class}::{$method}";

        if (! isset($this->attributeCache[$cacheKey])) {
            $this->attributeCache[$cacheKey] = $this->resolveAttributes($class, $method);
        }

        $attrs = $this->attributeCache[$cacheKey];

        foreach ($attrs['middleware'] as $middleware) {
            $this->runMiddleware($middleware);
        }

        foreach ($attrs['authenticated'] as $attr) {
            if (! Auth::guard($attr->guard)->check()) {
                throw new AuthenticationException('Unauthenticated.', [$attr->guard ?? config('auth.defaults.guard')]);
            }
        }

        foreach ($attrs['can'] as $attr) {
            $arguments = $attr->model !== null ? [$attr->model] : [];
            Gate::authorize($attr->ability, $arguments);
        }
    }

    /**
     * Reflect attributes from both class and method, merging them together.
     *
     * @return array{authenticated: Authenticated[], can: Can[], middleware: string[]}
     */
    private function resolveAttributes(string $class, string $method): array
    {
        $refClass = new ReflectionClass($class);

        $authenticated = array_map(
            fn (\ReflectionAttribute $a): Authenticated => $a->newInstance(),
            $refClass->getAttributes(Authenticated::class),
        );

        $can = array_map(
            fn (\ReflectionAttribute $a): Can => $a->newInstance(),
            $refClass->getAttributes(Can::class),
        );

        $middleware = array_merge(
            ...array_map(
                fn (\ReflectionAttribute $a): array => $a->newInstance()->middleware,
                $refClass->getAttributes(Middleware::class),
            ),
            ...[[]], // Ensure at least one array for spread
        );

        if ($method !== '__invoke' || $refClass->hasMethod($method)) {
            $refMethod = $refClass->getMethod($method);

            $authenticated = array_merge($authenticated, array_map(
                fn (\ReflectionAttribute $a): Authenticated => $a->newInstance(),
                $refMethod->getAttributes(Authenticated::class),
            ));

            $can = array_merge($can, array_map(
                fn (\ReflectionAttribute $a): Can => $a->newInstance(),
                $refMethod->getAttributes(Can::class),
            ));

            $middleware = array_merge($middleware, ...array_map(
                fn (\ReflectionAttribute $a): array => $a->newInstance()->middleware,
                $refMethod->getAttributes(Middleware::class),
            ), ...[[]], // Ensure at least one array for spread
            );
        }

        return [
            'authenticated' => $authenticated,
            'can' => $can,
            'middleware' => array_values(array_unique($middleware)),
        ];
    }

    /**
     * Resolve and run a single middleware through Laravel's Pipeline.
     */
    private function runMiddleware(string $middleware): void
    {
        $request = $this->container->make('request');

        (new Pipeline($this->container))
            ->send($request)
            ->through([$middleware])
            ->then(fn ($req) => $req);
    }

    public function hasCallables(): bool
    {
        return $this->callables !== [];
    }

    /**
     * @return array<string>
     */
    public function names(): array
    {
        return array_keys($this->callables);
    }

    private function resolveInstance(string $class): object
    {
        if (! isset($this->instances[$class])) {
            $this->instances[$class] = $this->container->make($class);
        }

        return $this->instances[$class];
    }

    /**
     * Resolve a fully-qualified class name from a PHP file path using PSR-4 conventions.
     */
    private function resolveClassName(string $filePath): ?string
    {
        $contents = file_get_contents($filePath);

        if ($contents === false) {
            return null;
        }

        if (preg_match('/namespace\s+([^;]+);/', $contents, $nsMatch)
            && preg_match('/class\s+(\w+)/', $contents, $classMatch)) {
            return $nsMatch[1].'\\'.$classMatch[1];
        }

        return null;
    }
}
