<?php

use App\Events\MessageSent;
use App\Events\UserTyping;
use App\Models\Guild;
use App\Models\GuildMember;
use App\Models\Message;
use App\Models\MessageRead;
use App\Models\Room;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;

beforeEach(function () {
    Cache::flush();
});

it('sends room messages through the chat service', function () {
    Event::fake([MessageSent::class]);
    [$user, $room] = messageControllerMemberRoom();

    $this->actingAs($user)
        ->postJson("/api/rooms/{$room->id}/messages", [
            'body' => 'Raid starts now.',
        ])
        ->assertCreated()
        ->assertJsonPath('data.body', 'Raid starts now.')
        ->assertJsonPath('data.user.id', $user->id);

    $this->assertDatabaseHas('messages', [
        'room_id' => $room->id,
        'user_id' => $user->id,
        'body' => 'Raid starts now.',
    ]);
    Event::assertDispatched(MessageSent::class);
});

it('returns 429 when chat service rate limiting rejects spam', function () {
    Event::fake([MessageSent::class]);
    [$user, $room] = messageControllerMemberRoom();

    $this->actingAs($user)
        ->postJson("/api/rooms/{$room->id}/messages", [
            'body' => 'First.',
        ])
        ->assertCreated();

    $this->actingAs($user)
        ->postJson("/api/rooms/{$room->id}/messages", [
            'body' => 'Second.',
        ])
        ->assertTooManyRequests()
        ->assertExactJson([
            'message' => 'You are sending messages too quickly.',
            'error' => 'too_many_messages',
        ]);
});

it('returns validation errors from chat service for invalid message payloads', function () {
    Event::fake([MessageSent::class]);
    [$user, $room] = messageControllerMemberRoom();

    $this->actingAs($user)
        ->postJson("/api/rooms/{$room->id}/messages", [
            'body' => '',
        ])
        ->assertUnprocessable()
        ->assertJsonPath('message', 'The body field is required.')
        ->assertJsonPath('errors.body.0', 'The body field is required.');
});

it('rejects soft deleted parent messages through chat service validation', function () {
    Event::fake([MessageSent::class]);
    [$user, $room] = messageControllerMemberRoom();
    $parent = Message::create([
        'room_id' => $room->id,
        'user_id' => $user->id,
        'body' => 'Deleted parent.',
    ]);
    $parent->delete();

    $this->actingAs($user)
        ->postJson("/api/rooms/{$room->id}/messages", [
            'body' => 'Invalid deleted-parent reply.',
            'parent_id' => $parent->id,
        ])
        ->assertUnprocessable()
        ->assertJsonPath('message', 'The selected parent id is invalid.')
        ->assertJsonPath('errors.parent_id.0', 'The selected parent id is invalid.');
});

it('rejects replies deeper than the configured thread depth', function () {
    Event::fake([MessageSent::class]);
    [$user, $room] = messageControllerMemberRoom();
    $parentId = null;
    $maxThreadDepth = (int) config('chat.messages.max_thread_depth');

    foreach (range(1, $maxThreadDepth) as $number) {
        $message = Message::create([
            'room_id' => $room->id,
            'user_id' => $user->id,
            'body' => "Thread level {$number}.",
            'parent_id' => $parentId,
        ]);

        $parentId = $message->id;
    }

    $this->actingAs($user)
        ->postJson("/api/rooms/{$room->id}/messages", [
            'body' => 'Too deep.',
            'parent_id' => $parentId,
        ])
        ->assertUnprocessable()
        ->assertJsonPath('errors.parent_id.0', "Replies cannot be nested more than {$maxThreadDepth} levels deep.");
});

it('edits messages through the chat service for authors', function () {
    [$user, $room] = messageControllerMemberRoom();
    $message = Message::create([
        'room_id' => $room->id,
        'user_id' => $user->id,
        'body' => 'Before.',
        'created_at' => now()->subMinutes(5),
        'updated_at' => now()->subMinutes(5),
    ]);

    $this->actingAs($user)
        ->patchJson("/api/messages/{$message->id}", [
            'body' => 'After.',
        ])
        ->assertOk()
        ->assertJsonPath('data.body', 'After.');

    expect($message->fresh()->edited_at)->not->toBeNull();
});

it('deletes messages through the chat service and masks the response body', function () {
    [$user, $room] = messageControllerMemberRoom();
    $message = Message::create([
        'room_id' => $room->id,
        'user_id' => $user->id,
        'body' => 'Secret.',
    ]);

    $this->actingAs($user)
        ->deleteJson("/api/messages/{$message->id}")
        ->assertOk()
        ->assertJsonPath('data.body', '[message deleted]');

    $this->assertSoftDeleted('messages', [
        'id' => $message->id,
    ]);
});

