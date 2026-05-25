<?php

use App\Http\Controllers\RoomController;
use App\Http\Controllers\RoomHeartbeatController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/guilds/{guild}/rooms', [RoomController::class, 'index']);
    Route::post('/guilds/{guild}/rooms', [RoomController::class, 'store']);
    Route::get('/rooms/{room}', [RoomController::class, 'show']);
    Route::post('/rooms/{room}/heartbeat', RoomHeartbeatController::class);
});
