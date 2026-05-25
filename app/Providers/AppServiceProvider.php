<?php

namespace App\Providers;

use App\Models\Guild;
use App\Models\Message;
use App\Models\Room;
use App\Policies\GuildPolicy;
use App\Policies\MessagePolicy;
use App\Policies\RoomPolicy;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Gate;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::policy(Guild::class, GuildPolicy::class);
        Gate::policy(Room::class, RoomPolicy::class);
        Gate::policy(Message::class, MessagePolicy::class);
    }
}
