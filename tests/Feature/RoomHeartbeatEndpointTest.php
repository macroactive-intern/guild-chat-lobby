<?php

use App\Events\PresenceUpdated;
use App\Models\Guild;
use App\Models\GuildMember;
use App\Models\Room;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;

beforeEach(function () {
    Cache::flush();
    Event::fake([PresenceUpdated::class]);
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
    Event::assertDispatched(PresenceUpdated::class, fn (PresenceUpdated $event): bool => $event->room->is($room)
        && $event->user->is($member)
        && $event->status === 'online');
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
    Event::assertDispatched(PresenceUpdated::class, 1);
});

it('marks authenticated guild members offline and broadcasts departure', function () {
    [$guild, $room] = heartbeatRoom();
    $member = User::factory()->create(['name' => 'Nate']);

    GuildMember::create([
        'guild_id' => $guild->id,
        'user_id' => $member->id,
    ]);

    $this->actingAs($member)
        ->postJson("/api/rooms/{$room->id}/heartbeat")
        ->assertOk();

    $this->actingAs($member)
        ->deleteJson("/api/rooms/{$room->id}/heartbeat")
        ->assertOk()
        ->assertJson([
            'message' => 'Presence heartbeat cleared.',
        ]);

    expect(Cache::get("presence.room.{$room->id}"))->toBeNull();
    Event::assertDispatched(PresenceUpdated::class, fn (PresenceUpdated $event): bool => $event->room->is($room)
        && $event->user->is($member)
        && $event->status === 'offline'
        && $event->onlineMembers === []);
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
