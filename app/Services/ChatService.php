<?php

namespace App\Services;

use App\Events\MessageSent;
use App\Exceptions\TooManyMessagesException;
use App\Models\Message;
use App\Models\Room;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class ChatService
{
    /**
     * @throws TooManyMessagesException
     * @throws ValidationException
     */
    public function send(User $user, Room $room, array $data): Message
    {
        $validated = $this->validateMessageData($room, $data);
        $this->hitRateLimit($user->id, $room->id);

        $message = DB::transaction(fn () => Message::create([
            'room_id' => $room->id,
            'user_id' => $user->id,
            'body' => $validated['body'],
            'parent_id' => $validated['parent_id'] ?? null,
        ]));

        $message->load(['room', 'user', 'replies.user']);

        event(new MessageSent($message));

        return $message;
    }

    /**
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function edit(Message $message, User $user, array $data): Message
    {
        if ($message->user_id !== $user->id) {
            throw new AuthorizationException('Only the message author may edit this message.');
        }

        if ($message->created_at->copy()->addMinutes(10)->isPast()) {
            throw new AuthorizationException('Messages can only be edited for 10 minutes after creation.');
        }

        $validated = Validator::make($data, [
            'body' => ['required', 'string', 'max:5000'],
        ])->validate();

        $message->forceFill([
            'body' => $validated['body'],
            'edited_at' => now(),
        ])->save();

        return $message->load(['room', 'user', 'replies.user']);
    }

    /**
     * @throws AuthorizationException
     */
    public function delete(Message $message, User $user): Message
    {
        $guildId = $message->room?->guild_id
            ?? $message->room()->value('guild_id');

        if ($message->user_id !== $user->id && ! $user->isLeaderOfGuild($guildId)) {
            throw new AuthorizationException('Only the message author or a guild leader may delete this message.');
        }

        $message->delete();

        return $message->load(['room', 'user', 'replies.user']);
    }

    /**
     * @throws ValidationException
     */
    private function validateMessageData(Room $room, array $data): array
    {
        $validator = Validator::make($data, [
            'body' => ['required', 'string', 'max:5000'],
            'parent_id' => ['nullable', 'integer', 'exists:messages,id'],
        ]);

        $validator->after(function ($validator) use ($room, $data): void {
            if ($room->is_archived) {
                $validator->errors()->add('room_id', 'Archived rooms cannot receive new messages.');
            }

            if (! array_key_exists('parent_id', $data) || $data['parent_id'] === null) {
                return;
            }

            $parentBelongsToRoom = Message::query()
                ->whereKey($data['parent_id'])
                ->where('room_id', $room->id)
                ->exists();

            if (! $parentBelongsToRoom) {
                $validator->errors()->add('parent_id', 'The parent message must belong to the same room.');
            }
        });

        return $validator->validate();
    }

    /**
     * @throws TooManyMessagesException
     */
    private function hitRateLimit(int $userId, int $roomId): void
    {
        $lock = Cache::lock("chat-rate.{$userId}.{$roomId}", 1);

        if (! $lock->get()) {
            throw new TooManyMessagesException();
        }
    }
}
