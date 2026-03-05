<?php

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use LaraBun\BunBridge;
use LaraBun\Rsc\Header;
use LaraBun\Rsc\RscRedirectException;

beforeEach(function () {
    $this->bridgeMock = Mockery::mock(BunBridge::class);
    $this->app->instance(BunBridge::class, $this->bridgeMock);
});

function defineLoginRoute(): void
{
    Route::get('/login', fn () => 'login page')->name('login');
    app('router')->getRoutes()->refreshNameLookups();
}

function postAction(mixed $test, string $actionId = 'myAction', string $body = ''): \Illuminate\Testing\TestResponse
{
    return $test->post('/_rsc/action', [], [
        Header::X_RSC_ACTION => $actionId,
        Header::X_RSC_CONTENT_TYPE => 'text/plain',
        'Content-Type' => 'application/octet-stream',
    ]);
}

test('returns 401 with X-RSC-Redirect to login on AuthenticationException', function () {
    defineLoginRoute();

    $this->bridgeMock
        ->shouldReceive('rscAction')
        ->once()
        ->andReturnUsing(function () {
            return (function () {
                throw new AuthenticationException;
                yield; // @phpstan-ignore deadCode.unreachable
            })();
        });

    $response = postAction($this);

    $response->assertStatus(401)
        ->assertHeader('X-RSC-Redirect', route('login'));
});

test('returns 403 JSON with message on AuthorizationException', function () {
    $this->bridgeMock
        ->shouldReceive('rscAction')
        ->once()
        ->andReturnUsing(function () {
            return (function () {
                throw new AuthorizationException('You cannot do this.');
                yield; // @phpstan-ignore deadCode.unreachable
            })();
        });

    $response = postAction($this);

    $response->assertStatus(403)
        ->assertJson([
            'message' => 'You cannot do this.',
        ]);
});

test('returns 403 with default message when AuthorizationException has no message', function () {
    $this->bridgeMock
        ->shouldReceive('rscAction')
        ->once()
        ->andReturnUsing(function () {
            return (function () {
                throw new AuthorizationException;
                yield; // @phpstan-ignore deadCode.unreachable
            })();
        });

    $response = postAction($this);

    $response->assertStatus(403)
        ->assertJson([
            'message' => 'This action is unauthorized.',
        ]);
});

test('returns redirect with X-RSC-Redirect on RscRedirectException', function () {
    $this->bridgeMock
        ->shouldReceive('rscAction')
        ->once()
        ->andReturnUsing(function () {
            return (function () {
                throw new RscRedirectException('/posts/123');
                yield; // @phpstan-ignore deadCode.unreachable
            })();
        });

    $response = postAction($this);

    $response->assertStatus(302)
        ->assertHeader('X-RSC-Redirect', '/posts/123');
});

test('respects custom status code on RscRedirectException', function () {
    $this->bridgeMock
        ->shouldReceive('rscAction')
        ->once()
        ->andReturnUsing(function () {
            return (function () {
                throw new RscRedirectException('/dashboard', 301);
                yield; // @phpstan-ignore deadCode.unreachable
            })();
        });

    $response = postAction($this);

    $response->assertStatus(301)
        ->assertHeader('X-RSC-Redirect', '/dashboard');
});

test('returns 422 JSON with validation errors on ValidationException', function () {
    $this->bridgeMock
        ->shouldReceive('rscAction')
        ->once()
        ->andReturnUsing(function () {
            return (function () {
                throw ValidationException::withMessages([
                    'title' => ['The title field is required.'],
                ]);
                yield; // @phpstan-ignore deadCode.unreachable
            })();
        });

    $response = postAction($this);

    $response->assertStatus(422)
        ->assertJson([
            'message' => 'The title field is required.',
            'errors' => [
                'title' => ['The title field is required.'],
            ],
        ]);
});

test('returns 400 when X-RSC-Action header is missing', function () {
    $response = $this->post('/_rsc/action');

    $response->assertStatus(400);
});

test('returns 419 when CSRF token is invalid', function () {
    $this->app['env'] = 'production';

    $response = $this->post('/_rsc/action', [], [
        Header::X_RSC_ACTION => 'myAction',
        Header::X_RSC_CONTENT_TYPE => 'text/plain',
        'Content-Type' => 'application/octet-stream',
        'X-CSRF-TOKEN' => 'invalid-token',
    ]);

    $response->assertStatus(419);
});

test('streams successful action response', function () {
    $this->bridgeMock
        ->shouldReceive('rscAction')
        ->once()
        ->andReturnUsing(function () {
            return (function () {
                yield 'chunk-1';
                yield 'chunk-2';
            })();
        });

    $response = postAction($this);

    $response->assertStatus(200)
        ->assertHeader('Content-Type', 'text/x-component; charset=utf-8');
});
