<?php

use App\Models\Guild;
use App\Models\Room;
use App\Models\User;
use App\Services\PresenceService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

beforeEach(function () {
    Cache::flush();
    Carbon::setTestNow(now());
});

afterEach(function () {
    Carbon::setTestNow();
});

it('tracks online users per room using the presence cache key', function () {
    [$room, $user] = presenceServiceRoomAndUser();

    app(PresenceService::class)->markOnline($room, $user);

    expect(Cache::get("presence.room.{$room->id}"))->toHaveKey($user->id)
        ->and(app(PresenceService::class)->onlineMembers($room)->all())->toBe([
            [
                'id' => $user->id,
                'name' => $user->name,
            ],
        ]);
});

it('stores room presence with a 30 second cache ttl', function () {
    [$room, $user] = presenceServiceRoomAndUser();
    $expiresAt = now()->addSeconds(30);

    Cache::shouldReceive('get')
        ->once()
        ->with("presence.room.{$room->id}", [])
        ->andReturn([]);
    Cache::shouldReceive('put')
        ->once()
        ->with(
            "presence.room.{$room->id}",
            \Mockery::on(fn (array $members): bool => isset($members[$user->id])
                && $members[$user->id]['id'] === $user->id
                && $members[$user->id]['name'] === $user->name),
            \Mockery::on(fn (Carbon $ttl): bool => $ttl->equalTo($expiresAt)),
        );

    app(PresenceService::class)->markOnline($room, $user);
});

it('refreshes heartbeat timestamps when a user is marked online again', function () {
    [$room, $user] = presenceServiceRoomAndUser();
    $service = app(PresenceService::class);

    $service->markOnline($room, $user);
    $firstSeenAt = Cache::get("presence.room.{$room->id}")[$user->id]['last_seen_at'];

    Carbon::setTestNow(now()->addSeconds(10));

    $service->markOnline($room, $user);
    $secondSeenAt = Cache::get("presence.room.{$room->id}")[$user->id]['last_seen_at'];

    expect($secondSeenAt)->toBeGreaterThan($firstSeenAt)
        ->and($service->onlineMembers($room))->toHaveCount(1);
});

it('filters stale members after the ttl window', function () {
    [$room, $user] = presenceServiceRoomAndUser();
    $service = app(PresenceService::class);

    $service->markOnline($room, $user);

    Carbon::setTestNow(now()->addSeconds(31));

    expect($service->onlineMembers($room))->toBeEmpty()
        ->and(Cache::get("presence.room.{$room->id}"))->toBeNull();
});

it('marks users offline without affecting other online room members', function () {
    [$room, $user] = presenceServiceRoomAndUser();
    $otherUser = User::factory()->create(['name' => 'Other User']);
    $service = app(PresenceService::class);

    $service->markOnline($room, $user);
    $service->markOnline($room, $otherUser);
    $service->markOffline($room, $user);

    expect($service->onlineMembers($room)->all())->toBe([
        [
            'id' => $otherUser->id,
            'name' => 'Other User',
        ],
    ]);
});

function presenceServiceRoomAndUser(): array
{
    $guild = Guild::create(['name' => 'Raid Guild']);
    $room = Room::create([
        'guild_id' => $guild->id,
        'name' => 'general',
    ]);
    $user = User::factory()->create(['name' => 'Nate']);

    return [$room, $user];
}
