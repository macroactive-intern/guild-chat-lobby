<?php

namespace App\Http\Controllers;

use App\Models\Room;
use App\Services\PresenceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class RoomHeartbeatController extends Controller
{
    public function __invoke(Request $request, Room $room, PresenceService $presence): JsonResponse
    {
        Gate::authorize('view', $room);

        $presence->markOnline($room, $request->user());

        return response()->json([
            'message' => 'Presence heartbeat recorded.',
        ]);
    }

    public function destroy(Request $request, Room $room, PresenceService $presence): JsonResponse
    {
        Gate::authorize('view', $room);

        $presence->markOffline($room, $request->user());

        return response()->json([
            'message' => 'Presence heartbeat cleared.',
        ]);
    }
}
