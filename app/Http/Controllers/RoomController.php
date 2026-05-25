<?php

namespace App\Http\Controllers;

use App\Events\RoomStatusUpdated;
use App\Http\Resources\RoomResource;
use App\Models\Guild;
use App\Models\Message;
use App\Models\Room;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class RoomController extends Controller
{
    public function index(Request $request, Guild $guild): AnonymousResourceCollection
    {
        Gate::authorize('view', $guild);

        $perPage = max(1, min($request->integer('per_page', 15), 100));

        $rooms = $guild->rooms()
            ->latest('id')
            ->paginate($perPage);
        $this->loadLastMessagesForRooms($rooms->getCollection());

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

    public function archive(Room $room): JsonResponse
    {
        Gate::authorize('archive', $room);

        $room->forceFill(['is_archived' => true])->save();

        event(new RoomStatusUpdated($room));

        return (new RoomResource($room))
            ->response();
    }

    public function unarchive(Room $room): JsonResponse
    {
        Gate::authorize('archive', $room);

        $room->forceFill(['is_archived' => false])->save();

        event(new RoomStatusUpdated($room));

        return (new RoomResource($room))
            ->response();
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

    /**
     * @param  EloquentCollection<int, Room>  $rooms
     */
    private function loadLastMessagesForRooms(EloquentCollection $rooms): void
    {
        if ($rooms->isEmpty()) {
            return;
        }

        $rankedMessages = Message::query()
            ->withTrashed()
            ->select('id')
            ->selectRaw('ROW_NUMBER() OVER (PARTITION BY room_id ORDER BY id DESC) as room_message_rank')
            ->whereIn('room_id', $rooms->modelKeys());

        $messageIds = DB::query()
            ->fromSub($rankedMessages, 'ranked_messages')
            ->where('room_message_rank', '<=', 50)
            ->pluck('id');

        $messages = Message::query()
            ->withTrashed()
            ->with(['user', 'replies.user'])
            ->whereIn('id', $messageIds)
            ->latest('id')
            ->get()
            ->groupBy('room_id');

        $rooms->each(function (Room $room) use ($messages): void {
            $room->setRelation(
                'messages',
                $messages->get($room->id, new EloquentCollection())->values(),
            );
        });
    }
}
