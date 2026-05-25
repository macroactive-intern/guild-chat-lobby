<?php

use App\Models\Room;
use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

Broadcast::channel('guild.{guildId}.room.{roomId}', function (User $user, int $guildId, int $roomId) {
    $roomBelongsToGuild = Room::query()
        ->whereKey($roomId)
        ->where('guild_id', $guildId)
        ->exists();

    if (! $roomBelongsToGuild || ! $user->isMemberOfGuild($guildId)) {
        return false;
    }

    return [
        'id' => $user->id,
        'name' => $user->name,
    ];
});
