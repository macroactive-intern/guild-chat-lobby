<?php

use App\Http\Controllers\MessageController;
use App\Http\Controllers\RoomController;
use App\Http\Controllers\RoomHeartbeatController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

Route::middleware('auth:sanctum')->group(function () {
    Route::prefix('guilds/{guild}')->group(function () {
        Route::get('/rooms', [RoomController::class, 'index'])
            ->middleware('can:view,guild');
        Route::post('/rooms', [RoomController::class, 'store'])
            ->middleware('can:createRoom,guild');
    });

    Route::prefix('rooms/{room}')->group(function () {
        Route::get('/', [RoomController::class, 'show'])
            ->middleware('can:view,room');
        Route::get('/messages', [MessageController::class, 'index'])
            ->middleware('can:view,room');
        Route::post('/messages', [MessageController::class, 'store'])
            ->middleware('can:sendMessage,room');
        Route::post('/read', [MessageController::class, 'read'])
            ->middleware('can:view,room');
        Route::post('/typing', [MessageController::class, 'typing'])
            ->middleware('can:view,room');
        Route::post('/heartbeat', RoomHeartbeatController::class)
            ->middleware('can:view,room');
    });

    Route::prefix('messages/{message}')->group(function () {
        Route::patch('/', [MessageController::class, 'update'])
            ->middleware('can:update,message');
        Route::delete('/', [MessageController::class, 'destroy'])
            ->middleware('can:delete,message');
    });
});
