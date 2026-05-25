<?php

use App\Http\Requests\StoreMessageRequest;
use App\Models\Guild;
use App\Models\Message;
use App\Models\Room;
use App\Models\User;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    Route::post('/test/rooms/{room}/messages', function (StoreMessageRequest $request, Room $room) {
        return response()->json([
            'validated' => $request->validated(),
            'room_id' => $room->id,
        ]);
    })->middleware('api');
});

it('validates a valid message payload', function () {
    [$room, $parent] = roomWithParentMessage();

    $this->postJson("/test/rooms/{$room->id}/messages", [
        'body' => 'Ready for the raid.',
        'parent_id' => $parent->id,
    ])
        ->assertOk()
        ->assertJsonPath('validated.body', 'Ready for the raid.')
        ->assertJsonPath('validated.parent_id', $parent->id)
        ->assertJsonPath('room_id', $room->id);
});

it('returns clean API validation responses for invalid body input', function () {
    [$room] = roomWithParentMessage();

    $this->postJson("/test/rooms/{$room->id}/messages", [
        'body' => '',
    ])
        ->assertUnprocessable()
        ->assertExactJson([
            'message' => 'The given data was invalid.',
            'errors' => [
                'body' => ['The body field is required.'],
            ],
        ]);
});

it('rejects parent messages from another room', function () {
    [$room] = roomWithParentMessage();
    [, $otherParent] = roomWithParentMessage();

    $this->postJson("/test/rooms/{$room->id}/messages", [
        'body' => 'Replying across rooms should fail.',
        'parent_id' => $otherParent->id,
    ])
        ->assertUnprocessable()
        ->assertJsonPath('message', 'The given data was invalid.')
        ->assertJsonPath('errors.parent_id.0', 'The parent message must belong to the same room.');
});

it('rejects soft deleted parent messages', function () {
    [$room, $parent] = roomWithParentMessage();
    $parent->delete();

    $this->postJson("/test/rooms/{$room->id}/messages", [
        'body' => 'Replying to deleted messages should fail.',
        'parent_id' => $parent->id,
    ])
        ->assertUnprocessable()
        ->assertJsonPath('message', 'The given data was invalid.')
        ->assertJsonPath('errors.parent_id.0', 'The selected parent id is invalid.');
});

it('rejects new messages in archived rooms', function () {
    [$room] = roomWithParentMessage(['is_archived' => true]);

    $this->postJson("/test/rooms/{$room->id}/messages", [
        'body' => 'This room is closed.',
    ])
        ->assertUnprocessable()
        ->assertJsonPath('message', 'The given data was invalid.')
        ->assertJsonPath('errors.room_id.0', 'Archived rooms cannot receive new messages.');
});

function roomWithParentMessage(array $roomAttributes = []): array
{
    $user = User::factory()->create();
    $guild = Guild::create(['name' => 'Raid Guild']);
    $room = Room::create(array_merge([
        'guild_id' => $guild->id,
        'name' => 'general',
    ], $roomAttributes));
    $message = Message::create([
        'room_id' => $room->id,
        'user_id' => $user->id,
        'body' => 'Parent message.',
    ]);

    return [$room, $message];
}
