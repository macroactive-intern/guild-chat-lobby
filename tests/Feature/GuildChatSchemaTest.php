<?php

namespace Tests\Feature;

use App\Models\Guild;
use App\Models\GuildMember;
use App\Models\Message;
use App\Models\MessageRead;
use App\Models\Room;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GuildChatSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_guild_chat_relationships_and_message_soft_deletes_work(): void
    {
        $user = User::factory()->create();
        $guild = Guild::create(['name' => 'Night Watch']);
        $room = Room::create([
            'guild_id' => $guild->id,
            'name' => 'general',
            'description' => 'Default guild chat room.',
        ]);
        $parent = Message::create([
            'room_id' => $room->id,
            'user_id' => $user->id,
            'body' => 'Raid starts soon.',
        ]);
        $reply = Message::create([
            'room_id' => $room->id,
            'user_id' => $user->id,
            'parent_id' => $parent->id,
            'body' => 'Ready.',
            'edited_at' => now(),
        ]);
        $read = MessageRead::create([
            'message_id' => $reply->id,
            'user_id' => $user->id,
            'read_at' => now(),
        ]);

        GuildMember::create([
            'guild_id' => $guild->id,
            'user_id' => $user->id,
        ]);

        $this->assertTrue($guild->rooms->first()->is($room));
        $this->assertTrue($room->guild->is($guild));
        $this->assertTrue($room->messages->contains($parent));
        $this->assertTrue($parent->user->is($user));
        $this->assertTrue($parent->room->is($room));
        $this->assertTrue($reply->parent->is($parent));
        $this->assertTrue($parent->replies->first()->is($reply));
        $this->assertTrue($read->message->is($reply));
        $this->assertTrue($read->user->is($user));

        $reply->delete();

        $this->assertSoftDeleted('messages', [
            'id' => $reply->id,
        ]);
    }
}
