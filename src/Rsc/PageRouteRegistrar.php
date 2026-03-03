<?php

namespace RamonMalcolm\LaraBun\Rsc;

use Illuminate\Routing\Router;
use RamonMalcolm\LaraBun\Http\Middleware\ServeStaticRsc;

class PageRouteRegistrar
{
    public function __construct(
        protected Router $router,
    ) {}

    /**
     * @param  list<PageDefinition>  $pages
     */
    public function register(array $pages): void
    {
        foreach ($pages as $page) {
            $this->registerPage($page);
        }

        $this->router->getRoutes()->refreshNameLookups();
    }

    protected function registerPage(PageDefinition $page): void
    {
        $url = $page->urlPattern === '' ? '/' : $page->urlPattern;

        $route = $this->router->get($url, [PageController::class, 'handle']);

        $route->defaults('_rsc_component', $page->componentName);
        $route->defaults('_rsc_layouts', $page->layouts);

        // Collect all config paths (directory-level + page-level) for viewData resolution
        $configPaths = $page->directoryConfigPaths;

        if ($page->routeConfigPath !== null) {
            $configPaths[] = $page->routeConfigPath;
        }

        $route->defaults('_rsc_config_paths', $configPaths);

        // Start with web middleware
        $middleware = ['web'];

        // Load directory-level route.php configs for middleware
        foreach ($page->directoryConfigPaths as $configPath) {
            $config = $this->loadConfig($configPath);

            if ($config instanceof PageRoute) {
                $middleware = array_merge($middleware, $config->getMiddleware());

                if ($config->getAbility()) {
                    $canMiddleware = 'can:'.$config->getAbility();

                    if ($config->getAbilityModel()) {
                        $canMiddleware .= ','.$config->getAbilityModel();
                    }

                    $middleware[] = $canMiddleware;
                }

                foreach ($config->getWhereConstraints() as $param => $pattern) {
                    $route->where($param, $pattern);
                }

                if ($config->getName()) {
                    $route->name($config->getName());
                }
            }
        }

        // Load page-level route.php config
        $pageConfig = null;

        if ($page->routeConfigPath !== null) {
            $pageConfig = $this->loadConfig($page->routeConfigPath);

            if ($pageConfig instanceof PageRoute) {
                $middleware = array_merge($middleware, $pageConfig->getMiddleware());

                if ($pageConfig->getAbility()) {
                    $canMiddleware = 'can:'.$pageConfig->getAbility();

                    if ($pageConfig->getAbilityModel()) {
                        $canMiddleware .= ','.$pageConfig->getAbilityModel();
                    }

                    $middleware[] = $canMiddleware;
                }

                foreach ($pageConfig->getWhereConstraints() as $param => $pattern) {
                    $route->where($param, $pattern);
                }

                if ($pageConfig->getName()) {
                    $route->name($pageConfig->getName());
                }
            }
        }

        // Catch-all segments: [...path] → where('path', '.*')
        if (preg_match_all('/\{(\w+)\}/', $page->urlPattern, $matches)) {
            foreach ($matches[1] as $param) {
                if ($this->isCatchAll($page, $param)) {
                    $route->where($param, '.*');
                }
            }
        }

        // Auto-static: non-dynamic pages get ServeStaticRsc middleware
        // Unless route.php explicitly calls ->dynamic()
        $forcedDynamic = $pageConfig instanceof PageRoute && $pageConfig->isDynamic();

        if (! $page->isDynamic && ! $forcedDynamic) {
            $middleware[] = ServeStaticRsc::class;
        }

        // Dynamic pages with staticPaths also get static middleware
        if ($page->isDynamic && $pageConfig instanceof PageRoute) {
            $staticPaths = $pageConfig->getStaticPaths();

            if ($staticPaths !== null) {
                $middleware[] = ServeStaticRsc::class;
                $resolved = $staticPaths instanceof \Closure ? app()->call($staticPaths) : $staticPaths;
                $route->defaults('_static_paths', $resolved);
            }
        }

        $route->middleware(array_unique($middleware));

        // Domain routing via route.php ->domain()
        $domain = $pageConfig instanceof PageRoute ? $pageConfig->getDomain() : null;

        if ($domain !== null) {
            $route->domain($domain);
        }

        // Auto-name: app/docs/[slug]/page → rsc.docs.slug
        if (! $route->getName()) {
            $route->name($this->generateRouteName($page));
        }
    }

    protected function loadConfig(string $path): mixed
    {
        return require $path;
    }

    /**
     * Check if a parameter is a catch-all by examining the component name.
     */
    protected function isCatchAll(PageDefinition $page, string $param): bool
    {
        return str_contains($page->componentName, '[...'.$param.']');
    }

    /**
     * Generate a route name from the component name.
     * app/page → rsc.index
     * app/about/page → rsc.about
     * app/docs/[slug]/page → rsc.docs.slug
     */
    protected function generateRouteName(PageDefinition $page): string
    {
        $name = $page->componentName;

        // Remove app/ prefix and /page suffix
        $name = preg_replace('#^app/#', '', $name);
        $name = preg_replace('#/page$#', '', $name);

        if ($name === '' || $name === 'page') {
            return 'rsc.index';
        }

        // Strip route groups: (marketing) → nothing
        $name = preg_replace('#\([^)]+\)/?#', '', $name);

        // Convert [param] and [...param] to just param
        $name = preg_replace('/\[\.\.\.(\w+)\]/', '$1', $name);
        $name = preg_replace('/\[(\w+)\]/', '$1', $name);

        // Convert slashes to dots
        $name = str_replace('/', '.', trim($name, '/'));

        return 'rsc.'.$name;
    }
}
