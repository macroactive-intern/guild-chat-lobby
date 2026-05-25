<?php

use App\Events\MessageSent;
use App\Models\Guild;
use App\Models\Message;
use App\Models\Room;
use App\Models\User;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;

it('broadcasts queued message sent events to the private guild room channel', function () {
    [$message, $user, $room] = messageSentEventMessage();

    $event = new MessageSent($message);
    $channels = $event->broadcastOn();

    expect($event)->toBeInstanceOf(ShouldBroadcast::class)
        ->and($channels->name)->toBe("private-guild.{$room->guild_id}.room.{$room->id}")
        ->and(method_exists($event, 'onQueue'))->toBeTrue();
});

it('broadcasts the expected message payload', function () {
    [$message, $user] = messageSentEventMessage(parent: true);

    $payload = (new MessageSent($message))->broadcastWith();

    expect($payload)->toHaveKeys(['id', 'body', 'user', 'parent_id', 'created_at'])
        ->and($payload['id'])->toBe($message->id)
        ->and($payload['body'])->toBe('Child message.')
        ->and($payload['user'])->toBe([
            'id' => $user->id,
            'name' => $user->name,
        ])
        ->and($payload['parent_id'])->toBe($message->parent_id)
        ->and($payload['created_at'])->not->toBeNull();
});

it('never exposes original content for deleted messages', function () {
    [$message] = messageSentEventMessage();

    $message->delete();
    $message = Message::withTrashed()
        ->with(['room', 'user'])
        ->findOrFail($message->id);

    $payload = (new MessageSent($message))->broadcastWith();

    expect($payload['body'])->toBe('[message deleted]')
        ->and(json_encode($payload))->not->toContain('Visible message.');
});

function messageSentEventMessage(bool $parent = false): array
{
    $user = User::factory()->create(['name' => 'Nate']);
    $guild = Guild::create(['name' => 'Raid Guild']);
    $room = Room::create([
        'guild_id' => $guild->id,
        'name' => 'general',
    ]);

    if (! $parent) {
        $message = Message::create([
            'room_id' => $room->id,
            'user_id' => $user->id,
            'body' => 'Visible message.',
        ]);

        return [$message, $user, $room];
    }

    $parentMessage = Message::create([
        'room_id' => $room->id,
        'user_id' => $user->id,
        'body' => 'Parent message.',
    ]);
    $message = Message::create([
        'room_id' => $room->id,
        'user_id' => $user->id,
        'parent_id' => $parentMessage->id,
        'body' => 'Child message.',
    ]);

    return [$message, $user, $room];
}
