<?php

namespace RamonMalcolm\LaraBun\Rsc;

use Closure;
use Illuminate\Contracts\Container\Container;
use ReflectionClass;
use ReflectionMethod;
use RuntimeException;

class CallableRegistry
{
    /** @var array<string, array{class-string, string}|class-string|Closure> */
    private array $callables = [];

    /** @var array<string, object> */
    private array $instances = [];

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
            $instance = $this->resolveInstance($callable);

            return $instance(...$args);
        }

        if (is_array($callable)) {
            [$class, $method] = $callable;
            $instance = $this->resolveInstance($class);

            return $instance->{$method}(...$args);
        }

        throw new RuntimeException("Invalid callable configuration for \"{$name}\"");
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
