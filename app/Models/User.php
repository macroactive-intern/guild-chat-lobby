<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * @var array<string, array{member: bool, leader: bool}>
     */
    private array $membershipCache = [];

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function guildMemberships(): HasMany
    {
        return $this->hasMany(GuildMember::class);
    }

    public function isMemberOfGuild($guildId): bool
    {
        return $this->guildMembershipFor($guildId)['member'];
    }

    public function isLeaderOfGuild($guildId): bool
    {
        return $this->guildMembershipFor($guildId)['leader'];
    }

    private function guildMembershipFor($guildId): array
    {
        $cacheKey = (string) $guildId;

        if (array_key_exists($cacheKey, $this->membershipCache)) {
            return $this->membershipCache[$cacheKey];
        }

        $membership = $this->guildMemberships()
            ->where('guild_id', $guildId)
            ->first(['is_leader']);

        return $this->membershipCache[$cacheKey] = [
            'member' => $membership !== null,
            'leader' => (bool) $membership?->is_leader,
        ];
    }
}
