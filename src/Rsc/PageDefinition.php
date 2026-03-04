<?php

namespace LaraBun\Rsc;

class PageDefinition
{
    /**
     * @param  list<string>  $layouts
     * @param  list<string>  $directoryConfigPaths
     */
    public function __construct(
        public string $componentName,
        public string $urlPattern,
        public array $layouts,
        public bool $isDynamic,
        public ?string $routeConfigPath,
        public array $directoryConfigPaths,
    ) {}
}
