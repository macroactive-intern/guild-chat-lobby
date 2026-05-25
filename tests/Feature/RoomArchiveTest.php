<?php

use App\Events\RoomStatusUpdated;
use App\Models\Guild;
use App\Models\GuildMember;
use App\Models\Room;
use App\Models\User;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Support\Facades\Event;

it('allows guild leaders to archive and unarchive rooms', function () {
    Event::fake([RoomStatusUpdated::class]);
    [$leader, $room] = roomArchiveLeaderRoom();

    $this->actingAs($leader)
        ->patchJson("/api/rooms/{$room->id}/archive")
        ->assertOk()
        ->assertJsonPath('data.is_archived', true);

    expect($room->fresh()->is_archived)->toBeTrue();
    Event::assertDispatched(RoomStatusUpdated::class, fn (RoomStatusUpdated $event) => $event->room->is($room)
        && $event->room->is_archived);

    $this->actingAs($leader)
        ->patchJson("/api/rooms/{$room->id}/unarchive")
        ->assertOk()
        ->assertJsonPath('data.is_archived', false);

    expect($room->fresh()->is_archived)->toBeFalse();
    Event::assertDispatched(RoomStatusUpdated::class, 2);
});

it('rejects room archive updates from normal guild members', function () {
    [$leader, $room, $guild] = roomArchiveLeaderRoom();
    $member = User::factory()->create();

    GuildMember::create([
        'guild_id' => $guild->id,
        'user_id' => $member->id,
    ]);

    $this->actingAs($member)
        ->patchJson("/api/rooms/{$room->id}/archive")
        ->assertForbidden();

    $this->actingAs($member)
        ->patchJson("/api/rooms/{$room->id}/unarchive")
        ->assertForbidden();

    expect($room->fresh()->is_archived)->toBeFalse()
        ->and($leader->isLeaderOfGuild($guild->id))->toBeTrue();
});

it('makes archived rooms read-only for new messages', function () {
    [$leader, $room, $guild] = roomArchiveLeaderRoom();
    $member = User::factory()->create();

    GuildMember::create([
        'guild_id' => $guild->id,
        'user_id' => $member->id,
    ]);

    $this->actingAs($leader)
        ->patchJson("/api/rooms/{$room->id}/archive")
        ->assertOk();

    $this->actingAs($member)
        ->postJson("/api/rooms/{$room->id}/messages", [
            'body' => 'This room should be read-only.',
        ])
        ->assertConflict()
        ->assertJsonPath('error', 'archived_room')
        ->assertJsonPath('message', 'Archived rooms cannot receive new messages.');
});

it('broadcasts room status updates to the private room channel', function () {
    [, $room] = roomArchiveLeaderRoom([
        'is_archived' => true,
    ]);

    $event = new RoomStatusUpdated($room);
    $channel = $event->broadcastOn();
    $payload = $event->broadcastWith();

    expect($event)->toBeInstanceOf(ShouldBroadcast::class)
        ->and($event->broadcastAs())->toBe('room.status.updated')
        ->and($channel->name)->toBe("private-guild.{$room->guild_id}.room.{$room->id}")
        ->and($payload['id'])->toBe($room->id)
        ->and($payload['guild_id'])->toBe($room->guild_id)
        ->and($payload['is_archived'])->toBeTrue()
        ->and($payload['updated_at']->equalTo($room->updated_at))->toBeTrue();
});

function roomArchiveLeaderRoom(array $roomAttributes = []): array
{
    $leader = User::factory()->create(['name' => 'Nate']);
    $guild = Guild::create(['name' => 'Raid Guild']);
    $room = Room::create(array_merge([
        'guild_id' => $guild->id,
        'name' => 'general',
    ], $roomAttributes));

    GuildMember::create([
        'guild_id' => $guild->id,
        'user_id' => $leader->id,
        'is_leader' => true,
    ]);

    return [$leader, $room, $guild];
}
