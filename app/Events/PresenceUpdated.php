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

class PresenceUpdated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 5;

    public int $maxExceptions = 3;

    public function __construct(
        public Room $room,
        public User $user,
        public string $status,
        public array $onlineMembers,
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
        return 'presence.updated';
    }

    public function broadcastWith(): array
    {
        return [
            'room_id' => $this->room->id,
            'user' => [
                'id' => $this->user->id,
                'name' => $this->user->name,
            ],
            'status' => $this->status,
            'online_members' => $this->onlineMembers,
        ];
    }
}
