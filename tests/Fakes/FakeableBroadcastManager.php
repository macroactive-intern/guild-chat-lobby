<?php

namespace Tests\Fakes;

use Illuminate\Broadcasting\BroadcastManager;

class FakeableBroadcastManager extends BroadcastManager
{
    private bool $faked = false;

    public function fake(): static
    {
        $this->faked = true;

        return $this;
    }

    public function isFaked(): bool
    {
        return $this->faked;
    }
}