it('searches room messages from the last 30 days and uses the configured page size', function () {
    [$user, $room] = messageControllerMemberRoom();
    $author = User::factory()->create(['name' => 'Scout']);
    [, $otherRoom] = messageControllerMemberRoom();
    $pageSize = (int) config('chat.messages.index_page_size');

    foreach (range(1, $pageSize + 1) as $number) {
        $message = Message::create([
            'room_id' => $room->id,
            'user_id' => $author->id,
            'body' => "raid search {$number}",
        ]);
        $message->forceFill([
            'created_at' => now()->subDays(2)->addSeconds($number),
            'updated_at' => now()->subDays(2)->addSeconds($number),
        ])->save();
    }

    $oldMessage = Message::create([
        'room_id' => $room->id,
        'user_id' => $author->id,
        'body' => 'raid search too old',
    ]);
    $oldMessage->forceFill([
        'created_at' => now()->subDays(31),
        'updated_at' => now()->subDays(31),
    ])->save();
    Message::create([
        'room_id' => $room->id,
        'user_id' => $author->id,
        'body' => 'different topic',
    ]);
    Message::create([
        'room_id' => $otherRoom->id,
        'user_id' => $author->id,
        'body' => 'raid search other room',
    ]);

    $response = $this->actingAs($user)
        ->getJson("/api/rooms/{$room->id}/messages?search=raid")
        ->assertOk()
        ->assertJsonPath('meta.per_page', $pageSize)
        ->assertJsonPath('meta.total', $pageSize + 1);
    $latestSearchMessageBody = 'raid search '.($pageSize + 1);

    expect($response->json('data'))->toHaveCount($pageSize)
        ->and(collect($response->json('data'))->pluck('body')->all())
        ->toContain($latestSearchMessageBody)
        ->not->toContain('raid search too old')
        ->not->toContain('different topic')
        ->not->toContain('raid search other room');
});

it('stores the latest read message for authenticated guild members', function () {
    [$user, $room] = messageControllerMemberRoom();
    $author = User::factory()->create();
    $messages = collect(range(1, 3))->map(fn (int $number) => Message::create([
        'room_id' => $room->id,
        'user_id' => $author->id,
        'body' => "Message {$number}",
    ]));
    $latestMessage = $messages->last();

    $this->actingAs($user)
        ->postJson("/api/rooms/{$room->id}/read")
        ->assertOk()
        ->assertJsonPath('latest_read_message_id', $latestMessage->id)
        ->assertJsonStructure(['read_at']);

    $this->assertDatabaseHas('message_reads', [
        'message_id' => $latestMessage->id,
        'user_id' => $user->id,
    ]);
    expect(MessageRead::where('user_id', $user->id)->count())->toBe(1);
});

it('updates existing latest read receipts instead of duplicating them', function () {
    [$user, $room] = messageControllerMemberRoom();
    $author = User::factory()->create();
    $message = Message::create([
        'room_id' => $room->id,
        'user_id' => $author->id,
        'body' => 'Message.',
    ]);
    MessageRead::create([
        'message_id' => $message->id,
        'user_id' => $user->id,
        'read_at' => now()->subHour(),
    ]);

    $this->actingAs($user)
        ->postJson("/api/rooms/{$room->id}/read")
        ->assertOk();

    expect(MessageRead::where('message_id', $message->id)
        ->where('user_id', $user->id)
        ->count())->toBe(1);
});

it('returns a null latest read message id for empty rooms', function () {
    [$user, $room] = messageControllerMemberRoom();

    $this->actingAs($user)
        ->postJson("/api/rooms/{$room->id}/read")
        ->assertOk()
        ->assertJsonPath('latest_read_message_id', null)
        ->assertJsonStructure(['read_at']);

    expect(MessageRead::count())->toBe(0);
});

it('broadcasts typing indicators without persisting anything', function () {
    Event::fake([UserTyping::class]);
    [$user, $room] = messageControllerMemberRoom();

    $this->actingAs($user)
        ->postJson("/api/rooms/{$room->id}/typing")
        ->assertAccepted();

    Event::assertDispatched(UserTyping::class, fn (UserTyping $event) => $event->room->is($room)
        && $event->user->is($user));
    expect(Message::count())->toBe(0)
        ->and(MessageRead::count())->toBe(0);
});

it('rate limits typing indicators per user and room', function () {
    Event::fake([UserTyping::class]);
    [$user, $room] = messageControllerMemberRoom();

    $this->actingAs($user)
        ->postJson("/api/rooms/{$room->id}/typing")
        ->assertAccepted();

    $this->actingAs($user)
        ->postJson("/api/rooms/{$room->id}/typing")
        ->assertTooManyRequests()
        ->assertExactJson([
            'message' => 'You are sending typing indicators too quickly.',
            'error' => 'too_many_typing_events',
        ]);

    Event::assertDispatched(UserTyping::class, 1);
});

it('rejects message endpoints for users outside the guild', function () {
    $outsider = User::factory()->create();
    [, $room] = messageControllerMemberRoom();
    $message = Message::create([
        'room_id' => $room->id,
        'user_id' => User::factory()->create()->id,
        'body' => 'Protected.',
    ]);

    $this->actingAs($outsider)->getJson("/api/rooms/{$room->id}/messages")->assertForbidden();
    $this->actingAs($outsider)->postJson("/api/rooms/{$room->id}/messages", ['body' => 'Nope.'])->assertForbidden();
    $this->actingAs($outsider)->postJson("/api/rooms/{$room->id}/read")->assertForbidden();
    $this->actingAs($outsider)->postJson("/api/rooms/{$room->id}/typing")->assertForbidden();
    $this->actingAs($outsider)->patchJson("/api/messages/{$message->id}", ['body' => 'Nope.'])->assertForbidden();
    $this->actingAs($outsider)->deleteJson("/api/messages/{$message->id}")->assertForbidden();
});

function messageControllerMemberRoom(bool $leader = false): array
{
    $user = User::factory()->create(['name' => 'Nate']);
    $guild = Guild::create(['name' => 'Raid Guild']);
    $room = Room::create([
        'guild_id' => $guild->id,
        'name' => 'general',
    ]);

    GuildMember::create([
        'guild_id' => $guild->id,
        'user_id' => $user->id,
        'is_leader' => $leader,
    ]);

    return [$user, $room, $guild];
}
