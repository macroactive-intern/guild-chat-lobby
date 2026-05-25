<?php

namespace App\Http\Controllers;

use App\Http\Resources\RoomResource;
use App\Models\Guild;
use App\Models\Room;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

class RoomController extends Controller
{
    public function index(Request $request, Guild $guild): AnonymousResourceCollection
    {
        Gate::authorize('view', $guild);

        $perPage = max(1, min($request->integer('per_page', 15), 100));

        $rooms = $guild->rooms()
            ->with($this->lastMessages())
            ->latest('id')
            ->paginate($perPage);

        return RoomResource::collection($rooms);
    }

    public function store(Request $request, Guild $guild): JsonResponse
    {
        Gate::authorize('createRoom', $guild);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'is_archived' => ['sometimes', 'boolean'],
        ]);
        $validated['is_archived'] ??= false;

        $room = $guild->rooms()->create($validated);

        return (new RoomResource($room))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function show(Room $room): RoomResource
    {
        Gate::authorize('view', $room);

        $room->load($this->lastMessages());

        return new RoomResource($room);
    }

    private function lastMessages(): array
    {
        return [
            'messages' => fn ($query) => $query
                ->withTrashed()
                ->with(['user', 'replies.user'])
                ->latest('id')
                ->limit(50),
        ];
    }
}
