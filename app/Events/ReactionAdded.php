<?php

namespace App\Events;

use App\Models\MessageReaction;
use Illuminate\Bus\Queueable;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ReactionAdded implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 5;

    public int $maxExceptions = 3;

    public function __construct(public MessageReaction $reaction)
    {
        $this->reaction->loadMissing(['message.room', 'user']);
    }

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel(
            "guild.{$this->reaction->message->room->guild_id}.room.{$this->reaction->message->room_id}"
        );
    }

    public function broadcastAs(): string
    {
        return 'reaction.added';
    }

    public function broadcastWith(): array
    {
        return [
            'message_id' => $this->reaction->message_id,
            'emoji' => $this->reaction->emoji,
            'user' => [
                'id' => $this->reaction->user->id,
                'name' => $this->reaction->user->name,
            ],
            'created_at' => $this->reaction->created_at,
        ];
    }
}
