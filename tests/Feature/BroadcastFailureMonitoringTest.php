<?php

use App\Events\MessageSent;
use App\Events\ReactionAdded;
use App\Events\RoomStatusUpdated;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

it('alerts when failed broadcast jobs are present', function () {
    Log::spy();

    DB::table('failed_jobs')->insert([
        'uuid' => (string) Str::uuid(),
        'connection' => 'database',
        'queue' => 'default',
        'payload' => json_encode([
            'job' => 'Illuminate\\Broadcasting\\BroadcastEvent',
            'displayName' => MessageSent::class,
        ]),
        'exception' => 'Broadcast connection failed.',
        'failed_at' => now(),
    ]);

    $this->artisan('chat:broadcast-failures')
        ->expectsOutput('Failed broadcast jobs: 1')
        ->assertFailed();

    Log::shouldHaveReceived('critical')
        ->once()
        ->with('Failed broadcast jobs detected.', \Mockery::on(fn (array $context): bool => $context['count'] === 1
            && $context['threshold'] === 1
            && $context['queues'] === ['default']));
});

it('does not alert when failed jobs are not broadcast jobs', function () {
    Log::spy();

    DB::table('failed_jobs')->insert([
        'uuid' => (string) Str::uuid(),
        'connection' => 'database',
        'queue' => 'default',
        'payload' => json_encode([
            'job' => 'App\\Jobs\\SendDigestEmail',
            'displayName' => 'App\\Jobs\\SendDigestEmail',
        ]),
        'exception' => 'Email failed.',
        'failed_at' => now(),
    ]);

    $this->artisan('chat:broadcast-failures')
        ->expectsOutput('Failed broadcast jobs: 0')
        ->assertSuccessful();

    Log::shouldNotHaveReceived('critical');
});

it('configures queued broadcast events with retry metadata', function () {
    expect(new MessageSent(broadcastFailureMessage()))
        ->tries->toBe(3)
        ->backoff->toBe(5)
        ->maxExceptions->toBe(3)
        ->and(new ReactionAdded(broadcastFailureReaction()))
        ->tries->toBe(3)
        ->backoff->toBe(5)
        ->maxExceptions->toBe(3)
        ->and(new RoomStatusUpdated(broadcastFailureRoom()))
        ->tries->toBe(3)
        ->backoff->toBe(5)
        ->maxExceptions->toBe(3);
});

function broadcastFailureMessage(): App\Models\Message
{
    $user = App\Models\User::factory()->create();
    $guild = App\Models\Guild::create(['name' => 'Raid Guild']);
    $room = App\Models\Room::create([
        'guild_id' => $guild->id,
        'name' => 'general',
    ]);

    return App\Models\Message::create([
        'room_id' => $room->id,
        'user_id' => $user->id,
        'body' => 'Retry me.',
    ]);
}

function broadcastFailureReaction(): App\Models\MessageReaction
{
    $message = broadcastFailureMessage();

    return App\Models\MessageReaction::create([
        'message_id' => $message->id,
        'user_id' => $message->user_id,
        'emoji' => ':fire:',
    ]);
}

function broadcastFailureRoom(): App\Models\Room
{
    $guild = App\Models\Guild::create(['name' => 'Raid Guild']);

    return App\Models\Room::create([
        'guild_id' => $guild->id,
        'name' => 'general',
    ]);
}
