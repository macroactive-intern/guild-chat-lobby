<?php

namespace Tests\Feature;

use App\Models\Guild;
use App\Models\GuildMember;
use App\Models\Message;
use App\Models\Room;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GuildAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_membership_helpers_check_members_and_leaders(): void
    {
        $leader = User::factory()->create();
        $member = User::factory()->create();
        $outsider = User::factory()->create();
        $guild = Guild::create(['name' => 'Raid Guild']);

        GuildMember::create([
            'guild_id' => $guild->id,
            'user_id' => $leader->id,
            'is_leader' => true,
        ]);
        GuildMember::create([
            'guild_id' => $guild->id,
            'user_id' => $member->id,
        ]);

        $this->assertTrue($leader->isMemberOfGuild($guild->id));
        $this->assertTrue($leader->isLeaderOfGuild($guild->id));
        $this->assertTrue($member->isMemberOfGuild($guild->id));
        $this->assertFalse($member->isLeaderOfGuild($guild->id));
        $this->assertFalse($outsider->isMemberOfGuild($guild->id));
        $this->assertFalse($outsider->isLeaderOfGuild($guild->id));
    }

    public function test_guild_and_room_policies_authorize_members_and_leaders(): void
    {
        [$leader, $member, $outsider, $guild, $room] = $this->guildWithUsers();

        $this->assertTrue($leader->can('view', $guild));
        $this->assertTrue($member->can('view', $guild));
        $this->assertFalse($outsider->can('view', $guild));

        $this->assertTrue($leader->can('createRoom', $guild));
        $this->assertFalse($member->can('createRoom', $guild));
        $this->assertFalse($outsider->can('createRoom', $guild));

        $this->assertTrue($leader->can('view', $room));
        $this->assertTrue($member->can('view', $room));
        $this->assertFalse($outsider->can('view', $room));

        $this->assertTrue($leader->can('sendMessage', $room));
        $this->assertTrue($member->can('sendMessage', $room));
        $this->assertFalse($outsider->can('sendMessage', $room));
    }

    public function test_message_policy_authorizes_owners_and_guild_leaders(): void
    {
        [$leader, $member, $outsider, , $room] = $this->guildWithUsers();

        $memberMessage = Message::create([
            'room_id' => $room->id,
            'user_id' => $member->id,
            'body' => 'Need backup.',
        ]);
        $leaderMessage = Message::create([
            'room_id' => $room->id,
            'user_id' => $leader->id,
            'body' => 'On my way.',
        ]);

        $this->assertTrue($member->can('update', $memberMessage));
        $this->assertTrue($member->can('delete', $memberMessage));
        $this->assertFalse($member->can('update', $leaderMessage));
        $this->assertFalse($member->can('delete', $leaderMessage));

        $this->assertFalse($leader->can('update', $memberMessage));
        $this->assertTrue($leader->can('delete', $memberMessage));

        $this->assertFalse($outsider->can('update', $memberMessage));
        $this->assertFalse($outsider->can('delete', $memberMessage));
    }

    private function guildWithUsers(): array
    {
        $leader = User::factory()->create();
        $member = User::factory()->create();
        $outsider = User::factory()->create();
        $guild = Guild::create(['name' => 'Raid Guild']);
        $room = Room::create([
            'guild_id' => $guild->id,
            'name' => 'general',
        ]);

        GuildMember::create([
            'guild_id' => $guild->id,
            'user_id' => $leader->id,
            'is_leader' => true,
        ]);
        GuildMember::create([
            'guild_id' => $guild->id,
            'user_id' => $member->id,
        ]);

        return [$leader, $member, $outsider, $guild, $room];
    }
}
