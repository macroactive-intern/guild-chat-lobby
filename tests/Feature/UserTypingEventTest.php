<?php

use App\Events\UserTyping;
use App\Models\Guild;
use App\Models\Room;
use App\Models\User;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;

it('broadcasts typing indicators to the private guild room channel', function () {
    [$user, $room] = userTypingEventRoom();

    $event = new UserTyping($user, $room);
    $channel = $event->broadcastOn();

    expect($event)->toBeInstanceOf(ShouldBroadcast::class)
        ->and($channel->name)->toBe("private-guild.{$room->guild_id}.room.{$room->id}")
        ->and(method_exists($event, 'onQueue'))->toBeTrue();
});

it('broadcasts only transient typing payload data', function () {
    [$user, $room] = userTypingEventRoom();

    $payload = (new UserTyping($user, $room))->broadcastWith();

    expect($payload)->toBe([
        'room_id' => $room->id,
        'user_id' => $user->id,
        'user_name' => $user->name,
    ]);
});

it('supports broadcasting typing indicators to others', function () {
    [$user, $room] = userTypingEventRoom();

    $pendingBroadcast = broadcast(new UserTyping($user, $room))->toOthers();

    expect($pendingBroadcast)->not->toBeNull();
});

function userTypingEventRoom(): array
{
    $user = User::factory()->create(['name' => 'Nate']);
    $guild = Guild::create(['name' => 'Raid Guild']);
    $room = Room::create([
        'guild_id' => $guild->id,
        'name' => 'general',
    ]);

    return [$user, $room];
}
