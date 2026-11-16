<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use App\Models\User;
use App\Models\UploadSession;
use App\Models\ChunkUpload;
use App\Models\ReverseShareInvite;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File as Filesystem;
use Illuminate\Support\Facades\Log;

class maintainDb implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new job instance.
     */
    public function __construct() {}

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        Log::info('Starting maintainDb job');

        //delete any chunks over 7 days old, these have probably been left by failed or aborted uploads
        Log::info('Deleting chunks over 7 days old: ' . ChunkUpload::where('created_at', '<', now()->subDays(7))->count() . ' chunks');
        ChunkUpload::where('created_at', '<', now()->subDays(7))->delete();

        //delete any upload sessions over 7 days old, these have probably been left by failed or aborted uploads
        Log::info('Deleting upload sessions over 7 days old: ' . UploadSession::where('created_at', '<', now()->subDays(7))->count() . ' sessions');
        UploadSession::where('created_at', '<', now()->subDays(7))->delete();

        // A guest account carries the link of a file request: it stays while the request is open
        // (a request may last a month), and goes a week after it was made once the request is
        // over. The request keeps its place in the list of its member, and lets go of the account
        // first: its foreign key refused the delete, which failed this job every day.
        $guests = User::where('is_guest', true)
            ->where('created_at', '<', now()->subDays(7))
            ->whereDoesntHave('invite', fn ($request) => $request->whereNull('completed_at')
                ->where(fn ($open) => $open->whereNull('expires_at')->orWhere('expires_at', '>', now())))
            ->pluck('id');
        Log::info('Deleting guest users over 7 days old: ' . $guests->count() . ' users');
        ReverseShareInvite::whereIn('guest_user_id', $guests)->update(['guest_user_id' => null]);
        User::whereIn('id', $guests)->delete();

        // What tusd kept for the uploads nobody finished (their sessions went above): partial
        // files, never encrypted, would otherwise stay on the disk for good
        $this->deleteAbandonedUploads();

        // The database cache only removes an expired entry when it is read again:
        // abandoned sign-ins, which anyone can start, would stay forever
        if (config('cache.default') === 'database') {
            DB::table(config('cache.stores.database.table'))->where('expiration', '<=', now()->getTimestamp())->delete();
        }
    }

    // The files of storage/app/uploads untouched for a week: an upload in progress writes to its
    // file with each chunk, one left there is not coming back
    private function deleteAbandonedUploads(): void
    {
        $folder = storage_path('app/uploads');
        if (!is_dir($folder)) {
            return;
        }

        $before = now()->subDays(7)->getTimestamp();
        $deleted = 0;
        foreach (new \DirectoryIterator($folder) as $entry) {
            // .gitignore and the like belong to the folder, not to an upload
            if ($entry->isDot() || str_starts_with($entry->getFilename(), '.') || $entry->getMTime() >= $before) {
                continue;
            }
            if ($entry->isFile()) {
                $deleted += @unlink($entry->getPathname()) ? 1 : 0;
            } elseif ($entry->isDir() && str_ends_with($entry->getFilename(), '_extracted')) {
                $deleted += Filesystem::deleteDirectory($entry->getPathname()) ? 1 : 0;
            }
        }
        Log::info('Deleting abandoned uploads over 7 days old: ' . $deleted . ' files');
    }
}
