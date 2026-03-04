<?php

namespace LaraBun\Rsc;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RscMiddleware
{
    /**
     * Override this method to provide a custom version string.
     */
    public function version(Request $request): string
    {
        $buildDir = public_path('build/rsc');

        if (! is_dir($buildDir)) {
            return '';
        }

        $mtime = 0;

        /** @var \SplFileInfo $file */
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($buildDir)) as $file) {
            if ($file->isFile() && $file->getMTime() > $mtime) {
                $mtime = $file->getMTime();
            }
        }

        return md5((string) $mtime);
    }

    public function handle(Request $request, Closure $next): Response
    {
        if ($request->isMethod('GET') && $request->hasHeader(Header::X_RSC)) {
            $currentVersion = $this->version($request);
            $clientVersion = $request->header(Header::X_RSC_VERSION, '');

            if ($clientVersion !== '' && $clientVersion !== $currentVersion) {
                return new \Illuminate\Http\Response('', 409, [
                    Header::X_RSC_LOCATION => $request->fullUrl(),
                ]);
            }
        }

        $response = $next($request);

        $response->headers->set('Vary', Header::X_RSC, false);

        if ($this->isRedirect($response) && $this->shouldConvertRedirect($request)) {
            $response->setStatusCode(303);
        }

        return $response;
    }

    protected function isRedirect(Response $response): bool
    {
        return $response->getStatusCode() === 302;
    }

    protected function shouldConvertRedirect(Request $request): bool
    {
        return in_array($request->method(), ['PUT', 'PATCH', 'DELETE']);
    }
}
