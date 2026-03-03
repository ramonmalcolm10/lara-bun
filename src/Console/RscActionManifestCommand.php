<?php

namespace RamonMalcolm\LaraBun\Console;

use Illuminate\Console\Command;
use ReflectionClass;
use ReflectionMethod;

class RscActionManifestCommand extends Command
{
    protected $signature = 'rsc:action-manifest';

    protected $description = 'Output RSC action mappings as JSON for the build system';

    public function handle(): int
    {
        $actions = $this->discoverActions();

        $this->line(json_encode($actions, JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }

    /**
     * Auto-discover actions from classes in the configured actions_dir.
     *
     * @return array<string, string>
     */
    private function discoverActions(): array
    {
        $directory = config('bun.rsc.actions_dir', app_path('RSC/Actions'));

        if ($directory === null || ! is_dir($directory)) {
            return [];
        }

        $files = glob($directory.'/*.php');

        if ($files === false) {
            return [];
        }

        $actions = [];

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
            $baseName = preg_replace('/Callable$/', '', $shortName);

            foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
                if ($method->isStatic() || $method->isConstructor()) {
                    continue;
                }

                $methodName = $method->getName();

                if ($methodName === '__invoke') {
                    $jsName = lcfirst($baseName);
                    $phpCallable = $shortName;
                } else {
                    $jsName = lcfirst($baseName).ucfirst($methodName);
                    $phpCallable = "{$shortName}.{$methodName}";
                }

                $actions[$jsName] = $phpCallable;
            }
        }

        return $actions;
    }

    /**
     * Resolve a fully-qualified class name from a PHP file path.
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
