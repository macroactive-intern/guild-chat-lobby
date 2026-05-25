<?php

use App\Models\Guild;
use App\Models\GuildMember;
use App\Models\Room;
use App\Models\User;

function guildWithRoom(): array
{
    $guild = Guild::create(['name' => 'Raid Guild']);
    $room = Room::create([
        'guild_id' => $guild->id,
        'name' => 'general',
    ]);

    return [$guild, $room];
}

beforeEach(function () {
    useReverbBroadcastingForTests();
});

it('requires authentication for private guild room channels', function () {
    [$guild, $room] = guildWithRoom();

    $this->postJson('/broadcasting/auth', [
        'socket_id' => '1234.5678',
        'channel_name' => "private-guild.{$guild->id}.room.{$room->id}",
    ])->assertForbidden();
});

it('requires membership and a matching room for private guild room channels', function () {
    $member = User::factory()->create();
    $outsider = User::factory()->create();
    [$guild, $room] = guildWithRoom();
    [$otherGuild, $otherRoom] = guildWithRoom();

    GuildMember::create([
        'guild_id' => $guild->id,
        'user_id' => $member->id,
    ]);

    $this->actingAs($member)
        ->postJson('/broadcasting/auth', [
            'socket_id' => '1234.5678',
            'channel_name' => "private-guild.{$guild->id}.room.{$room->id}",
        ])
        ->assertOk()
        ->assertJsonStructure(['auth']);

    $this->actingAs($outsider)
        ->postJson('/broadcasting/auth', [
            'socket_id' => '1234.5678',
            'channel_name' => "private-guild.{$guild->id}.room.{$room->id}",
        ])
        ->assertForbidden();

    $this->actingAs($member)
        ->postJson('/broadcasting/auth', [
            'socket_id' => '1234.5678',
            'channel_name' => "private-guild.{$guild->id}.room.{$otherRoom->id}",
        ])
        ->assertForbidden();

    $this->actingAs($member)
        ->postJson('/broadcasting/auth', [
            'socket_id' => '1234.5678',
            'channel_name' => "private-guild.{$otherGuild->id}.room.{$room->id}",
        ])
        ->assertForbidden();
});

it('returns member identity for presence guild room channels', function () {
    $member = User::factory()->create(['name' => 'Nate']);
    [$guild, $room] = guildWithRoom();

    GuildMember::create([
        'guild_id' => $guild->id,
        'user_id' => $member->id,
    ]);

    $response = $this->actingAs($member)
        ->postJson('/broadcasting/auth', [
            'socket_id' => '1234.5678',
            'channel_name' => "presence-guild.{$guild->id}.room.{$room->id}",
        ])
        ->assertOk()
        ->json();

    $channelData = json_decode($response['channel_data'], true);

    expect($channelData['user_id'])->toBe((string) $member->id)
        ->and($channelData['user_info'])->toBe([
            'id' => $member->id,
            'name' => 'Nate',
        ]);
});
