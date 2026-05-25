<?php

namespace App\Events;

use App\Models\Room;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class UserTyping implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, Queueable, SerializesModels;

    public function __construct(
        public User $user,
        public Room $room,
    ) {
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
        return 'UserTyping';
    }

    public function broadcastWith(): array
    {
        return [
            'room_id' => $this->room->id,
            'user_id' => $this->user->id,
            'user_name' => $this->user->name,
        ];
    }
}
