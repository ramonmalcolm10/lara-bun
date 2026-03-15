<?php

namespace LaraBun\Console;

use Illuminate\Console\Command;
use LaraBun\Rsc\PageRoute;
use LaraBun\Rsc\PageScanner;

class RscRouteManifestCommand extends Command
{
    protected $signature = 'rsc:route-manifest';

    protected $description = 'Output RSC route metadata as JSON for typed route generation';

    public function handle(): int
    {
        $appDir = config('bun.rsc.source_dir').'/app';

        if (! is_dir($appDir)) {
            $this->line(json_encode([], JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }

        $scanner = new PageScanner($appDir);
        $scanner->scan();

        $routes = [];

        foreach ($scanner->getPages() as $page) {
            $entry = [
                'urlPattern' => $page->urlPattern === '/' ? '/' : '/'.$page->urlPattern,
            ];

            if ($page->routeConfigPath !== null) {
                $config = require $page->routeConfigPath;

                if ($config instanceof PageRoute) {
                    $staticPaths = $config->getStaticPaths();

                    if (is_array($staticPaths)) {
                        $entry['staticPaths'] = $this->normalizeStaticPaths($staticPaths);
                    }

                    $where = $config->getWhereConstraints();

                    if ($where !== []) {
                        $entry['where'] = $this->extractWhereValues($where);
                    }
                }
            }

            $routes[] = $entry;
        }

        $this->line(json_encode($routes, JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }

    /**
     * Normalize staticPaths into a flat list of param value strings.
     *
     * staticPaths can be:
     *   ['installation', 'configuration']  → simple values for a single param
     *   [['slug' => 'a', 'id' => '1']]    → multi-param combinations
     *
     * @param  list<string|array<string, string>>  $paths
     * @return array<string, list<string>>
     */
    private function normalizeStaticPaths(array $paths): array
    {
        if ($paths === []) {
            return [];
        }

        // Check if first item is an associative array (multi-param)
        if (is_array($paths[0])) {
            $grouped = [];

            /** @var array<string, string> $combo */
            foreach ($paths as $combo) {
                foreach ($combo as $param => $value) {
                    $grouped[$param][] = (string) $value;
                }
            }

            // Deduplicate
            return array_map(fn (array $values) => array_values(array_unique($values)), $grouped);
        }

        // Simple list — infer param name from URL pattern (first dynamic segment)
        return ['_default' => array_map('strval', $paths)];
    }

    /**
     * Extract literal values from simple regex where constraints.
     *
     * Handles patterns like "foo|bar|baz" → ['foo', 'bar', 'baz']
     * Complex regex patterns are skipped.
     *
     * @param  array<string, string>  $constraints
     * @return array<string, list<string>>
     */
    private function extractWhereValues(array $constraints): array
    {
        $result = [];

        foreach ($constraints as $param => $pattern) {
            // Simple alternation: "foo|bar|baz" (no regex metacharacters)
            if (preg_match('/^[\w-]+(\|[\w-]+)*$/', $pattern)) {
                $result[$param] = explode('|', $pattern);
            }
        }

        return $result;
    }
}
