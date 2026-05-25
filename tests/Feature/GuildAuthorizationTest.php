<?php

use App\Models\Guild;
use App\Models\GuildMember;
use App\Models\Message;
use App\Models\Room;
use App\Models\User;

function guildWithUsers(): array
{
    $leader = User::factory()->create();
    $member = User::factory()->create();
    $outsider = User::factory()->create();
    $guild = Guild::create(['name' => 'Raid Guild']);
    $room = Room::create([
        'guild_id' => $guild->id,
        'name' => 'general',
    ]);

    GuildMember::create([
        'guild_id' => $guild->id,
        'user_id' => $leader->id,
        'is_leader' => true,
    ]);
    GuildMember::create([
        'guild_id' => $guild->id,
        'user_id' => $member->id,
    ]);

    return [$leader, $member, $outsider, $guild, $room];
}

it('checks guild membership helpers for members and leaders', function () {
    $leader = User::factory()->create();
    $member = User::factory()->create();
    $outsider = User::factory()->create();
    $guild = Guild::create(['name' => 'Raid Guild']);

    GuildMember::create([
        'guild_id' => $guild->id,
        'user_id' => $leader->id,
        'is_leader' => true,
    ]);
    GuildMember::create([
        'guild_id' => $guild->id,
        'user_id' => $member->id,
    ]);

    expect($leader->isMemberOfGuild($guild->id))->toBeTrue()
        ->and($leader->isLeaderOfGuild($guild->id))->toBeTrue()
        ->and($member->isMemberOfGuild($guild->id))->toBeTrue()
        ->and($member->isLeaderOfGuild($guild->id))->toBeFalse()
        ->and($outsider->isMemberOfGuild($guild->id))->toBeFalse()
        ->and($outsider->isLeaderOfGuild($guild->id))->toBeFalse();
});

it('authorizes members and leaders through guild and room policies', function () {
    [$leader, $member, $outsider, $guild, $room] = guildWithUsers();

    expect($leader->can('view', $guild))->toBeTrue()
        ->and($member->can('view', $guild))->toBeTrue()
        ->and($outsider->can('view', $guild))->toBeFalse()
        ->and($leader->can('createRoom', $guild))->toBeTrue()
        ->and($member->can('createRoom', $guild))->toBeFalse()
        ->and($outsider->can('createRoom', $guild))->toBeFalse()
        ->and($leader->can('view', $room))->toBeTrue()
        ->and($member->can('view', $room))->toBeTrue()
        ->and($outsider->can('view', $room))->toBeFalse()
        ->and($leader->can('sendMessage', $room))->toBeTrue()
        ->and($member->can('sendMessage', $room))->toBeTrue()
        ->and($outsider->can('sendMessage', $room))->toBeFalse();
});

it('authorizes message owners and guild leaders through message policies', function () {
    [$leader, $member, $outsider, , $room] = guildWithUsers();

    $memberMessage = Message::create([
        'room_id' => $room->id,
        'user_id' => $member->id,
        'body' => 'Need backup.',
    ]);
    $leaderMessage = Message::create([
        'room_id' => $room->id,
        'user_id' => $leader->id,
        'body' => 'On my way.',
    ]);

    expect($member->can('update', $memberMessage))->toBeTrue()
        ->and($member->can('delete', $memberMessage))->toBeTrue()
        ->and($member->can('update', $leaderMessage))->toBeFalse()
        ->and($member->can('delete', $leaderMessage))->toBeFalse()
        ->and($leader->can('update', $memberMessage))->toBeFalse()
        ->and($leader->can('delete', $memberMessage))->toBeTrue()
        ->and($outsider->can('update', $memberMessage))->toBeFalse()
        ->and($outsider->can('delete', $memberMessage))->toBeFalse();
});
