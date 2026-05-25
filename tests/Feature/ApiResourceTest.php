<?php

use App\Http\Resources\MessageResource;
use App\Http\Resources\RoomResource;
use App\Models\Guild;
use App\Models\Message;
use App\Models\Room;
use App\Models\User;

it('serializes rooms with the public room shape', function () {
    $guild = Guild::create(['name' => 'Raid Guild']);
    $room = Room::create([
        'guild_id' => $guild->id,
        'name' => 'general',
        'description' => 'Default guild room.',
        'is_archived' => true,
    ]);

    expect((new RoomResource($room))->resolve())->toBe([
        'id' => $room->id,
        'name' => 'general',
        'description' => 'Default guild room.',
        'is_archived' => true,
    ]);
});

it('serializes messages with eager loaded users and nested replies', function () {
    $user = User::factory()->create(['name' => 'Nate']);
    $guild = Guild::create(['name' => 'Raid Guild']);
    $room = Room::create([
        'guild_id' => $guild->id,
        'name' => 'general',
    ]);
    $message = Message::create([
        'room_id' => $room->id,
        'user_id' => $user->id,
        'body' => 'Raid starts soon.',
        'edited_at' => now(),
    ]);
    $reply = Message::create([
        'room_id' => $room->id,
        'user_id' => $user->id,
        'parent_id' => $message->id,
        'body' => 'Ready.',
    ]);

    $message->load(['user', 'replies.user', 'replies.replies.user']);

    $resource = json_decode((new MessageResource($message))->toJson(), true);
    $replyResource = $resource['replies'][0];

    expect($resource['id'])->toBe($message->id)
        ->and($resource['body'])->toBe('Raid starts soon.')
        ->and($resource['user'])->toBe([
            'id' => $user->id,
            'name' => 'Nate',
        ])
        ->and($resource['parent_id'])->toBeNull()
        ->and($resource['edited_at'])->not->toBeNull()
        ->and($resource['is_deleted'])->toBeFalse()
        ->and($resource['created_at'])->not->toBeNull()
        ->and($replyResource['id'])->toBe($reply->id)
        ->and($replyResource['body'])->toBe('Ready.')
        ->and($replyResource['parent_id'])->toBe($message->id);
});

it('fails fast when message resources are missing required eager loads', function () {
    $user = User::factory()->create();
    $guild = Guild::create(['name' => 'Raid Guild']);
    $room = Room::create([
        'guild_id' => $guild->id,
        'name' => 'general',
    ]);
    $message = Message::create([
        'room_id' => $room->id,
        'user_id' => $user->id,
        'body' => 'Missing eager loads.',
    ]);

    (new MessageResource($message))->resolve();
})->throws(\LogicException::class, 'App\Http\Resources\MessageResource requires the [user] relationship to be eager loaded.');

it('omits optional replies when they are not eager loaded', function () {
    $user = User::factory()->create(['name' => 'Nate']);
    $guild = Guild::create(['name' => 'Raid Guild']);
    $room = Room::create([
        'guild_id' => $guild->id,
        'name' => 'general',
    ]);
    $message = Message::create([
        'room_id' => $room->id,
        'user_id' => $user->id,
        'body' => 'No replies loaded.',
    ]);
    Message::create([
        'room_id' => $room->id,
        'user_id' => $user->id,
        'parent_id' => $message->id,
        'body' => 'Hidden by missing eager load.',
    ]);

    $message->load('user');

    expect((new MessageResource($message))->resolve())
        ->not->toHaveKey('replies');
});

it('masks soft deleted message bodies without exposing original content', function () {
    $user = User::factory()->create();
    $guild = Guild::create(['name' => 'Raid Guild']);
    $room = Room::create([
        'guild_id' => $guild->id,
        'name' => 'general',
    ]);
    $message = Message::create([
        'room_id' => $room->id,
        'user_id' => $user->id,
        'body' => 'Secret strategy.',
    ]);
    $reply = Message::create([
        'room_id' => $room->id,
        'user_id' => $user->id,
        'parent_id' => $message->id,
        'body' => 'Hidden reply.',
    ]);

    $message->delete();
    $reply->delete();

    $message = Message::withTrashed()
        ->with(['user', 'replies.user'])
        ->findOrFail($message->id);

    $resource = json_decode((new MessageResource($message))->toJson(), true);
    $json = json_encode($resource);

    expect($resource['body'])->toBe('[message deleted]')
        ->and($resource['is_deleted'])->toBeTrue()
        ->and($resource['replies'][0]['body'])->toBe('[message deleted]')
        ->and($resource['replies'][0]['is_deleted'])->toBeTrue()
        ->and($json)->not->toContain('Secret strategy.')
        ->and($json)->not->toContain('Hidden reply.');
});
