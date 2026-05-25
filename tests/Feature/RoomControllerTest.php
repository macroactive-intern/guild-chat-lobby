<?php

use App\Models\Guild;
use App\Models\GuildMember;
use App\Models\Message;
use App\Models\Room;
use App\Models\User;

it('lists guild rooms for members with pagination and the latest 50 messages', function () {
    $member = User::factory()->create(['name' => 'Nate']);
    $messageUser = User::factory()->create(['name' => 'Scout']);
    [$guild, $firstRoom] = roomControllerGuildWithRoom('alpha');
    $secondRoom = Room::create([
        'guild_id' => $guild->id,
        'name' => 'beta',
    ]);
    $thirdRoom = Room::create([
        'guild_id' => $guild->id,
        'name' => 'gamma',
    ]);
    [$otherGuild] = roomControllerGuildWithRoom('outsider');

    GuildMember::create([
        'guild_id' => $guild->id,
        'user_id' => $member->id,
    ]);
    GuildMember::create([
        'guild_id' => $otherGuild->id,
        'user_id' => $member->id,
    ]);

    foreach (range(1, 55) as $number) {
        Message::create([
            'room_id' => $thirdRoom->id,
            'user_id' => $messageUser->id,
            'body' => "Message {$number}",
            'created_at' => now()->addSeconds($number),
            'updated_at' => now()->addSeconds($number),
        ]);
    }

    foreach (range(1, 55) as $number) {
        Message::create([
            'room_id' => $secondRoom->id,
            'user_id' => $messageUser->id,
            'body' => "Beta message {$number}",
            'created_at' => now()->addMinutes(1)->addSeconds($number),
            'updated_at' => now()->addMinutes(1)->addSeconds($number),
        ]);
    }

    $response = $this->actingAs($member)
        ->getJson("/api/guilds/{$guild->id}/rooms?per_page=2")
        ->assertOk()
        ->assertJsonPath('meta.per_page', 2)
        ->assertJsonPath('meta.total', 3);

    expect($response->json('data'))->toHaveCount(2)
        ->and($response->json('data.0.id'))->toBe($thirdRoom->id)
        ->and($response->json('data.0.messages'))->toHaveCount(50)
        ->and(collect($response->json('data.0.messages'))->pluck('body')->all())
        ->toContain('Message 55')
        ->not->toContain('Message 1')
        ->and($response->json('data.1.id'))->toBe($secondRoom->id)
        ->and($response->json('data.1.messages'))->toHaveCount(50)
        ->and(collect($response->json('data.1.messages'))->pluck('body')->all())
        ->toContain('Beta message 55')
        ->not->toContain('Beta message 1')
        ->and(collect($response->json('data'))->pluck('id')->all())
        ->not->toContain($firstRoom->id);
});

it('rejects guild room listing for non members', function () {
    $outsider = User::factory()->create();
    [$guild] = roomControllerGuildWithRoom();

    $this->actingAs($outsider)
        ->getJson("/api/guilds/{$guild->id}/rooms")
        ->assertForbidden();
});

it('creates rooms for guild leaders', function () {
    $leader = User::factory()->create();
    [$guild] = roomControllerGuildWithRoom();

    GuildMember::create([
        'guild_id' => $guild->id,
        'user_id' => $leader->id,
        'is_leader' => true,
    ]);

    $this->actingAs($leader)
        ->postJson("/api/guilds/{$guild->id}/rooms", [
            'name' => 'strategy',
            'description' => 'Boss plans.',
        ])
        ->assertCreated()
        ->assertJsonPath('data.name', 'strategy')
        ->assertJsonPath('data.description', 'Boss plans.')
        ->assertJsonPath('data.is_archived', false);

    $this->assertDatabaseHas('rooms', [
        'guild_id' => $guild->id,
        'name' => 'strategy',
        'description' => 'Boss plans.',
        'is_archived' => false,
    ]);
});

it('rejects room creation for normal guild members', function () {
    $member = User::factory()->create();
    [$guild] = roomControllerGuildWithRoom();

    GuildMember::create([
        'guild_id' => $guild->id,
        'user_id' => $member->id,
    ]);

    $this->actingAs($member)
        ->postJson("/api/guilds/{$guild->id}/rooms", [
            'name' => 'strategy',
        ])
        ->assertForbidden();
});

it('shows a room to guild members with the latest 50 messages', function () {
    $member = User::factory()->create();
    $messageUser = User::factory()->create(['name' => 'Scout']);
    [$guild, $room] = roomControllerGuildWithRoom();

    GuildMember::create([
        'guild_id' => $guild->id,
        'user_id' => $member->id,
    ]);

    foreach (range(1, 51) as $number) {
        Message::create([
            'room_id' => $room->id,
            'user_id' => $messageUser->id,
            'body' => "Room message {$number}",
            'created_at' => now()->addSeconds($number),
            'updated_at' => now()->addSeconds($number),
        ]);
    }

    $this->actingAs($member)
        ->getJson("/api/rooms/{$room->id}")
        ->assertOk()
        ->assertJsonPath('data.id', $room->id)
        ->assertJsonCount(50, 'data.messages')
        ->assertJsonPath('data.messages.0.body', 'Room message 51');
});

it('rejects room show requests from users outside the guild', function () {
    $outsider = User::factory()->create();
    [, $room] = roomControllerGuildWithRoom();

    $this->actingAs($outsider)
        ->getJson("/api/rooms/{$room->id}")
        ->assertForbidden();
});

function roomControllerGuildWithRoom(string $roomName = 'general'): array
{
    $guild = Guild::create(['name' => 'Raid Guild']);
    $room = Room::create([
        'guild_id' => $guild->id,
        'name' => $roomName,
    ]);

    return [$guild, $room];
}
