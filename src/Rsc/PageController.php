<?php

namespace LaraBun\Rsc;

use Illuminate\Http\Request;

class PageController
{
    public function handle(Request $request): RscResponse
    {
        $route = $request->route();
        $component = $route->defaults['_rsc_component'];
        $layouts = $route->defaults['_rsc_layouts'] ?? [];
        $configPaths = $route->defaults['_rsc_config_paths'] ?? [];

        $props = $route->parameters();
        $response = new RscResponse($component, $props);

        foreach ($layouts as $layout) {
            $response->layout($layout);
        }

        foreach ($configPaths as $path) {
            if (! file_exists($path)) {
                continue;
            }

            $config = require $path;

            if ($config instanceof PageRoute && $config->getViewData()) {
                $viewData = app()->call($config->getViewData(), $props);

                foreach ($viewData as $key => $value) {
                    $response->withViewData($key, $value);
                }
            }
        }

        return $response;
    }
}
