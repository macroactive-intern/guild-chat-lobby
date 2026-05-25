<?php

namespace App\Policies;

use App\Models\Room;
use App\Models\User;

class RoomPolicy
{
    public function view(User $user, Room $room): bool
    {
        return $user->isMemberOfGuild($room->guild_id);
    }

    public function sendMessage(User $user, Room $room): bool
    {
        return $user->isMemberOfGuild($room->guild_id);
    }

    public function archive(User $user, Room $room): bool
    {
        return $user->isLeaderOfGuild($room->guild_id);
    }
}
