<?php

use App\Events\MessageSent;
use App\Exceptions\TooManyMessagesException;
use App\Http\Resources\MessageResource;
use App\Models\Guild;
use App\Models\GuildMember;
use App\Models\Message;
use App\Models\Room;
use App\Models\User;
use App\Services\ChatService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
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

it('rejects sending messages to archived rooms', function () {
    Event::fake([MessageSent::class]);

    [$user, $room] = chatServiceRoomWithUser(['is_archived' => true]);

    app(ChatService::class)->send($user, $room, [
        'body' => 'Can anyone hear me?',
    ]);
})->throws(ValidationException::class);

it('edits author messages within ten minutes and sets edited timestamp', function () {
    [$user, $room] = chatServiceRoomWithUser();
    $message = Message::create([
        'room_id' => $room->id,
        'user_id' => $user->id,
        'body' => 'Before.',
    ]);
    $message->forceFill([
        'created_at' => now()->subMinutes(9),
        'updated_at' => now()->subMinutes(9),
    ])->save();

    $edited = app(ChatService::class)->edit($message, $user, [
        'body' => 'After.',
    ]);

    expect($edited->body)->toBe('After.')
        ->and($edited->edited_at)->not->toBeNull();
});

it('rejects edits from non authors', function () {
    [$user, $room] = chatServiceRoomWithUser();
    $otherUser = User::factory()->create();
    $message = Message::create([
        'room_id' => $room->id,
        'user_id' => $user->id,
        'body' => 'Original.',
    ]);

    app(ChatService::class)->edit($message, $otherUser, [
        'body' => 'Nope.',
    ]);
})->throws(AuthorizationException::class);

it('rejects edits after ten minutes', function () {
    [$user, $room] = chatServiceRoomWithUser();
    $message = Message::create([
        'room_id' => $room->id,
        'user_id' => $user->id,
        'body' => 'Original.',
    ]);
    $message->forceFill([
        'created_at' => now()->subMinutes(11),
        'updated_at' => now()->subMinutes(11),
    ])->save();

    app(ChatService::class)->edit($message, $user, [
        'body' => 'Too late.',
    ]);
})->throws(AuthorizationException::class);

it('soft deletes messages by author and masks resource output', function () {
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

it('rejects deletes from non authors who are not guild leaders', function () {
    [$author, $room] = chatServiceRoomWithUser();
    $outsider = User::factory()->create();
    $message = Message::create([
        'room_id' => $room->id,
        'user_id' => $author->id,
        'body' => 'Protected.',
    ]);

    app(ChatService::class)->delete($message, $outsider);
})->throws(AuthorizationException::class);

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
