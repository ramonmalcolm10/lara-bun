<?php

namespace LaraBun\Rsc;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;
use LaraBun\BunBridge;
use Symfony\Component\HttpFoundation\StreamedResponse;

class RscActionController
{
    public function __invoke(Request $request): StreamedResponse|JsonResponse|Response
    {
        $actionId = $request->header(Header::X_RSC_ACTION);

        if (! $actionId) {
            abort(400, 'Missing X-RSC-Action header');
        }

        $body = $request->getContent();
        $contentType = $request->header(Header::X_RSC_CONTENT_TYPE, 'text/plain');
        $bridge = app(BunBridge::class);
        $generator = $bridge->rscAction($actionId, $body, $contentType);

        try {
            $first = $generator->current();
        } catch (AuthenticationException) {
            return response('', 401)
                ->header('X-RSC-Redirect', route('login'));
        } catch (AuthorizationException $e) {
            return response()->json([
                'message' => $e->getMessage() ?: 'This action is unauthorized.',
            ], 403);
        } catch (RscRedirectException $e) {
            return response('', $e->getStatus())
                ->header('X-RSC-Redirect', $e->getLocation());
        } catch (ValidationException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'errors' => $e->errors(),
            ], 422);
        }

        return new StreamedResponse(function () use ($generator, $first): void {
            while (ob_get_level() > 0) {
                ob_end_flush();
            }

            if ($first !== null) {
                echo $first;
                flush();
            }

            $generator->next();

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
