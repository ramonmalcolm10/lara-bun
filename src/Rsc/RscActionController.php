<?php

namespace RamonMalcolm\LaraBun\Rsc;

use Illuminate\Http\Request;
use RamonMalcolm\LaraBun\BunBridge;
use Symfony\Component\HttpFoundation\StreamedResponse;

class RscActionController
{
    public function __invoke(Request $request): StreamedResponse
    {
        $actionId = $request->header(Header::X_RSC_ACTION);

        if (! $actionId) {
            abort(400, 'Missing X-RSC-Action header');
        }

        $body = $request->getContent();
        $contentType = $request->header(Header::X_RSC_CONTENT_TYPE, 'text/plain');
        $bridge = app(BunBridge::class);
        $generator = $bridge->rscAction($actionId, $body, $contentType);

        return new StreamedResponse(function () use ($generator): void {
            while (ob_get_level() > 0) {
                ob_end_flush();
            }

            while ($generator->valid()) {
                echo $generator->current();
                flush();
                $generator->next();
            }
        }, 200, [
            'Content-Type' => 'text/x-component',
            'X-Accel-Buffering' => 'no',
        ]);
    }
}
