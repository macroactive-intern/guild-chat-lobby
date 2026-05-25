<?php

namespace App\Services;

use App\Events\MessageDeleted;
use App\Events\MessageEdited;
use App\Events\MessageSent;
use App\Exceptions\ArchivedRoomException;
use App\Exceptions\MessageEditExpiredException;
use App\Exceptions\TooManyMessagesException;
use App\Models\Message;
use App\Models\Room;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class ChatService
{
    /**
     * @throws TooManyMessagesException
     * @throws ArchivedRoomException
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

        // Keep this outside the transaction so broadcasts queue only after the message commits.
        event(new MessageSent($message));

        return $message;
    }

    /**
     * @throws MessageEditExpiredException
     * @throws ValidationException
     */
    public function edit(Message $message, User $user, array $data): Message
    {
        if ($message->created_at->copy()->addMinutes(10)->isPast()) {
            throw new MessageEditExpiredException();
        }

        $validated = Validator::make($data, [
            'body' => ['required', 'string', 'max:5000'],
        ])->validate();

        $message->forceFill([
            'body' => $validated['body'],
            'edited_at' => now(),
        ])->save();

        $message->load(['room', 'user', 'replies.user']);

        event(new MessageEdited($message));

        return $message;
    }

    public function delete(Message $message, User $user): Message
    {
        $message->delete();

        $message->load(['room', 'user', 'replies.user']);

        event(new MessageDeleted($message));

        return $message;
    }

    /**
     * @throws ValidationException
     * @throws ArchivedRoomException
     */
    private function validateMessageData(Room $room, array $data): array
    {
        if ($room->is_archived) {
            throw new ArchivedRoomException();
        }

        $validator = Validator::make($data, [
            'body' => ['required', 'string', 'max:5000'],
            'parent_id' => ['nullable', 'integer', 'exists:messages,id'],
        ]);

        $validator->after(function ($validator) use ($room, $data): void {
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
