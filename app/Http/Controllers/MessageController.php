<?php

namespace App\Http\Controllers;

use App\Events\UserTyping;
use App\Exceptions\TooManyMessagesException;
use App\Http\Resources\MessageResource;
use App\Models\Message;
use App\Models\MessageRead;
use App\Models\Room;
use App\Services\ChatService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;

class MessageController extends Controller
{
    public function index(Request $request, Room $room): AnonymousResourceCollection
    {
        Gate::authorize('view', $room);

        $search = $request->string('search')->trim()->toString();

        $messages = $room->messages()
            ->withTrashed()
            ->with(['user', 'replies.user'])
            ->where('created_at', '>=', now()->subDays(30))
            ->when($search !== '', fn ($query) => $query
                ->whereNull('deleted_at')
                ->where('body', 'like', "%{$search}%"))
            ->latest('id')
            ->paginate((int) config('chat.messages.index_page_size'));

        return MessageResource::collection($messages);
    }

    public function store(Request $request, Room $room, ChatService $chat): JsonResponse
    {
        Gate::authorize('sendMessage', $room);

        try {
            $message = $chat->send($request->user(), $room, $request->all());
        } catch (TooManyMessagesException $exception) {
            return $exception->render();
        }

        return (new MessageResource($message))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function update(Request $request, Message $message, ChatService $chat): MessageResource
    {
        Gate::authorize('update', $message);

        return new MessageResource($chat->edit($message, $request->user(), $request->all()));
    }

    public function destroy(Request $request, Message $message, ChatService $chat): MessageResource
    {
        Gate::authorize('delete', $message);

        return new MessageResource($chat->delete($message, $request->user()));
    }

    public function read(Request $request, Room $room): JsonResponse
    {
        Gate::authorize('view', $room);

        $latestMessageId = $room->messages()
            ->withTrashed()
            ->latest('id')
            ->value('id');
        $readAt = now();

        if ($latestMessageId) {
            MessageRead::upsert(
                [[
                    'message_id' => $latestMessageId,
                    'user_id' => $request->user()->id,
                    'read_at' => $readAt,
                    'created_at' => $readAt,
                    'updated_at' => $readAt,
                ]],
                ['message_id', 'user_id'],
                ['read_at', 'updated_at'],
            );
        }

        return response()->json([
            'latest_read_message_id' => $latestMessageId,
            'read_at' => $readAt,
        ]);
    }

    public function typing(Request $request, Room $room): JsonResponse
    {
        Gate::authorize('view', $room);

        $lock = Cache::lock("typing-rate.{$request->user()->id}.{$room->id}", 1);

        if (! $lock->get()) {
            return response()->json([
                'message' => 'You are sending typing indicators too quickly.',
                'error' => 'too_many_typing_events',
            ], Response::HTTP_TOO_MANY_REQUESTS);
        }

        broadcast(new UserTyping($request->user(), $room))->toOthers();

        return response()->json(status: Response::HTTP_ACCEPTED);
    }
}
