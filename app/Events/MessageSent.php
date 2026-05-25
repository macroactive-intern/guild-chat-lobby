<?php

namespace App\Events;

use App\Models\Message;
use Illuminate\Bus\Queueable;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class MessageSent implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 5;

    public int $maxExceptions = 3;

    public function __construct(public Message $message)
    {
        $this->message->loadMissing(['room', 'user']);
    }

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel(
            "guild.{$this->message->room->guild_id}.room.{$this->message->room_id}"
        );
    }

    public function broadcastAs(): string
    {
        return 'message.sent';
    }

    public function broadcastWith(): array
    {
        return [
            'id' => $this->message->id,
            'body' => $this->message->trashed() ? '[message deleted]' : $this->message->body,
            'user' => [
                'id' => $this->message->user->id,
                'name' => $this->message->user->name,
            ],
            'parent_id' => $this->message->parent_id,
            'edited_at' => $this->message->edited_at,
            'is_deleted' => $this->message->trashed(),
            'created_at' => $this->message->created_at,
        ];
    }
}
