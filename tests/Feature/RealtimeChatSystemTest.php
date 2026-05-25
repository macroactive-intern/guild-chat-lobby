<?php

use App\Events\MessageSent;
use App\Events\UserTyping;
use App\Models\Guild;
use App\Models\GuildMember;
use App\Models\Message;
use App\Models\MessageRead;
use App\Models\Room;
use App\Models\User;
use Illuminate\Broadcasting\BroadcastManager;
use Illuminate\Contracts\Broadcasting\Factory as BroadcastFactory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Tests\Fakes\FakeableBroadcastManager;

beforeEach(function () {
    installRealtimeChatBroadcastFake();

    Broadcast::fake();
    Event::fake([
        MessageSent::class,
        UserTyping::class,
    ]);
    Cache::flush();
    Carbon::setTestNow(now());
});

afterEach(function () {
    Carbon::setTestNow();
});

it('prevents non-members from accessing rooms', function () {
    [$member, $room] = realtimeChatMemberRoom();
    $outsider = User::factory()->create();

    $this->actingAs($member)
        ->getJson("/api/rooms/{$room->id}")
        ->assertOk();

    $this->actingAs($outsider)
        ->getJson("/api/rooms/{$room->id}")
        ->assertForbidden();
});

it('prevents non-members from subscribing to private and presence room channels', function () {
    useReverbBroadcastingForTests();

    [$member, $room, $guild] = realtimeChatMemberRoom();
    $outsider = User::factory()->create();

    foreach (['private', 'presence'] as $channelType) {
        $this->actingAs($member)
            ->postJson('/broadcasting/auth', [
                'socket_id' => '1234.5678',
                'channel_name' => "{$channelType}-guild.{$guild->id}.room.{$room->id}",
            ])
            ->assertOk();

        $this->actingAs($outsider)
            ->postJson('/broadcasting/auth', [
                'socket_id' => '1234.5678',
                'channel_name' => "{$channelType}-guild.{$guild->id}.room.{$room->id}",
            ])
            ->assertForbidden();
    }
});

it('returns 429 when a user sends messages too quickly', function () {
    [$user, $room] = realtimeChatMemberRoom();

    $this->actingAs($user)
        ->postJson("/api/rooms/{$room->id}/messages", [
            'body' => 'First message.',
        ])
        ->assertCreated();

    $this->actingAs($user)
        ->postJson("/api/rooms/{$room->id}/messages", [
            'body' => 'Second message.',
        ])
        ->assertTooManyRequests()
        ->assertJsonPath('error', 'too_many_messages');
});

it('isolates messages per room', function () {
    [$user, $room, $guild] = realtimeChatMemberRoom();
    $otherRoom = Room::create([
        'guild_id' => $guild->id,
        'name' => 'strategy',
    ]);

    Message::create([
        'room_id' => $room->id,
        'user_id' => $user->id,
        'body' => 'General room message.',
    ]);
    Message::create([
        'room_id' => $otherRoom->id,
        'user_id' => $user->id,
        'body' => 'Strategy room message.',
    ]);

    $this->actingAs($user)
        ->getJson("/api/rooms/{$room->id}/messages")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.body', 'General room message.');
});

it('rejects replies whose parent message belongs to another room', function () {
    [$user, $room, $guild] = realtimeChatMemberRoom();
    $otherRoom = Room::create([
        'guild_id' => $guild->id,
        'name' => 'strategy',
    ]);
    $otherRoomMessage = Message::create([
        'room_id' => $otherRoom->id,
        'user_id' => $user->id,
        'body' => 'Different room.',
    ]);

    $this->actingAs($user)
        ->postJson("/api/rooms/{$room->id}/messages", [
            'body' => 'Invalid reply.',
            'parent_id' => $otherRoomMessage->id,
        ])
        ->assertUnprocessable()
        ->assertJsonPath('errors.parent_id.0', 'The parent message must belong to the same room.');
});

