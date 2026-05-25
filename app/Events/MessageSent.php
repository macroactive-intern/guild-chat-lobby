<?php

namespace App\Events;

use App\Http\Resources\MessageResource;
use App\Models\Message;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class MessageSent implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public Message $message)
    {
        $this->message->loadMissing(['room', 'user', 'replies.user']);
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
            'message' => (new MessageResource($this->message))->resolve(),
        ];
    }
}
