<?php

namespace App\Events;

use App\Models\Room;
use Illuminate\Bus\Queueable;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class RoomStatusUpdated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 5;

    public int $maxExceptions = 3;

    public function __construct(public Room $room)
    {
        //
    }

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel(
            "guild.{$this->room->guild_id}.room.{$this->room->id}"
        );
    }

    public function broadcastAs(): string
    {
        return 'room.status.updated';
    }

    public function broadcastWith(): array
    {
        return [
            'id' => $this->room->id,
            'guild_id' => $this->room->guild_id,
            'is_archived' => $this->room->is_archived,
            'updated_at' => $this->room->updated_at,
        ];
    }
}
