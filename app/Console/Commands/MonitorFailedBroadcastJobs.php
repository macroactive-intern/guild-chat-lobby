<?php

namespace App\Console\Commands;

use App\Events\MessageDeleted;
use App\Events\MessageEdited;
use App\Events\MessageSent;
use App\Events\PresenceUpdated;
use App\Events\ReactionAdded;
use App\Events\RoomStatusUpdated;
use App\Events\UserTyping;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class MonitorFailedBroadcastJobs extends Command
{
    protected $signature = 'chat:broadcast-failures
        {--threshold=1 : Failed broadcast job count required before alerting}';

    protected $description = 'Monitor failed broadcast jobs and alert when any are found.';

    public function handle(): int
    {
        $threshold = max(1, (int) $this->option('threshold'));
        $failures = $this->failedBroadcastJobs();
        $count = $failures->count();

        if ($count < $threshold) {
            $this->info("Failed broadcast jobs: {$count}");

            return self::SUCCESS;
        }

        Log::critical('Failed broadcast jobs detected.', [
            'count' => $count,
            'threshold' => $threshold,
            'failed_job_ids' => $failures->pluck('id')->all(),
            'queues' => $failures->pluck('queue')->unique()->values()->all(),
        ]);

        $this->error("Failed broadcast jobs: {$count}");

        return self::FAILURE;
    }

    private function failedBroadcastJobs()
    {
        $broadcastEvents = [
            MessageDeleted::class,
            MessageEdited::class,
            MessageSent::class,
            PresenceUpdated::class,
            ReactionAdded::class,
            RoomStatusUpdated::class,
            UserTyping::class,
        ];

        return DB::table('failed_jobs')
            ->where(function ($query) use ($broadcastEvents): void {
                $query->where('payload', 'like', '%Illuminate\\\\Broadcasting\\\\BroadcastEvent%');

                foreach ($broadcastEvents as $event) {
                    $query->orWhere('payload', 'like', '%'.str_replace('\\', '\\\\', $event).'%');
                }
            })
            ->orderBy('id')
            ->get(['id', 'queue', 'payload', 'failed_at']);
    }
}
