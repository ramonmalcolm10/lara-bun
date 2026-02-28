<?php

namespace RamonMalcolm\LaraBun\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use RamonMalcolm\LaraBun\Rsc\Header;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

class ServeStaticRsc
{
    public function handle(Request $request, Closure $next): SymfonyResponse
    {
        $path = trim($request->getPathInfo(), '/') ?: 'index';
        $basePath = config('bun.rsc.static_path', storage_path('framework/rsc-static'));

        if ($request->hasHeader(Header::X_RSC)) {
            $flightFile = "{$basePath}/{$path}.flight";
            $metaFile = "{$basePath}/{$path}.meta.json";

            if (file_exists($flightFile) && file_exists($metaFile)) {
                $meta = json_decode(file_get_contents($metaFile), true);

                return new Response(file_get_contents($flightFile), 200, [
                    'Content-Type' => 'text/x-component',
                    Header::X_RSC_CHUNKS => json_encode($meta['clientChunks'] ?? [], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
                    Header::X_RSC_VERSION => $meta['version'] ?? '',
                    'X-Accel-Buffering' => 'no',
                ]);
            }
        } else {
            $htmlFile = "{$basePath}/{$path}.html";

            if (file_exists($htmlFile)) {
                return new Response(file_get_contents($htmlFile), 200, [
                    'Content-Type' => 'text/html; charset=UTF-8',
                ]);
            }
        }

        return $next($request);
    }
}
