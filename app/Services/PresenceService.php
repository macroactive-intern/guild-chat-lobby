<?php

namespace App\Services;

use App\Models\Room;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

class PresenceService
{
    private const TTL_SECONDS = 30;

    public function markOnline(Room $room, User $user): void
    {
        $members = $this->freshMembers($room);

        $members[$user->id] = [
            'id' => $user->id,
            'name' => $user->name,
            'last_seen_at' => now()->timestamp,
        ];

        $this->putMembers($room, $members);
    }

    public function markOffline(Room $room, User $user): void
    {
        $members = $this->freshMembers($room);

        unset($members[$user->id]);

        if ($members === []) {
            Cache::forget($this->key($room));

            return;
        }

        $this->putMembers($room, $members);
    }

    public function onlineMembers(Room $room): Collection
    {
        $members = $this->freshMembers($room);

        if ($members === []) {
            Cache::forget($this->key($room));

            return collect();
        }

        $this->putMembers($room, $members);

        return collect($members)
            ->map(fn (array $member): array => [
                'id' => $member['id'],
                'name' => $member['name'],
            ])
            ->values();
    }

    private function freshMembers(Room $room): array
    {
        $expiresBefore = now()->subSeconds(self::TTL_SECONDS)->timestamp;

        return collect(Cache::get($this->key($room), []))
            ->filter(fn (array $member): bool => ($member['last_seen_at'] ?? 0) > $expiresBefore)
            ->all();
    }

    private function putMembers(Room $room, array $members): void
    {
        Cache::put($this->key($room), $members, now()->addSeconds(self::TTL_SECONDS));
    }

    private function key(Room $room): string
    {
        return "presence.room.{$room->id}";
    }
}
