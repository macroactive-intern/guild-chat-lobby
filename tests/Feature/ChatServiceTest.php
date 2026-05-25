<?php

use App\Events\MessageDeleted;
use App\Events\MessageEdited;
use App\Events\MessageSent;
use App\Exceptions\ArchivedRoomException;
use App\Exceptions\MessageEditExpiredException;
use App\Exceptions\TooManyMessagesException;
use App\Http\Resources\MessageResource;
use App\Models\Guild;
use App\Models\GuildMember;
use App\Models\Message;
use App\Models\Room;
use App\Models\User;
use App\Services\ChatService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    Cache::flush();
});

it('persists and broadcasts sent messages', function () {
    Event::fake([MessageSent::class]);

    [$user, $room] = chatServiceRoomWithUser();

    $message = app(ChatService::class)->send($user, $room, [
        'body' => 'Raid starts now.',
    ]);

    expect($message->exists)->toBeTrue()
        ->and($message->room_id)->toBe($room->id)
        ->and($message->user_id)->toBe($user->id)
        ->and($message->body)->toBe('Raid starts now.')
        ->and($message->relationLoaded('user'))->toBeTrue()
        ->and($message->relationLoaded('replies'))->toBeTrue();

    $this->assertDatabaseHas('messages', [
        'id' => $message->id,
        'body' => 'Raid starts now.',
    ]);

    Event::assertDispatched(MessageSent::class, fn (MessageSent $event) => $event->message->is($message));
});

it('rate limits sent messages per user and room', function () {
    Event::fake([MessageSent::class]);

    [$user, $room] = chatServiceRoomWithUser();

    app(ChatService::class)->send($user, $room, [
        'body' => 'First message.',
    ]);

    app(ChatService::class)->send($user, $room, [
        'body' => 'Second message.',
    ]);
})->throws(TooManyMessagesException::class);

it('checks send rate limits before validation', function () {
    Event::fake([MessageSent::class]);

    [$user, $room] = chatServiceRoomWithUser(['is_archived' => true]);
    $lock = Cache::lock("chat-rate.{$user->id}.{$room->id}", 1);
    $lock->get();

    app(ChatService::class)->send($user, $room, [
        'body' => 'This should hit the lock before archived validation.',
    ]);
})->throws(TooManyMessagesException::class);

it('allows simultaneous messages in different rooms', function () {
    Event::fake([MessageSent::class]);

    [$user, $room] = chatServiceRoomWithUser();
    $otherRoom = Room::create([
        'guild_id' => $room->guild_id,
        'name' => 'strategy',
    ]);

    app(ChatService::class)->send($user, $room, [
        'body' => 'General.',
    ]);
    $message = app(ChatService::class)->send($user, $otherRoom, [
        'body' => 'Strategy.',
    ]);

    expect($message->room_id)->toBe($otherRoom->id);
});

it('validates reply parent messages belong to the same room', function () {
    Event::fake([MessageSent::class]);

    [$user, $room] = chatServiceRoomWithUser();
    [, $otherRoom] = chatServiceRoomWithUser();
    $otherMessage = Message::create([
        'room_id' => $otherRoom->id,
        'user_id' => $user->id,
        'body' => 'Different room.',
    ]);

    app(ChatService::class)->send($user, $room, [
        'body' => 'Invalid reply.',
        'parent_id' => $otherMessage->id,
    ]);
})->throws(ValidationException::class);

it('rejects soft deleted parent messages', function () {
    Event::fake([MessageSent::class]);

    [$user, $room] = chatServiceRoomWithUser();
    $parent = Message::create([
        'room_id' => $room->id,
        'user_id' => $user->id,
        'body' => 'Deleted parent.',
    ]);
    $parent->delete();

    app(ChatService::class)->send($user, $room, [
        'body' => 'Invalid deleted-parent reply.',
        'parent_id' => $parent->id,
    ]);
})->throws(ValidationException::class);

it('rejects replies deeper than the configured thread depth', function () {
    Event::fake([MessageSent::class]);

    [$user, $room] = chatServiceRoomWithUser();
    $parentId = null;

    foreach (range(1, (int) config('chat.messages.max_thread_depth')) as $number) {
        $message = Message::create([
            'room_id' => $room->id,
            'user_id' => $user->id,
            'body' => "Thread level {$number}.",
            'parent_id' => $parentId,
        ]);

        $parentId = $message->id;
    }

    app(ChatService::class)->send($user, $room, [
        'body' => 'Too deep.',
        'parent_id' => $parentId,
    ]);
})->throws(ValidationException::class);

