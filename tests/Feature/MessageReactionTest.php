<?php

use App\Events\ReactionAdded;
use App\Models\Guild;
use App\Models\GuildMember;
use App\Models\Message;
use App\Models\MessageReaction;
use App\Models\Room;
use App\Models\User;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Support\Facades\Event;

it('stores one emoji reaction per user per message and broadcasts new reactions', function () {
    Event::fake([ReactionAdded::class]);
    [$user, $message] = messageReactionMemberMessage();

    $this->actingAs($user)
        ->postJson("/api/messages/{$message->id}/reactions", [
            'emoji' => ':fire:',
        ])
        ->assertCreated()
        ->assertJsonPath('message_id', $message->id)
        ->assertJsonPath('user_id', $user->id)
        ->assertJsonPath('emoji', ':fire:');

    $this->assertDatabaseHas('message_reactions', [
        'message_id' => $message->id,
        'user_id' => $user->id,
        'emoji' => ':fire:',
    ]);
    Event::assertDispatched(ReactionAdded::class);

    $this->actingAs($user)
        ->postJson("/api/messages/{$message->id}/reactions", [
            'emoji' => ':fire:',
        ])
        ->assertOk();

    expect(MessageReaction::count())->toBe(1);
    Event::assertDispatched(ReactionAdded::class, 1);
});

it('allows the same user to add different emoji reactions to a message', function () {
    [$user, $message] = messageReactionMemberMessage();

    $this->actingAs($user)
        ->postJson("/api/messages/{$message->id}/reactions", [
            'emoji' => ':fire:',
        ])
        ->assertCreated();
    $this->actingAs($user)
        ->postJson("/api/messages/{$message->id}/reactions", [
            'emoji' => ':heart:',
        ])
        ->assertCreated();

    expect($message->reactions()->count())->toBe(2);
});

it('deletes a user reaction without deleting other users reactions', function () {
    [$user, $message, $guild] = messageReactionMemberMessage();
    $otherUser = User::factory()->create();
    GuildMember::create([
        'guild_id' => $guild->id,
        'user_id' => $otherUser->id,
    ]);

    MessageReaction::create([
        'message_id' => $message->id,
        'user_id' => $user->id,
        'emoji' => ':fire:',
    ]);
    MessageReaction::create([
        'message_id' => $message->id,
        'user_id' => $otherUser->id,
        'emoji' => ':fire:',
    ]);

    $this->actingAs($user)
        ->deleteJson("/api/messages/{$message->id}/reactions", [
            'emoji' => ':fire:',
        ])
        ->assertNoContent();

    $this->assertDatabaseMissing('message_reactions', [
        'message_id' => $message->id,
        'user_id' => $user->id,
        'emoji' => ':fire:',
    ]);
    $this->assertDatabaseHas('message_reactions', [
        'message_id' => $message->id,
        'user_id' => $otherUser->id,
        'emoji' => ':fire:',
    ]);
});

it('rejects message reactions from users outside the guild', function () {
    $outsider = User::factory()->create();
    [, $message] = messageReactionMemberMessage();

    $this->actingAs($outsider)
        ->postJson("/api/messages/{$message->id}/reactions", [
            'emoji' => ':fire:',
        ])
        ->assertForbidden();

    $this->actingAs($outsider)
        ->deleteJson("/api/messages/{$message->id}/reactions", [
            'emoji' => ':fire:',
        ])
        ->assertForbidden();
});

it('validates emoji reaction payloads', function () {
    [$user, $message] = messageReactionMemberMessage();

    $this->actingAs($user)
        ->postJson("/api/messages/{$message->id}/reactions", [
            'emoji' => '',
        ])
        ->assertUnprocessable()
        ->assertJsonPath('errors.emoji.0', 'The emoji field is required.');
});

it('broadcasts reaction added events to the private room channel', function () {
    [$user, $message] = messageReactionMemberMessage();
    $reaction = MessageReaction::create([
        'message_id' => $message->id,
        'user_id' => $user->id,
        'emoji' => ':fire:',
    ]);

    $event = new ReactionAdded($reaction);
    $channel = $event->broadcastOn();
    $payload = $event->broadcastWith();

    expect($event)->toBeInstanceOf(ShouldBroadcast::class)
        ->and($event->broadcastAs())->toBe('reaction.added')
        ->and($channel->name)->toBe("private-guild.{$message->room->guild_id}.room.{$message->room_id}")
        ->and($payload['message_id'])->toBe($message->id)
        ->and($payload['emoji'])->toBe(':fire:')
        ->and($payload['user'])->toBe([
            'id' => $user->id,
            'name' => $user->name,
        ])
        ->and($payload['created_at']->equalTo($reaction->created_at))->toBeTrue();
});

function messageReactionMemberMessage(): array
{
    $user = User::factory()->create(['name' => 'Nate']);
    $guild = Guild::create(['name' => 'Raid Guild']);
    $room = Room::create([
        'guild_id' => $guild->id,
        'name' => 'general',
    ]);
    $message = Message::create([
        'room_id' => $room->id,
        'user_id' => $user->id,
        'body' => 'React to this.',
    ]);

    GuildMember::create([
        'guild_id' => $guild->id,
        'user_id' => $user->id,
    ]);

    return [$user, $message, $guild];
}