it('blocks message edits after ten minutes', function () {
    [$user, $room] = realtimeChatMemberRoom();
    $editWindowMinutes = (int) config('chat.messages.edit_window_minutes');
    $message = Message::create([
        'room_id' => $room->id,
        'user_id' => $user->id,
        'body' => 'Original.',
    ]);
    $message->forceFill([
        'created_at' => now()->subMinutes($editWindowMinutes + 1),
        'updated_at' => now()->subMinutes($editWindowMinutes + 1),
    ])->save();

    $this->actingAs($user)
        ->patchJson("/api/messages/{$message->id}", [
            'body' => 'Too late.',
        ])
        ->assertForbidden()
        ->assertJsonPath('message', 'This action is unauthorized.');
});

it('shows deleted message bodies as deleted placeholders', function () {
    [$user, $room] = realtimeChatMemberRoom();
    $message = Message::create([
        'room_id' => $room->id,
        'user_id' => $user->id,
        'body' => 'Secret strategy.',
    ]);

    $this->actingAs($user)
        ->deleteJson("/api/messages/{$message->id}")
        ->assertOk()
        ->assertJsonPath('data.body', '[message deleted]');

    $this->actingAs($user)
        ->getJson("/api/rooms/{$room->id}/messages")
        ->assertOk()
        ->assertJsonPath('data.0.body', '[message deleted]');
});

it('broadcasts typing events without creating database rows', function () {
    [$user, $room] = realtimeChatMemberRoom();

    $this->actingAs($user)
        ->postJson("/api/rooms/{$room->id}/typing")
        ->assertAccepted();

    Event::assertDispatched(UserTyping::class);

    expect(Message::count())->toBe(0)
        ->and(MessageRead::count())->toBe(0);
});

it('rate limits typing events to protect broadcast capacity', function () {
    [$user, $room] = realtimeChatMemberRoom();

    $this->actingAs($user)
        ->postJson("/api/rooms/{$room->id}/typing")
        ->assertAccepted();

    $this->actingAs($user)
        ->postJson("/api/rooms/{$room->id}/typing")
        ->assertTooManyRequests()
        ->assertJsonPath('error', 'too_many_typing_events');

    Event::assertDispatched(UserTyping::class, 1);
});

it('upserts read receipts for the latest room message', function () {
    [$user, $room] = realtimeChatMemberRoom();
    $author = User::factory()->create();
    $message = Message::create([
        'room_id' => $room->id,
        'user_id' => $author->id,
        'body' => 'Read me.',
    ]);

    $this->actingAs($user)
        ->postJson("/api/rooms/{$room->id}/read")
        ->assertOk()
        ->assertJsonPath('latest_read_message_id', $message->id);

    $firstReadAt = MessageRead::firstOrFail()->read_at;

    Carbon::setTestNow(now()->addMinute());

    $this->actingAs($user)
        ->postJson("/api/rooms/{$room->id}/read")
        ->assertOk()
        ->assertJsonPath('latest_read_message_id', $message->id);

    $receipt = MessageRead::firstOrFail();

    expect(MessageRead::count())->toBe(1)
        ->and($receipt->message_id)->toBe($message->id)
        ->and($receipt->user_id)->toBe($user->id)
        ->and($receipt->read_at->greaterThan($firstReadAt))->toBeTrue();
});

it('rejects new messages in archived rooms', function () {
    [$user, $room] = realtimeChatMemberRoom([
        'is_archived' => true,
    ]);

    $this->actingAs($user)
        ->postJson("/api/rooms/{$room->id}/messages", [
            'body' => 'Can anyone hear me?',
        ])
        ->assertConflict()
        ->assertJsonPath('error', 'archived_room')
        ->assertJsonPath('message', 'Archived rooms cannot receive new messages.');
});

function realtimeChatMemberRoom(array $roomAttributes = []): array
{
    $user = User::factory()->create(['name' => 'Nate']);
    $guild = Guild::create(['name' => 'Raid Guild']);
    $room = Room::create(array_merge([
        'guild_id' => $guild->id,
        'name' => 'general',
    ], $roomAttributes));

    GuildMember::create([
        'guild_id' => $guild->id,
        'user_id' => $user->id,
    ]);

    return [$user, $room, $guild];
}

function installRealtimeChatBroadcastFake(): void
{
    $manager = new FakeableBroadcastManager(app());

    app()->instance(BroadcastManager::class, $manager);
    app()->instance(BroadcastFactory::class, $manager);

    Broadcast::clearResolvedInstance(BroadcastFactory::class);
}
