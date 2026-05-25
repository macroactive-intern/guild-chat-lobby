<?php

namespace App\Policies;

use App\Models\Message;
use App\Models\User;

class MessagePolicy
{
    public function view(User $user, Message $message): bool
    {
        return $user->isMemberOfGuild($this->messageGuildId($message));
    }

    public function react(User $user, Message $message): bool
    {
        return $user->isMemberOfGuild($this->messageGuildId($message));
    }

    public function update(User $user, Message $message): bool
    {
        return $message->user_id === $user->id
            && $this->isWithinEditWindow($message)
            && $user->isMemberOfGuild($this->messageGuildId($message));
    }

    public function delete(User $user, Message $message): bool
    {
        $guildId = $this->messageGuildId($message);

        return $user->isLeaderOfGuild($guildId)
            || (
                $message->user_id === $user->id
                && $user->isMemberOfGuild($guildId)
            );
    }

    private function messageGuildId(Message $message): mixed
    {
        return $message->room?->guild_id
            ?? $message->room()->value('guild_id');
    }

    private function isWithinEditWindow(Message $message): bool
    {
        return $message->created_at?->copy()
            ->addMinutes((int) config('chat.messages.edit_window_minutes'))
            ->isFuture() ?? false;
    }
}
