<?php

namespace App\Policies;

use App\Models\Guild;
use App\Models\User;

class GuildPolicy
{
    public function view(User $user, Guild $guild): bool
    {
        return $user->isMemberOfGuild($guild->id);
    }

    public function createRoom(User $user, Guild $guild): bool
    {
        return $user->isLeaderOfGuild($guild->id);
    }
}
