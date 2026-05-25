<?php

use App\Models\Guild;
use App\Models\GuildMember;
use App\Models\Room;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

beforeEach(function () {
    Cache::flush();
    Carbon::setTestNow(now());
});

afterEach(function () {
    Carbon::setTestNow();
});

it('requires authentication to record a room heartbeat', function () {
    [, $room] = heartbeatRoom();

    $this->postJson("/api/rooms/{$room->id}/heartbeat")
        ->assertUnauthorized();
});

it('records a heartbeat for authenticated guild members', function () {
    [$guild, $room] = heartbeatRoom();
    $member = User::factory()->create(['name' => 'Nate']);

    GuildMember::create([
        'guild_id' => $guild->id,
        'user_id' => $member->id,
    ]);

    $this->actingAs($member)
        ->postJson("/api/rooms/{$room->id}/heartbeat")
        ->assertOk()
        ->assertJson([
            'message' => 'Presence heartbeat recorded.',
        ]);

    expect(Cache::get("presence.room.{$room->id}"))->toHaveKey($member->id)
        ->and(Cache::get("presence.room.{$room->id}")[$member->id]['name'])->toBe('Nate');
});

it('rejects heartbeat requests from users outside the room guild', function () {
    [, $room] = heartbeatRoom();
    $outsider = User::factory()->create();

    $this->actingAs($outsider)
        ->postJson("/api/rooms/{$room->id}/heartbeat")
        ->assertForbidden();

    expect(Cache::get("presence.room.{$room->id}"))->toBeNull();
});

it('refreshes the presence ttl on heartbeat', function () {
    [$guild, $room] = heartbeatRoom();
    $member = User::factory()->create();

    GuildMember::create([
        'guild_id' => $guild->id,
        'user_id' => $member->id,
    ]);

    $this->actingAs($member)
        ->postJson("/api/rooms/{$room->id}/heartbeat")
        ->assertOk();

    $firstSeenAt = Cache::get("presence.room.{$room->id}")[$member->id]['last_seen_at'];

    Carbon::setTestNow(now()->addSeconds(10));

    $this->actingAs($member)
        ->postJson("/api/rooms/{$room->id}/heartbeat")
        ->assertOk();

    expect(Cache::get("presence.room.{$room->id}")[$member->id]['last_seen_at'])
        ->toBeGreaterThan($firstSeenAt);
});

function heartbeatRoom(): array
{
    $guild = Guild::create(['name' => 'Raid Guild']);
    $room = Room::create([
        'guild_id' => $guild->id,
        'name' => 'general',
    ]);

    return [$guild, $room];
}
