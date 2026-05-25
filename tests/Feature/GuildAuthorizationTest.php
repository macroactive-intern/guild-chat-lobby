<?php

use App\Models\Guild;
use App\Models\GuildMember;
use App\Models\Message;
use App\Models\Room;
use App\Models\User;
use Illuminate\Support\Facades\DB;

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

it('memoizes guild membership helper lookups per user instance and guild', function () {
    $leader = User::factory()->create();
    $guild = Guild::create(['name' => 'Raid Guild']);

    GuildMember::create([
        'guild_id' => $guild->id,
        'user_id' => $leader->id,
        'is_leader' => true,
    ]);

    DB::flushQueryLog();
    DB::enableQueryLog();

    expect($leader->isMemberOfGuild($guild->id))->toBeTrue()
        ->and($leader->isLeaderOfGuild($guild->id))->toBeTrue()
        ->and($leader->isMemberOfGuild($guild->id))->toBeTrue()
        ->and($leader->isLeaderOfGuild($guild->id))->toBeTrue();

    $membershipQueries = collect(DB::getQueryLog())
        ->filter(fn (array $query): bool => str_contains(strtolower($query['query']), 'guild_members'));

    expect($membershipQueries)->toHaveCount(1);
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

    expect($member->can('react', $memberMessage))->toBeTrue()
        ->and($leader->can('react', $memberMessage))->toBeTrue()
        ->and($outsider->can('react', $memberMessage))->toBeFalse()
        ->and($member->can('update', $memberMessage))->toBeTrue()
        ->and($member->can('delete', $memberMessage))->toBeTrue()
        ->and($member->can('update', $leaderMessage))->toBeFalse()
        ->and($member->can('delete', $leaderMessage))->toBeFalse()
        ->and($leader->can('update', $memberMessage))->toBeFalse()
        ->and($leader->can('delete', $memberMessage))->toBeTrue()
        ->and($outsider->can('update', $memberMessage))->toBeFalse()
        ->and($outsider->can('delete', $memberMessage))->toBeFalse();
});

it('rejects message updates outside the edit window through message policy', function () {
    [, $member, , , $room] = guildWithUsers();
    $editWindowMinutes = (int) config('chat.messages.edit_window_minutes');
    $expiredMessage = Message::create([
        'room_id' => $room->id,
        'user_id' => $member->id,
        'body' => 'Too old to edit.',
    ]);
    $expiredMessage->forceFill([
        'created_at' => now()->subMinutes($editWindowMinutes + 1),
        'updated_at' => now()->subMinutes($editWindowMinutes + 1),
    ])->save();

    expect($member->can('update', $expiredMessage))->toBeFalse()
        ->and($member->can('delete', $expiredMessage))->toBeTrue();
});

it('denies orphaned messages through message policies without throwing', function () {
    [, $member] = guildWithUsers();
    $message = Message::make([
        'id' => 999,
        'room_id' => 999,
        'user_id' => $member->id,
        'body' => 'Orphaned message.',
    ]);
    $message->exists = true;

    expect($member->can('react', $message))->toBeFalse()
        ->and($member->can('update', $message))->toBeFalse()
        ->and($member->can('delete', $message))->toBeFalse();
});
