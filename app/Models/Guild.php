<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Guild extends Model
{
    protected $fillable = [
        'name',
    ];

    public function rooms(): HasMany
    {
        return $this->hasMany(Room::class);
    }

    public function members(): HasMany
    {
        return $this->hasMany(GuildMember::class);
    }
}
