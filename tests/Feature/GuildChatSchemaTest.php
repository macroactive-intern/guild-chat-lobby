<?php

use App\Models\Guild;
use App\Models\GuildMember;
use App\Models\Message;
use App\Models\MessageRead;
use App\Models\Room;
use App\Models\User;

it('supports guild chat relationships and message soft deletes', function () {
    $user = User::factory()->create();
    $guild = Guild::create(['name' => 'Night Watch']);
    $room = Room::create([
        'guild_id' => $guild->id,
        'name' => 'general',
        'description' => 'Default guild chat room.',
    ]);
    $parent = Message::create([
        'room_id' => $room->id,
        'user_id' => $user->id,
        'body' => 'Raid starts soon.',
    ]);
    $reply = Message::create([
        'room_id' => $room->id,
        'user_id' => $user->id,
        'parent_id' => $parent->id,
        'body' => 'Ready.',
        'edited_at' => now(),
    ]);
    $read = MessageRead::create([
        'message_id' => $reply->id,
        'user_id' => $user->id,
        'read_at' => now(),
    ]);

    GuildMember::create([
        'guild_id' => $guild->id,
        'user_id' => $user->id,
    ]);

    expect($guild->rooms->first()->is($room))->toBeTrue()
        ->and($room->guild->is($guild))->toBeTrue()
        ->and($room->messages->contains($parent))->toBeTrue()
        ->and($parent->user->is($user))->toBeTrue()
        ->and($parent->room->is($room))->toBeTrue()
        ->and($reply->parent->is($parent))->toBeTrue()
        ->and($parent->replies->first()->is($reply))->toBeTrue()
        ->and($read->message->is($reply))->toBeTrue()
        ->and($read->user->is($user))->toBeTrue();

    $reply->delete();

    $this->assertSoftDeleted('messages', [
        'id' => $reply->id,
    ]);
});
