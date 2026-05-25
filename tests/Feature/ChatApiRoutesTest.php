<?php

use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;

it('registers the chat API routes with sanctum and policy middleware', function (string $method, string $uri, string $policyMiddleware) {
    $route = collect(Route::getRoutes())
        ->first(fn (RoutingRoute $route): bool => $route->uri() === $uri
            && in_array($method, $route->methods(), true));

    expect($route)->not->toBeNull();

    $middleware = $route->gatherMiddleware();

    expect($middleware)
        ->toContain('auth:sanctum')
        ->toContain($policyMiddleware);
})->with([
    ['GET', 'api/guilds/{guild}/rooms', 'can:view,guild'],
    ['POST', 'api/guilds/{guild}/rooms', 'can:createRoom,guild'],
    ['GET', 'api/rooms/{room}', 'can:view,room'],
    ['POST', 'api/rooms/{room}/messages', 'can:sendMessage,room'],
    ['PATCH', 'api/messages/{message}', 'can:update,message'],
    ['DELETE', 'api/messages/{message}', 'can:delete,message'],
    ['GET', 'api/rooms/{room}/messages', 'can:view,room'],
    ['POST', 'api/rooms/{room}/read', 'can:view,room'],
    ['POST', 'api/rooms/{room}/typing', 'can:view,room'],
    ['POST', 'api/rooms/{room}/heartbeat', 'can:view,room'],
    ['POST', 'api/messages/{message}/reactions', 'can:view,message'],
    ['DELETE', 'api/messages/{message}/reactions', 'can:view,message'],
]);
