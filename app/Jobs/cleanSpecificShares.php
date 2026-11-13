<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use App\Models\Share;

class cleanSpecificShares implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new job instance.
     */
    public function __construct(public array $shareIds, public int $userId)
    {
        //
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        foreach ($this->shareIds as $shareId) {
            $share = Share::find($shareId);
            // The member's own shares, and the files guests dropped for them (no owner of their
            // own): the same shares "Delete expired shares" listed, never someone else's
            $forThisMember = $share && ($share->user_id === $this->userId || $share->invite?->user_id === $this->userId);
            if ($forThisMember) {
                $share->cleanFiles(true);
            }
        }
    }
}
