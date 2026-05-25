<?php

use App\Events\PresenceUpdated;
use App\Models\Guild;
use App\Models\Room;
use App\Models\User;
use App\Services\PresenceService;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
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
    Event::assertDispatched(PresenceUpdated::class, 1);
});

it('broadcasts presence updates when users appear and disappear', function () {
    [$room, $user] = presenceServiceRoomAndUser();
    $service = app(PresenceService::class);

    $service->markOnline($room, $user);

    Event::assertDispatched(PresenceUpdated::class, fn (PresenceUpdated $event): bool => $event->room->is($room)
        && $event->user->is($user)
        && $event->status === 'online'
        && $event->onlineMembers === [
            [
                'id' => $user->id,
                'name' => $user->name,
            ],
        ]);

    $service->markOffline($room, $user);

    Event::assertDispatched(PresenceUpdated::class, fn (PresenceUpdated $event): bool => $event->room->is($room)
        && $event->user->is($user)
        && $event->status === 'offline'
        && $event->onlineMembers === []);
    Event::assertDispatched(PresenceUpdated::class, 2);
});

it('does not broadcast when marking an already offline user offline', function () {
    [$room, $user] = presenceServiceRoomAndUser();

    app(PresenceService::class)->markOffline($room, $user);

    Event::assertNotDispatched(PresenceUpdated::class);
});

it('broadcasts presence updated events to the private room channel', function () {
    [$room, $user] = presenceServiceRoomAndUser();
    $event = new PresenceUpdated($room, $user, 'online', [
        [
            'id' => $user->id,
            'name' => $user->name,
        ],
    ]);
    $payload = $event->broadcastWith();

    expect($event)->toBeInstanceOf(ShouldBroadcast::class)
        ->and($event->broadcastOn()->name)->toBe("private-guild.{$room->guild_id}.room.{$room->id}")
        ->and($event->broadcastAs())->toBe('presence.updated')
        ->and($event->tries)->toBe(3)
        ->and($event->backoff)->toBe(5)
        ->and($event->maxExceptions)->toBe(3)
        ->and($payload)->toBe([
            'room_id' => $room->id,
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
            ],
            'status' => 'online',
            'online_members' => [
                [
                    'id' => $user->id,
                    'name' => $user->name,
                ],
            ],
        ]);
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