it('rejects sending messages to archived rooms', function () {
    Event::fake([MessageSent::class]);

    [$user, $room] = chatServiceRoomWithUser(['is_archived' => true]);

    app(ChatService::class)->send($user, $room, [
        'body' => 'Can anyone hear me?',
    ]);
})->throws(ArchivedRoomException::class);

it('edits author messages within the configured edit window and sets edited timestamp', function () {
    Event::fake([MessageEdited::class]);

    [$user, $room] = chatServiceRoomWithUser();
    $editWindowMinutes = (int) config('chat.messages.edit_window_minutes');
    $message = Message::create([
        'room_id' => $room->id,
        'user_id' => $user->id,
        'body' => 'Before.',
    ]);
    $message->forceFill([
        'created_at' => now()->subMinutes($editWindowMinutes - 1),
        'updated_at' => now()->subMinutes($editWindowMinutes - 1),
    ])->save();

    $edited = app(ChatService::class)->edit($message, $user, [
        'body' => 'After.',
    ]);

    expect($edited->body)->toBe('After.')
        ->and($edited->edited_at)->not->toBeNull();

    Event::assertDispatched(MessageEdited::class, fn (MessageEdited $event) => $event->message->is($edited));
});

it('rejects edits after the configured edit window', function () {
    [$user, $room] = chatServiceRoomWithUser();
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

    app(ChatService::class)->edit($message, $user, [
        'body' => 'Too late.',
    ]);
})->throws(MessageEditExpiredException::class);

it('returns API friendly JSON for chat domain exceptions', function () {
    Route::get('/test/exceptions/too-many-messages', fn () => throw new TooManyMessagesException())
        ->middleware('api');
    Route::get('/test/exceptions/message-edit-expired', fn () => throw new MessageEditExpiredException())
        ->middleware('api');
    Route::get('/test/exceptions/archived-room', fn () => throw new ArchivedRoomException())
        ->middleware('api');

    $this->getJson('/test/exceptions/too-many-messages')
        ->assertTooManyRequests()
        ->assertHeader('Retry-After', (string) config('chat.messages.rate_limit_seconds'))
        ->assertExactJson([
            'message' => 'You are sending messages too quickly.',
            'error' => 'too_many_messages',
        ]);

    $this->getJson('/test/exceptions/message-edit-expired')
        ->assertForbidden()
        ->assertExactJson([
            'message' => sprintf(
                'Messages can only be edited for %d minutes after creation.',
                (int) config('chat.messages.edit_window_minutes'),
            ),
            'error' => 'message_edit_expired',
        ]);

    $this->getJson('/test/exceptions/archived-room')
        ->assertConflict()
        ->assertExactJson([
            'message' => 'Archived rooms cannot receive new messages.',
            'error' => 'archived_room',
        ]);
});

it('soft deletes messages by author and masks resource output', function () {
    Event::fake([MessageDeleted::class]);

    [$user, $room] = chatServiceRoomWithUser();
    $message = Message::create([
        'room_id' => $room->id,
        'user_id' => $user->id,
        'body' => 'Original secret.',
    ]);

    $deleted = app(ChatService::class)->delete($message, $user);
    $payload = json_decode((new MessageResource($deleted))->toJson(), true);

    expect($deleted->trashed())->toBeTrue()
        ->and($payload['body'])->toBe('[message deleted]');

    Event::assertDispatched(MessageDeleted::class, fn (MessageDeleted $event) => $event->message->is($deleted));
});

it('soft deletes any guild message by leader', function () {
    [$author, $room, $leader] = chatServiceRoomWithUser(leader: true);
    $message = Message::create([
        'room_id' => $room->id,
        'user_id' => $author->id,
        'body' => 'Delete me.',
    ]);

    $deleted = app(ChatService::class)->delete($message, $leader);

    expect($deleted->trashed())->toBeTrue();
});

function chatServiceRoomWithUser(array $roomAttributes = [], bool $leader = false): array
{
    $user = User::factory()->create();
    $guild = Guild::create(['name' => 'Raid Guild']);
    $room = Room::create(array_merge([
        'guild_id' => $guild->id,
        'name' => 'general',
    ], $roomAttributes));

    GuildMember::create([
        'guild_id' => $guild->id,
        'user_id' => $user->id,
    ]);

    if (! $leader) {
        return [$user, $room];
    }

    $leaderUser = User::factory()->create();

    GuildMember::create([
        'guild_id' => $guild->id,
        'user_id' => $leaderUser->id,
        'is_leader' => true,
    ]);

    return [$user, $room, $leaderUser];
}
