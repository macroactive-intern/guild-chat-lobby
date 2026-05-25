<?php

namespace App\Http\Controllers;

use App\Events\ReactionAdded;
use App\Models\Message;
use App\Models\MessageReaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

class MessageReactionController extends Controller
{
    public function store(Request $request, Message $message): JsonResponse
    {
        Gate::authorize('view', $message);

        $validated = $request->validate([
            'emoji' => ['required', 'string', 'max:64'],
        ]);

        $reaction = MessageReaction::firstOrCreate([
            'message_id' => $message->id,
            'user_id' => $request->user()->id,
            'emoji' => $validated['emoji'],
        ]);

        if ($reaction->wasRecentlyCreated) {
            event(new ReactionAdded($reaction));
        }

        return response()->json([
            'message_id' => $reaction->message_id,
            'user_id' => $reaction->user_id,
            'emoji' => $reaction->emoji,
            'created_at' => $reaction->created_at,
        ], $reaction->wasRecentlyCreated ? Response::HTTP_CREATED : Response::HTTP_OK);
    }

    public function destroy(Request $request, Message $message): JsonResponse
    {
        Gate::authorize('view', $message);

        $validated = $request->validate([
            'emoji' => ['required', 'string', 'max:64'],
        ]);

        MessageReaction::query()
            ->where('message_id', $message->id)
            ->where('user_id', $request->user()->id)
            ->where('emoji', $validated['emoji'])
            ->delete();

        return response()->json(status: Response::HTTP_NO_CONTENT);
    }
}
